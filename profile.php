<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

$username = trim($_GET['u'] ?? '');
if (!$username) { header('Location: index.php'); exit; }

$stmt = db()->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
$stmt->execute([$username]);
$profile = $stmt->fetch();
if (!$profile) { http_response_code(404); die('<p>User not found.</p>'); }

$me = current_user();

// Access check
if (!can_view_profile($profile, $me)) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <title>Private Profile — <?= h(SITE_NAME) ?></title>
    <link rel="stylesheet" href="assets/style.css">
    <?php include __DIR__ . '/includes/pwa.php'; ?>
</head>
    <body>
    <?php include __DIR__ . '/includes/topbar.php'; ?>
    <div style="max-width:480px;margin:60px auto;padding:0 20px;text-align:center;font-family:Tahoma,Arial,sans-serif;">
      <div class="card" style="padding:40px 24px;">
        <div style="font-size:52px;margin-bottom:16px;">🔒</div>
        <div style="font-size:18px;font-weight:700;color:#1c1e21;margin-bottom:8px;">This profile is private.</div>
        <div style="font-size:13px;color:#888;margin-bottom:20px;">
          <?php if (!$me): ?>
            <a href="index.php" style="color:#3b5998;font-weight:700;">Log in</a> or sign up to send a friend request.
          <?php else: ?>
            Only this user's friends can view their profile.
          <?php endif; ?>
        </div>
        <?php if ($me): ?>
          <?php $fs_locked = friendship_status($me['id'], $profile['id']); ?>
          <?php if ($fs_locked === 'none'): ?>
            <button class="btn-sm btn-primary" onclick="addFriendLocked(<?= (int)$profile['id'] ?>)" id="lock-add-btn" style="font-size:13px;padding:8px 20px;">+ Add Friend</button>
          <?php elseif ($fs_locked === 'pending_sent'): ?>
            <button class="btn-sm btn-cancel" disabled style="font-size:13px;padding:8px 20px;">⏳ Request Sent</button>
          <?php elseif ($fs_locked === 'friends'): ?>
            <div style="color:#3b5998;font-weight:700;">✓ You are friends — <a href="profile.php?u=<?= h($profile['username']) ?>" style="color:#3b5998;">refresh</a> to view.</div>
          <?php endif; ?>
        <?php endif; ?>
        <div style="margin-top:16px;"><a href="home.php" style="color:#888;font-size:12px;">← Back to Home</a></div>
      </div>
    </div>
    <script>
    function addFriendLocked(uid) {
      fetch('api/friend.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=request&user_id=' + uid
      }).then(r=>r.json()).then(d=>{
        if(d.ok) document.getElementById('lock-add-btn').outerHTML = '<button class="btn-sm btn-cancel" disabled style="font-size:13px;padding:8px 20px;">⏳ Request Sent</button>';
      });
    }
    </script>
    </body>
    </html>
    <?php
    exit;
}

$fs = $me ? friendship_status($me['id'], $profile['id']) : 'none';

// Intro fields
$intro = json_decode($profile['intro_fields'] ?? '[]', true) ?: [];

// Friends list (accepted, show up to 9 — hide private users unless viewer is friends with them)
$viewer_id = $me ? (int)$me['id'] : 0;
$stmt = db()->prepare("
    SELECT u.id, u.username, u.display_name, u.avatar, u.profile_public
    FROM friendships f
    JOIN users u ON u.id = CASE WHEN f.requester_id = ? THEN f.addressee_id ELSE f.requester_id END
    WHERE (f.requester_id = ? OR f.addressee_id = ?) AND f.status = 'accepted'
      AND (
        u.profile_public = 1
        OR u.id = ?
        OR EXISTS (
            SELECT 1 FROM friendships f2
            WHERE f2.status = 'accepted'
              AND ((f2.requester_id = u.id AND f2.addressee_id = ?)
                OR (f2.addressee_id = u.id AND f2.requester_id = ?))
        )
      )
    LIMIT 9
");
$stmt->execute([$profile['id'], $profile['id'], $profile['id'], $viewer_id, $viewer_id, $viewer_id]);
$friends = $stmt->fetchAll();

$stmt2 = db()->prepare("SELECT COUNT(*) FROM friendships WHERE (requester_id=? OR addressee_id=?) AND status='accepted'");
$stmt2->execute([$profile['id'], $profile['id']]);
$friend_count = (int)$stmt2->fetchColumn();

// Posts on this wall
$stmt = db()->prepare("
    SELECT p.*, u.username, u.display_name, u.avatar AS author_avatar,
           wo.username AS wall_username, wo.display_name AS wall_display_name,
           (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id) AS like_count,
           (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id AND l.user_id = ?) AS i_liked,
           (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id) AS comment_count
    FROM posts p
    JOIN users u ON u.id = p.author_id
    JOIN users wo ON wo.id = p.wall_owner_id
    WHERE p.wall_owner_id = ?
    ORDER BY p.created_at DESC
    LIMIT 50
");
$stmt->execute([$me ? $me['id'] : 0, $profile['id']]);
$posts = $stmt->fetchAll();

$can_post = $me && can_post_on_wall($profile, $me);
$is_own = $me && $me['id'] === $profile['id'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/x-icon" href="favicon.ico">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($profile['display_name']) ?> — <?= h(SITE_NAME) ?></title>
<link rel="stylesheet" href="assets/style.css">
<?php include __DIR__ . '/includes/pwa.php'; ?>
</head>
<body>

<?php include __DIR__ . '/includes/topbar.php'; ?>

<!-- COVER -->
<div id="cover-area">
  <?php if ($profile['cover']): ?>
    <img id="cover-img" src="<?= h(UPLOAD_URL . $profile['cover']) ?>" alt="Cover photo">
  <?php else: ?>
    <div id="cover-placeholder">📷</div>
  <?php endif; ?>
  <?php if ($is_own): ?>
    <button id="cover-edit-btn" onclick="document.getElementById('cover-file').click()">📷 Edit Cover Photo</button>
    <input type="file" id="cover-file" accept="image/*" style="display:none" onchange="uploadCover(this)">
  <?php endif; ?>
</div>

<!-- PROFILE HEADER -->
<div id="profile-header">
  <div id="profile-identity" style="max-width:940px;margin:0 auto;display:flex;align-items:flex-end;gap:16px;padding:0 16px;position:relative;top:-32px;margin-bottom:-16px;">
    <div id="avatar-wrap">
      <img id="avatar-img" src="<?= avatar_url($profile['avatar'], 100) ?>" alt="Profile" style="width:100px;height:100px;border-radius:50%;border:4px solid white;background:#c8c8c8;object-fit:cover;display:block;<?= $is_own ? 'cursor:pointer;' : '' ?>" <?= $is_own ? 'onclick="document.getElementById(\'avatar-file\').click()" title="Click to change photo"' : '' ?>>
      <?php if ($is_own): ?>
        <input type="file" id="avatar-file" accept="image/*" style="display:none" onchange="uploadAvatar(this)">
      <?php endif; ?>
    </div>
    <div style="padding-bottom:10px;flex:1;">
      <div style="font-size:26px;font-weight:700;color:#1c1e21;"><?= h($profile['display_name']) ?></div>
      <div style="font-size:12px;color:#888;">@<?= h($profile['username']) ?> · <?= $friend_count ?> <?= $friend_count == 1 ? 'Friend' : 'Friends' ?></div>
    </div>
    <!-- Friend action button -->
    <?php if ($me && !$is_own): ?>
      <div style="padding-bottom:10px;">
        <?php if ($fs === 'friends'): ?>
          <button class="btn-sm btn-cancel" onclick="removeFriend(<?= $profile['id'] ?>)">✓ Friends</button>
        <?php elseif ($fs === 'pending_sent'): ?>
          <button class="btn-sm btn-cancel" disabled>⏳ Request Sent</button>
        <?php elseif ($fs === 'pending_received'): ?>
          <button class="btn-sm btn-primary" onclick="respondFriend(<?= $profile['id'] ?>, 'accept')">Accept Request</button>
        <?php else: ?>
          <button class="btn-sm btn-primary" onclick="addFriend(<?= $profile['id'] ?>)" id="add-friend-btn">+ Add Friend</button>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- MAIN -->
<div id="main" style="max-width:940px;margin:0 auto;display:grid;grid-template-columns:300px 1fr;gap:16px;padding:16px;">

  <!-- LEFT -->
  <div>
    <!-- Intro -->
    <div class="card">
      <div class="card-title">Intro
        <?php if ($is_own): ?>
          <span class="edit-link" onclick="addIntroField()">+ Add field</span>
        <?php endif; ?>
      </div>
      <?php if ($is_own): ?>
        <div style="padding:8px 12px 0;">
          <textarea id="bio-field" placeholder="Write a short bio..." style="width:100%;border:1px solid #dddfe2;border-radius:4px;padding:7px 10px;font-size:13px;font-family:Tahoma,Arial,sans-serif;resize:vertical;min-height:56px;outline:none;background:#f8f9fa;"><?= h($profile['bio'] ?? '') ?></textarea>
        </div>
      <?php elseif ($profile['bio']): ?>
        <div style="padding:8px 12px 4px;font-size:13px;color:#555;line-height:1.5;"><?= h($profile['bio']) ?></div>
      <?php endif; ?>
      <ul id="intro-list" style="list-style:none;padding:4px 12px 8px;">
        <?php foreach ($intro as $item): ?>
        <li style="display:flex;align-items:center;gap:8px;padding:5px 0;color:#555;font-size:13px;border-bottom:1px solid #f5f5f5;">
          <span class="icon" style="font-size:16px;width:20px;text-align:center;flex-shrink:0;<?= $is_own ? 'cursor:pointer;' : '' ?>" <?= $is_own ? 'onclick="cycleIcon(this)" title="Click to change"' : '' ?>><?= h($item['icon'] ?? '📍') ?></span>
          <span class="field" <?= $is_own ? 'contenteditable="true" spellcheck="false" style="flex:1;outline:none;border-bottom:1px dashed transparent;" onfocus="this.style.borderBottomColor=\'#3b5998\'" onblur="this.style.borderBottomColor=\'transparent\'"' : 'style="flex:1;"' ?>><?= h($item['text'] ?? '') ?></span>
          <?php if ($is_own): ?>
            <button onclick="this.closest('li').remove()" style="background:none;border:none;color:#ccc;cursor:pointer;font-size:14px;padding:0 4px;line-height:1;" title="Remove">✕</button>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php if ($is_own): ?>
        <div style="padding:0 12px 10px;">
          <button id="save-intro-btn" onclick="saveIntro()" style="width:100%;background:#e4e6eb;border:none;border-radius:4px;padding:7px;cursor:pointer;font-size:13px;font-family:Tahoma,Arial,sans-serif;font-weight:700;color:#1c1e21;">💾 Save Intro</button>
        </div>
      <?php endif; ?>
    </div>

    <!-- Friends -->
    <div class="card">
      <div class="card-title">Friends <span style="font-weight:400;color:#888;font-size:12px;"><?= $friend_count ?></span></div>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;padding:8px 12px 12px;">
        <?php foreach ($friends as $f): ?>
        <div style="text-align:center;">
          <a href="profile.php?u=<?= h($f['username']) ?>">
            <img src="<?= avatar_url($f['avatar'], 80) ?>" style="width:80px;height:80px;border-radius:6px;object-fit:cover;border:1px solid #dddfe2;">
          </a>
          <div style="font-size:11px;margin-top:3px;word-break:break-word;">
            <a href="profile.php?u=<?= h($f['username']) ?>" style="color:#1c1e21;text-decoration:none;"><?= h($f['display_name']) ?></a>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- RIGHT -->
  <div>
    <?php if ($can_post): ?>
    <div class="card" id="composer" style="padding:12px;margin-bottom:12px;">
      <div style="display:flex;gap:10px;align-items:flex-start;">
        <img src="<?= avatar_url($me ? ($me['avatar'] ?? null) : null, 40) ?>" style="width:40px;height:40px;border-radius:50%;object-fit:cover;border:1px solid #dddfe2;flex-shrink:0;">
        <textarea id="post-content" placeholder="Write something<?= $is_own ? '...' : ' on ' . h($profile['display_name']) . '\'s wall...' ?>" style="flex:1;border:1px solid #dddfe2;border-radius:20px;padding:10px 14px;font-size:13px;font-family:Tahoma,Arial,sans-serif;resize:none;min-height:40px;outline:none;background:#f0f2f5;" onclick="expandComposer()" rows="1"></textarea>
      </div>
      <div id="composer-extras" style="display:none;margin-top:10px;">
        <div id="staged-preview" style="margin-bottom:8px;display:none;">
          <img id="staged-thumb" style="max-height:80px;border-radius:4px;border:1px solid #dddfe2;">
          <button onclick="clearStaged()" style="background:none;border:none;color:#e44;cursor:pointer;font-size:12px;margin-left:6px;">✕ Remove</button>
        </div>
        <div style="display:flex;align-items:center;gap:8px;border-top:1px solid #f0f2f5;padding-top:8px;">
          <button onclick="document.getElementById('post-img-input').click()" class="btn-sm btn-cancel">📷 Photo</button>
          <input type="file" id="post-img-input" accept="image/*" style="display:none" onchange="stageImage(this)">
          <button id="post-btn" class="btn-sm btn-primary" style="margin-left:auto;" onclick="submitPost()">Post</button>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <div id="feed">
      <?php if (empty($posts)): ?>
        <div class="card" style="padding:24px;text-align:center;color:#888;">No posts yet.</div>
      <?php else: ?>
        <?php foreach ($posts as $post): ?>
          <?php include __DIR__ . '/includes/post_card.php'; ?>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<script src="assets/goodbook.js"></script>
<script>
const ME_ID = <?= $me ? (int)$me['id'] : 'null' ?>;
const ME_USERNAME = <?= $me ? json_encode($me['username']) : 'null' ?>;
const GB_UPLOAD_URL = <?= json_encode(UPLOAD_URL) ?>;
const WALL_OWNER_ID = <?= (int)$profile['id'] ?>;
const CONTEXT = 'profile';

function addFriend(uid) {
  fetch('api/friend.php', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'action=request&user_id=' + uid
  }).then(r=>r.json()).then(d=>{
    if(d.ok) document.getElementById('add-friend-btn').outerHTML = '<button class="btn-sm btn-cancel" disabled>⏳ Request Sent</button>';
  });
}
function respondFriend(uid, action) {
  fetch('api/friend.php', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'action=' + action + '&user_id=' + uid
  }).then(r=>r.json()).then(d=>{ if(d.ok) location.reload(); });
}
function removeFriend(uid) {
  if (!confirm('Remove this friend?')) return;
  fetch('api/friend.php', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'action=remove&user_id=' + uid
  }).then(r=>r.json()).then(d=>{ if(d.ok) location.reload(); });
}

<?php if ($is_own): ?>
const iconCycle = ['📍','🎓','💼','❤️','🌍','📞','✉️','🏠','⭐','🗓️','📖','🎂'];
function cycleIcon(el) {
  const idx = iconCycle.indexOf(el.textContent);
  el.textContent = iconCycle[(idx + 1) % iconCycle.length];
}
function addIntroField() {
  const li = document.createElement('li');
  li.style.cssText = 'display:flex;align-items:center;gap:8px;padding:4px 0;font-size:13px;';
  li.innerHTML = `<span class="icon" style="cursor:pointer;" onclick="cycleIcon(this)" title="Click to change">📍</span><span class="field" contenteditable="true" spellcheck="false" style="flex:1;outline:none;" >Click to edit...</span><button onclick="this.closest('li').remove()" style="background:none;border:none;color:#aaa;cursor:pointer;font-size:14px;padding:0 4px;">✕</button>`;
  document.getElementById('intro-list').appendChild(li);
  li.querySelector('.field').focus();
}
function saveIntro() {
  const items = [];
  document.querySelectorAll('#intro-list li').forEach(li => {
    const icon = li.querySelector('.icon')?.textContent?.trim() || '📍';
    const text = li.querySelector('.field')?.textContent?.trim() || '';
    if (text) items.push({ icon, text });
  });
  const bio = document.getElementById('bio-field')?.value?.trim() || '';
  fetch('api/profile.php', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'action=save_intro&intro=' + encodeURIComponent(JSON.stringify(items)) + '&bio=' + encodeURIComponent(bio)
  }).then(r=>r.json()).then(d=>{
    if(d.ok) {
      const btn = document.getElementById('save-intro-btn');
      btn.textContent = '✓ Saved!';
      setTimeout(()=>{ btn.textContent='💾 Save Intro'; }, 2000);
    }
  });
}
function uploadAvatar(input) {
  const file = input.files[0]; if (!file) return;
  const fd = new FormData();
  fd.append('action', 'upload_avatar');
  fd.append('avatar', file);
  fetch('api/profile.php', { method:'POST', body:fd })
    .then(r=>r.json()).then(d=>{ if(d.ok && d.url) document.getElementById('avatar-img').src = d.url; });
}
function uploadCover(input) {
  const file = input.files[0]; if (!file) return;
  const fd = new FormData();
  fd.append('action', 'upload_cover');
  fd.append('cover', file);
  fetch('api/profile.php', { method:'POST', body:fd })
    .then(r=>r.json()).then(d=>{
      if(d.ok && d.url) {
        let img = document.getElementById('cover-img');
        if (!img) { img = document.createElement('img'); img.id='cover-img'; document.getElementById('cover-area').prepend(img); }
        img.src = d.url;
        img.style.display='block';
        const ph = document.getElementById('cover-placeholder');
        if(ph) ph.style.display='none';
      }
    });
}
<?php endif; ?>
</script>
</body>
</html>
