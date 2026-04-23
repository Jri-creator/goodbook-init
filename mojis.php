<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

require_login();
$me = current_user();

// Load full user for is_admin check
$stmt = db()->prepare("SELECT is_admin FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$me['id']]);
$me_full = $stmt->fetch();
$is_admin = (bool)($me_full['is_admin'] ?? false);

// Fetch all mojis for the browse panel (active + inactive for admin)
if ($is_admin) {
    $stmt = db()->prepare("
        SELECT m.*, u.display_name AS uploader_name, u.username AS uploader_username
        FROM mojis m JOIN users u ON u.id = m.uploaded_by
        ORDER BY m.active DESC, m.name ASC
    ");
    $stmt->execute();
} else {
    $stmt = db()->prepare("
        SELECT m.*, u.display_name AS uploader_name, u.username AS uploader_username
        FROM mojis m JOIN users u ON u.id = m.uploaded_by
        WHERE m.active = 1
        ORDER BY m.name ASC
    ");
    $stmt->execute();
}
$all_mojis = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/x-icon" href="favicon.ico">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mojis — <?= h(SITE_NAME) ?></title>
<link rel="stylesheet" href="assets/style.css">
<?php include __DIR__ . '/includes/pwa.php'; ?>
<style>
.moji-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(90px,1fr)); gap:10px; padding:12px; }
.moji-tile { text-align:center; position:relative; }
.moji-tile img { width:48px; height:48px; border-radius:6px; display:block; margin:0 auto 4px; border:1px solid #dddfe2; image-rendering:pixelated; }
.moji-tile .moji-name { font-size:11px; color:#555; word-break:break-all; }
.moji-tile.inactive img { opacity:0.35; }
.moji-tile.inactive .moji-name { color:#bbb; text-decoration:line-through; }
.moji-tile .admin-btns { display:none; position:absolute; top:2px; right:2px; }
.moji-tile:hover .admin-btns { display:flex; gap:2px; }
.admin-btn { background:rgba(0,0,0,0.55); color:white; border:none; border-radius:3px; font-size:10px; cursor:pointer; padding:2px 5px; }

#crop-area { position:relative; width:256px; height:256px; overflow:hidden; background:#111; margin:0 auto 12px; border-radius:6px; cursor:crosshair; display:none; }
#crop-area img { position:absolute; top:0; left:0; }
#crop-handle { position:absolute; border:2px solid white; box-shadow:0 0 0 9999px rgba(0,0,0,0.5); cursor:move; box-sizing:border-box; }
.upload-box { border:2px dashed #dddfe2; border-radius:8px; padding:30px; text-align:center; cursor:pointer; color:#888; transition:border-color 0.2s; }
.upload-box:hover, .upload-box.dragover { border-color:#3b5998; color:#3b5998; }
.policy-box { background:#f8f9fa; border:1px solid #dddfe2; border-radius:4px; padding:10px 12px; font-size:12px; color:#555; line-height:1.5; margin-bottom:10px; }
</style>
</head>
<body>
<?php include __DIR__ . '/includes/topbar.php'; ?>

<div style="max-width:860px;margin:16px auto;padding:0 16px;display:grid;grid-template-columns:320px 1fr;gap:16px;">

  <!-- LEFT: Upload -->
  <div>
    <div class="card">
      <div class="card-title">✨ Upload a Moji</div>
      <div style="padding:12px;">

        <!-- Drop zone -->
        <div class="upload-box" id="drop-zone" onclick="document.getElementById('moji-file').click()">
          <div style="font-size:32px;margin-bottom:6px;">🖼️</div>
          <div style="font-size:13px;font-weight:700;">Click or drag an image here</div>
          <div style="font-size:11px;margin-top:4px;">PNG, WebP, or GIF · Will be cropped to a square</div>
        </div>
        <input type="file" id="moji-file" accept="image/png,image/gif,image/webp" style="display:none">

        <!-- Crop area -->
        <div id="crop-area" style="margin-top:12px;">
          <img id="crop-img" src="" alt="" draggable="false">
          <div id="crop-handle"></div>
        </div>
        <div id="crop-controls" style="display:none;margin-bottom:12px;">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
            <label style="font-size:12px;font-weight:700;color:#555;white-space:nowrap;">Zoom</label>
            <input type="range" id="zoom-slider" min="1" max="4" step="0.05" value="1" style="flex:1;">
          </div>
          <div style="font-size:11px;color:#aaa;text-align:center;">Drag to reposition · Zoom to fit</div>
        </div>

        <!-- Preview -->
        <div id="preview-area" style="display:none;text-align:center;margin-bottom:12px;">
          <div style="font-size:11px;color:#888;margin-bottom:6px;">Preview at display size (32px)</div>
          <canvas id="preview-canvas" width="32" height="32" style="border:1px solid #dddfe2;border-radius:4px;image-rendering:pixelated;width:64px;height:64px;"></canvas>
        </div>

        <!-- Name -->
        <div style="margin-bottom:10px;">
          <label style="display:block;font-size:12px;font-weight:700;color:#555;margin-bottom:4px;">Moji name</label>
          <div style="display:flex;align-items:center;gap:4px;">
            <span style="color:#888;font-size:14px;">:</span>
            <input type="text" id="moji-name" placeholder="moji_name" maxlength="40"
              style="flex:1;border:1px solid #dddfe2;border-radius:4px;padding:7px 10px;font-size:13px;font-family:Tahoma,Arial,sans-serif;outline:none;"
              oninput="this.value=this.value.toLowerCase().replace(/[^a-z0-9_]/g,'')">
            <span style="color:#888;font-size:14px;">:</span>
          </div>
          <div style="font-size:11px;color:#aaa;margin-top:3px;">Lowercase letters, numbers, underscores only.</div>
        </div>

        <!-- Policy -->
        <div class="policy-box">
          <strong>Content Policy</strong><br>
          By uploading, you confirm that this image is not illegal, does not violate anyone's rights, is not sexually explicit, does not depict real-world violence, and is appropriate for a general audience. The site owner may remove mojis at any time.
        </div>
        <label style="display:flex;align-items:center;gap:8px;margin-bottom:12px;cursor:pointer;font-size:13px;">
          <input type="checkbox" id="policy-agree">
          I agree to the content policy
        </label>

        <button id="upload-btn" onclick="uploadMoji()" class="auth-btn" style="width:100%;">Upload Moji</button>
        <div id="upload-status" style="font-size:12px;text-align:center;margin-top:8px;min-height:16px;"></div>
      </div>
    </div>
  </div>

  <!-- RIGHT: Browse -->
  <div>
    <div class="card">
      <div class="card-title">
        All Mojis
        <span style="font-weight:400;color:#888;font-size:12px;"><?= count(array_filter($all_mojis, fn($m) => $m['active'])) ?> active</span>
      </div>
      <?php if (empty($all_mojis)): ?>
        <div style="padding:24px;text-align:center;color:#aaa;font-size:13px;">No mojis yet — be the first to upload one!</div>
      <?php else: ?>
      <div class="moji-grid" id="moji-grid">
        <?php foreach ($all_mojis as $moji): ?>
        <div class="moji-tile <?= $moji['active'] ? '' : 'inactive' ?>" id="moji-tile-<?= (int)$moji['id'] ?>" title=":<?= h($moji['name']) ?>: — uploaded by <?= h($moji['uploader_name']) ?>">
          <img src="<?= h(UPLOAD_URL . $moji['image_path']) ?>" alt=":<?= h($moji['name']) ?>:">
          <div class="moji-name">:<?= h($moji['name']) ?>:</div>
          <?php if ($is_admin): ?>
          <div class="admin-btns">
            <?php if ($moji['active']): ?>
              <button class="admin-btn" onclick="adminDelete(<?= (int)$moji['id'] ?>)" title="Deactivate">🗑</button>
            <?php else: ?>
              <button class="admin-btn" onclick="adminRestore(<?= (int)$moji['id'] ?>)" title="Restore" style="background:rgba(0,120,0,0.7);">↩</button>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<script>
// ── CROP ENGINE ───────────────────────────────────────────────
let cropState = null; // { naturalW, naturalH, scale, x, y, zoom, dragging, lastMX, lastMY }
const CROP_SIZE = 256; // display canvas size
const OUT_SIZE  = 128; // output resolution

const dropZone    = document.getElementById('drop-zone');
const fileInput   = document.getElementById('moji-file');
const cropArea    = document.getElementById('crop-area');
const cropImg     = document.getElementById('crop-img');
const cropHandle  = document.getElementById('crop-handle');
const zoomSlider  = document.getElementById('zoom-slider');
const cropControls= document.getElementById('crop-controls');
const previewArea = document.getElementById('preview-area');
const previewCanvas= document.getElementById('preview-canvas');

// Drag-over styling
dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('dragover'); });
dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
dropZone.addEventListener('drop', e => {
  e.preventDefault(); dropZone.classList.remove('dragover');
  const file = e.dataTransfer.files[0];
  if (file) loadFile(file);
});
fileInput.addEventListener('change', () => { if (fileInput.files[0]) loadFile(fileInput.files[0]); });

function loadFile(file) {
  const url = URL.createObjectURL(file);
  const img = new Image();
  img.onload = () => {
    cropImg.src = url;
    const minDim = Math.min(img.naturalWidth, img.naturalHeight);
    const scale  = CROP_SIZE / minDim; // fit smallest side to crop area
    cropState = {
      naturalW: img.naturalWidth,
      naturalH: img.naturalHeight,
      scale, zoom: 1,
      x: (CROP_SIZE - img.naturalWidth  * scale) / 2,
      y: (CROP_SIZE - img.naturalHeight * scale) / 2,
      dragging: false, lastMX: 0, lastMY: 0
    };
    zoomSlider.value = 1;
    cropArea.style.display = 'block';
    cropControls.style.display = 'block';
    previewArea.style.display = 'block';
    applyCrop();
    updatePreview();
  };
  img.src = url;
}

function applyCrop() {
  if (!cropState) return;
  const { naturalW, naturalH, scale, zoom, x, y } = cropState;
  const w = naturalW * scale * zoom;
  const h = naturalH * scale * zoom;
  cropImg.style.width  = w + 'px';
  cropImg.style.height = h + 'px';
  cropImg.style.left   = x + 'px';
  cropImg.style.top    = y + 'px';
  // Handle shows the square crop region
  cropHandle.style.left   = '0px';
  cropHandle.style.top    = '0px';
  cropHandle.style.width  = CROP_SIZE + 'px';
  cropHandle.style.height = CROP_SIZE + 'px';
}

function clampPosition() {
  if (!cropState) return;
  const { naturalW, naturalH, scale, zoom } = cropState;
  const w = naturalW * scale * zoom;
  const h = naturalH * scale * zoom;
  cropState.x = Math.min(0, Math.max(CROP_SIZE - w, cropState.x));
  cropState.y = Math.min(0, Math.max(CROP_SIZE - h, cropState.y));
}

zoomSlider.addEventListener('input', () => {
  if (!cropState) return;
  const oldZoom = cropState.zoom;
  cropState.zoom = parseFloat(zoomSlider.value);
  // Keep center stable when zooming
  const cx = CROP_SIZE / 2, cy = CROP_SIZE / 2;
  cropState.x = cx - (cx - cropState.x) * (cropState.zoom / oldZoom);
  cropState.y = cy - (cy - cropState.y) * (cropState.zoom / oldZoom);
  clampPosition();
  applyCrop();
  updatePreview();
});

cropArea.addEventListener('mousedown', e => {
  if (!cropState) return;
  cropState.dragging = true;
  cropState.lastMX = e.clientX;
  cropState.lastMY = e.clientY;
  e.preventDefault();
});
window.addEventListener('mousemove', e => {
  if (!cropState?.dragging) return;
  cropState.x += e.clientX - cropState.lastMX;
  cropState.y += e.clientY - cropState.lastMY;
  cropState.lastMX = e.clientX;
  cropState.lastMY = e.clientY;
  clampPosition();
  applyCrop();
  updatePreview();
});
window.addEventListener('mouseup', () => { if (cropState) cropState.dragging = false; });

// Touch support
cropArea.addEventListener('touchstart', e => {
  if (!cropState || e.touches.length !== 1) return;
  cropState.dragging = true;
  cropState.lastMX = e.touches[0].clientX;
  cropState.lastMY = e.touches[0].clientY;
  e.preventDefault();
}, { passive: false });
window.addEventListener('touchmove', e => {
  if (!cropState?.dragging || e.touches.length !== 1) return;
  cropState.x += e.touches[0].clientX - cropState.lastMX;
  cropState.y += e.touches[0].clientY - cropState.lastMY;
  cropState.lastMX = e.touches[0].clientX;
  cropState.lastMY = e.touches[0].clientY;
  clampPosition();
  applyCrop();
  updatePreview();
}, { passive: false });
window.addEventListener('touchend', () => { if (cropState) cropState.dragging = false; });

function updatePreview() {
  if (!cropState) return;
  const ctx = previewCanvas.getContext('2d');
  const { naturalW, naturalH, scale, zoom, x, y } = cropState;
  const w = naturalW * scale * zoom;
  const h = naturalH * scale * zoom;
  // Scale factor from CROP_SIZE to OUT_SIZE
  const factor = OUT_SIZE / CROP_SIZE;
  ctx.clearRect(0, 0, OUT_SIZE, OUT_SIZE);
  const img = cropImg;
  ctx.drawImage(img, 0, 0, img.naturalWidth, img.naturalHeight,
    x * factor, y * factor, w * factor, h * factor);
}

function getCroppedBlob() {
  return new Promise(resolve => {
    const canvas = document.createElement('canvas');
    canvas.width = OUT_SIZE;
    canvas.height = OUT_SIZE;
    const ctx = canvas.getContext('2d');
    const { naturalW, naturalH, scale, zoom, x, y } = cropState;
    const w = naturalW * scale * zoom;
    const h = naturalH * scale * zoom;
    const factor = OUT_SIZE / CROP_SIZE;
    ctx.drawImage(cropImg, 0, 0, cropImg.naturalWidth, cropImg.naturalHeight,
      x * factor, y * factor, w * factor, h * factor);
    canvas.toBlob(resolve, 'image/png');
  });
}

// ── UPLOAD ────────────────────────────────────────────────────
async function uploadMoji() {
  const name   = document.getElementById('moji-name').value.trim();
  const agreed = document.getElementById('policy-agree').checked;
  const status = document.getElementById('upload-status');

  if (!cropState) { status.textContent = '⚠️ Please select an image first.'; return; }
  if (!name)      { status.textContent = '⚠️ Please enter a moji name.'; return; }
  if (!agreed)    { status.textContent = '⚠️ Please agree to the content policy.'; return; }

  const btn = document.getElementById('upload-btn');
  btn.disabled = true; btn.textContent = 'Uploading...';
  status.textContent = '';

  const blob = await getCroppedBlob();
  const fd = new FormData();
  fd.append('action',  'upload');
  fd.append('name',    name);
  fd.append('agreed',  '1');
  fd.append('image',   blob, name + '.png');

  fetch('api/moji.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.ok) {
        status.style.color = '#060';
        status.textContent = '✅ :' + data.moji.name + ': uploaded!';
        // Add to grid
        addMojiToGrid(data.moji);
        // Reset form
        cropArea.style.display = 'none';
        cropControls.style.display = 'none';
        previewArea.style.display = 'none';
        document.getElementById('moji-name').value = '';
        document.getElementById('policy-agree').checked = false;
        fileInput.value = '';
        cropState = null;
      } else {
        status.style.color = '#c00';
        status.textContent = '⚠️ ' + (data.error || 'Upload failed.');
      }
    })
    .catch(() => { status.style.color='#c00'; status.textContent = '⚠️ Network error.'; })
    .finally(() => { btn.disabled = false; btn.textContent = 'Upload Moji'; });
}

function addMojiToGrid(moji) {
  const grid = document.getElementById('moji-grid');
  if (!grid) return;
  const div = document.createElement('div');
  div.className = 'moji-tile';
  div.id = 'moji-tile-' + moji.id;
  div.title = ':' + moji.name + ':';
  div.innerHTML = `<img src="${moji.url}" alt=":${moji.name}:"><div class="moji-name">:${moji.name}:</div>`;
  grid.prepend(div);
}

// ── ADMIN ─────────────────────────────────────────────────────
function adminDelete(id) {
  if (!confirm('Deactivate this moji? The image stays, old posts are unaffected.')) return;
  fetch('api/moji.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'action=delete&moji_id=' + id
  }).then(r => r.json()).then(d => {
    if (d.ok) {
      const tile = document.getElementById('moji-tile-' + id);
      if (tile) tile.classList.add('inactive');
    }
  });
}
function adminRestore(id) {
  fetch('api/moji.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'action=restore&moji_id=' + id
  }).then(r => r.json()).then(d => {
    if (d.ok) {
      const tile = document.getElementById('moji-tile-' + id);
      if (tile) tile.classList.remove('inactive');
    } else { alert(d.error); }
  });
}
</script>
</body>
</html>
