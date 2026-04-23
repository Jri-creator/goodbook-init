// Project Goodbook — goodbook.js

// ── COMPOSER ─────────────────────────────────────────────────
let stagedImageFile = null;

function expandComposer() {
  const extras = document.getElementById('composer-extras');
  const textarea = document.getElementById('post-content');
  if (extras) extras.style.display = 'block';
  if (textarea) {
    textarea.style.borderRadius = '6px';
    textarea.rows = 3;
  }
}

function stageImage(input) {
  const file = input.files[0];
  if (!file) return;
  stagedImageFile = file;
  const reader = new FileReader();
  reader.onload = e => {
    const preview = document.getElementById('staged-preview');
    const thumb   = document.getElementById('staged-thumb');
    if (preview && thumb) {
      thumb.src = e.target.result;
      preview.style.display = 'block';
    }
  };
  reader.readAsDataURL(file);
}

function clearStaged() {
  stagedImageFile = null;
  const preview = document.getElementById('staged-preview');
  const thumb   = document.getElementById('staged-thumb');
  const input   = document.getElementById('post-img-input');
  if (preview) preview.style.display = 'none';
  if (thumb)   thumb.src = '';
  if (input)   input.value = '';
}

function submitPost() {
  const textarea = document.getElementById('post-content');
  const content  = textarea?.value?.trim();
  if (!content) return;

  const btn = document.getElementById('post-btn');
  if (btn) { btn.disabled = true; btn.textContent = 'Posting...'; }

  const wall_owner_id = typeof WALL_OWNER_ID !== 'undefined' ? WALL_OWNER_ID : ME_ID;

  const fd = new FormData();
  fd.append('action', 'create');
  fd.append('content', content);
  fd.append('wall_owner_id', wall_owner_id);
  if (stagedImageFile) fd.append('image', stagedImageFile);

  fetch('api/post.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.ok && data.post) {
        prependPost(data.post);
        textarea.value = '';
        clearStaged();
        const extras = document.getElementById('composer-extras');
        if (extras) extras.style.display = 'none';
        textarea.rows = 1;
        textarea.style.borderRadius = '20px';
      } else {
        alert(data.error || 'Could not post.');
      }
    })
    .catch(() => alert('Network error.'))
    .finally(() => {
      if (btn) { btn.disabled = false; btn.textContent = 'Post'; }
    });
}

function prependPost(post) {
  const feed = document.getElementById('feed');
  if (!feed) return;
  // Remove empty-state or end-of-feed placeholder
  feed.querySelector('.feed-empty')?.remove();
  feed.querySelector('.feed-end')?.remove();

  const div = document.createElement('div');
  div.innerHTML = buildPostHTML(post);
  feed.prepend(div.firstElementChild);
}

// ── BUILD POST HTML (for AJAX-created posts) ──────────────────
function buildPostHTML(p) {
  const isOnOthersWall = String(p.author_id) !== String(p.wall_owner_id);
  const isMine = ME_ID && (parseInt(p.author_id) === ME_ID || parseInt(p.wall_owner_id) === ME_ID);
  const pid = parseInt(p.id);

  // Use server-rendered Markdown if available (from api/post.php create response).
  // This ensures the AJAX post matches exactly what PHP+Parsedown produces on refresh.
  const bodyHtml = p.rendered_content
    ? p.rendered_content
    : autoLink(escHtml(p.content).replace(/\n/g, '<br>'));

  // Server sends image_url as a fully resolved URL
  const imgHtml = p.image_url
    ? `<img src="${escAttr(p.image_url)}" alt="Post image" style="max-width:100%;border-radius:6px;margin-top:8px;display:block;">`
    : '';

  const wallLabel = isOnOthersWall
    ? ` <span style="color:#888;font-weight:400;"> → </span><a href="profile.php?u=${encodeURIComponent(p.wall_username)}" style="color:#3b5998;text-decoration:none;font-size:12px;">${escHtml(p.wall_display_name)}</a>`
    : '';
  const deleteBtn = isMine
    ? `<button class="post-action delete" onclick="deletePost(${pid})" title="Delete" style="flex:0;padding:4px 8px;">✕</button>`
    : '';

  const avatarSrc = p.author_avatar_url || defaultAvatar();
  // created_at from server is UTC MySQL datetime; JS timeAgo handles it
  const tsAttr = p.created_at ? escAttr(p.created_at) : '';

  return `
    <div class="post-card" id="post-${pid}">
      <div class="post-header">
        <a href="profile.php?u=${encodeURIComponent(p.username)}">
          <img src="${escAttr(avatarSrc)}" class="post-avatar" alt="">
        </a>
        <div class="post-meta">
          <div class="post-author-name">
            <a href="profile.php?u=${encodeURIComponent(p.username)}" style="color:#3b5998;text-decoration:none;">${escHtml(p.display_name)}</a>${wallLabel}
          </div>
          <div class="post-date" data-ts="${tsAttr}">just now</div>
        </div>
        ${deleteBtn}
      </div>
      <div class="post-body">${bodyHtml}${imgHtml}</div>
      <div class="like-count" id="like-label-${pid}"></div>
      <div class="post-footer">
        ${ME_ID ? `<button class="post-action" id="like-btn-${pid}" onclick="toggleLike(${pid})">👍 Like</button>` : ''}
        <button class="post-action" onclick="toggleComments(${pid})">💬 Comment</button>
      </div>
      <div class="comments-section" id="comments-${pid}">
        <div class="comments-list" id="clist-${pid}"></div>
        ${ME_ID ? `<div class="comment-form"><div style="display:flex;gap:8px;align-items:center;"><input type="text" class="comment-input" id="cinput-${pid}" placeholder="Write a comment..." onkeydown="if(event.key==='Enter'){submitComment(${pid});}"><button onclick="submitComment(${pid})" style="background:#3b5998;color:white;border:none;border-radius:12px;padding:5px 12px;font-size:12px;font-family:Tahoma,Arial,sans-serif;cursor:pointer;white-space:nowrap;">Post</button></div></div>` : ''}
      </div>
    </div>`;
}

// ── TIMEZONE-AWARE TIMESTAMPS ─────────────────────────────────
// PHP stores datetimes as UTC. We compute time-ago client-side so it
// always matches the viewer's local clock, regardless of server timezone.
function timeAgo(mysqlUtcDatetime) {
  if (!mysqlUtcDatetime) return 'just now';
  // "YYYY-MM-DD HH:MM:SS" → treat as UTC by appending 'Z'
  const then = new Date(mysqlUtcDatetime.replace(' ', 'T') + 'Z');
  const diff = Math.floor((Date.now() - then.getTime()) / 1000);
  if (diff < 10)       return 'just now';
  if (diff < 60)       return diff + 's ago';
  if (diff < 3600)     return Math.floor(diff / 60) + 'm ago';
  if (diff < 86400)    return Math.floor(diff / 3600) + 'h ago';
  if (diff < 604800)   return Math.floor(diff / 86400) + 'd ago';
  return then.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}

function refreshTimestamps() {
  document.querySelectorAll('.post-date[data-ts]').forEach(el => {
    const ts = el.getAttribute('data-ts');
    if (ts) el.textContent = timeAgo(ts);
  });
}

document.addEventListener('DOMContentLoaded', function () {
  refreshTimestamps();
  setInterval(refreshTimestamps, 60000); // live-update every minute
});

// ── LIKES ─────────────────────────────────────────────────────
function toggleLike(postId) {
  fetch('api/post.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'action=like&post_id=' + postId
  })
  .then(r => r.json())
  .then(data => {
    if (!data.ok) return;
    const btn   = document.getElementById('like-btn-' + postId);
    const label = document.getElementById('like-label-' + postId);
    if (btn) {
      btn.classList.toggle('liked', data.liked);
      btn.textContent = data.liked ? '👍 Liked' : '👍 Like';
    }
    if (label) {
      label.textContent = data.count > 0
        ? '👍 ' + data.count + (data.count === 1 ? ' Like' : ' Likes')
        : '';
    }
  });
}

// ── COMMENTS ──────────────────────────────────────────────────
const _commentsLoaded = {};

function toggleComments(postId) {
  const section = document.getElementById('comments-' + postId);
  if (!section) return;
  section.classList.toggle('visible');
  if (section.classList.contains('visible') && !_commentsLoaded[postId]) {
    loadComments(postId);
  }
  if (section.classList.contains('visible')) {
    setTimeout(() => document.getElementById('cinput-' + postId)?.focus(), 50);
  }
}

function loadComments(postId) {
  fetch('api/post.php?action=get_comments&post_id=' + postId)
    .then(r => r.json())
    .then(data => {
      if (!data.ok) return;
      _commentsLoaded[postId] = true;
      const list = document.getElementById('clist-' + postId);
      if (!list) return;
      if (!data.comments.length) {
        list.innerHTML = '<div style="font-size:12px;color:#aaa;margin-bottom:6px;">No comments yet.</div>';
      } else {
        list.innerHTML = data.comments.map(buildCommentHTML).join('');
      }
    });
}

function submitComment(postId) {
  const input = document.getElementById('cinput-' + postId);
  if (!input) return;
  const content = input.value.trim();
  if (!content) return;
  input.value = '';

  fetch('api/post.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'action=add_comment&post_id=' + postId + '&content=' + encodeURIComponent(content)
  })
  .then(r => r.json())
  .then(data => {
    if (!data.ok) return;
    _commentsLoaded[postId] = true;
    const list = document.getElementById('clist-' + postId);
    if (!list) return;
    list.querySelector('[style*="No comments"]')?.remove();
    list.insertAdjacentHTML('beforeend', buildCommentHTML(data.comment));
    // Update comment count label in footer
    const footer = document.querySelector(`#post-${postId} .post-footer`);
    if (footer) {
      footer.querySelectorAll('.post-action').forEach(btn => {
        if (btn.textContent.includes('Comment')) {
          const cur = parseInt(btn.textContent.match(/\d+/) || [0]) || 0;
          btn.textContent = '💬 Comment (' + (cur + 1) + ')';
        }
      });
    }
  });
}

function buildCommentHTML(c) {
  return `
    <div class="comment">
      <a href="profile.php?u=${encodeURIComponent(c.username)}">
        <img src="${escAttr(c.avatar_url)}" style="width:28px;height:28px;border-radius:50%;object-fit:cover;flex-shrink:0;">
      </a>
      <div class="comment-bubble">
        <strong><a href="profile.php?u=${encodeURIComponent(c.username)}" style="color:#3b5998;text-decoration:none;">${escHtml(c.display_name)}</a></strong>
        ${escHtml(c.content)}
        <span class="comment-time">${escHtml(c.time_ago)}</span>
      </div>
    </div>`;
}

// ── DELETE POST ───────────────────────────────────────────────
function deletePost(postId) {
  if (!confirm('Delete this post?')) return;
  fetch('api/post.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'action=delete&post_id=' + postId
  })
  .then(r => r.json())
  .then(data => {
    if (data.ok) {
      document.getElementById('post-' + postId)?.remove();
    }
  });
}

// ── UTILITIES ─────────────────────────────────────────────────
function escHtml(str) {
  if (str === null || str === undefined) return '';
  return String(str)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
function escAttr(str) { return escHtml(str); }

function autoLink(str) {
  return str.replace(
    /(https?:\/\/[^\s<>"']+)/g,
    '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>'
  );
}

function defaultAvatar() {
  return "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 40 40'%3E%3Crect width='40' height='40' fill='%23c8c8c8'/%3E%3Ccircle cx='20' cy='15' r='9' fill='%23aaa'/%3E%3Cellipse cx='20' cy='33' rx='12' ry='8' fill='%23aaa'/%3E%3C/svg%3E";
}

// Close composer on outside click
document.addEventListener('click', function(e) {
  const composer = document.getElementById('composer');
  const extras   = document.getElementById('composer-extras');
  if (composer && extras && !composer.contains(e.target)) {
    const content = document.getElementById('post-content');
    if (content && !content.value.trim()) {
      extras.style.display = 'none';
      content.rows = 1;
      content.style.borderRadius = '20px';
    }
  }
  // Close moji picker on outside click
  const picker = document.getElementById('gb-moji-picker');
  const btn    = document.getElementById('gb-moji-btn');
  if (picker && btn && !picker.contains(e.target) && !btn.contains(e.target)) {
    picker.style.display = 'none';
  }
});

// ── MOJI SYSTEM ───────────────────────────────────────────────
let _mojiCache = null;

function loadMojis(cb) {
  if (_mojiCache) { cb(_mojiCache); return; }
  fetch('api/moji.php?action=list')
    .then(r => r.json())
    .then(d => { _mojiCache = d.mojis || []; cb(_mojiCache); })
    .catch(() => { _mojiCache = []; cb([]); });
}

// Insert text at cursor in a textarea
function insertAtCursor(ta, text) {
  const start = ta.selectionStart;
  const end   = ta.selectionEnd;
  ta.value = ta.value.slice(0, start) + text + ta.value.slice(end);
  ta.selectionStart = ta.selectionEnd = start + text.length;
  ta.focus();
}

// ── PICKER BUTTON ─────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
  // Inject moji button into composer extras when they appear
  const observer = new MutationObserver(() => {
    const extras = document.getElementById('composer-extras');
    if (extras && !document.getElementById('gb-moji-btn')) {
      injectMojiUI(extras);
    }
  });
  observer.observe(document.body, { childList: true, subtree: true });
  // Also try immediately (profile page)
  const extras = document.getElementById('composer-extras');
  if (extras) injectMojiUI(extras);
});

function injectMojiUI(extras) {
  // Add button to the action bar inside composer-extras
  const bar = extras.querySelector('[style*="border-top"]');
  if (!bar || document.getElementById('gb-moji-btn')) return;

  const btn = document.createElement('button');
  btn.id = 'gb-moji-btn';
  btn.className = 'btn-sm btn-cancel';
  btn.type = 'button';
  btn.textContent = '😀 Moji';
  btn.onclick = toggleMojiPicker;
  bar.insertBefore(btn, bar.querySelector('button'));

  // Build picker popup
  const picker = document.createElement('div');
  picker.id = 'gb-moji-picker';
  picker.style.cssText = 'display:none;position:absolute;z-index:300;background:white;border:1px solid #dddfe2;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,0.15);width:280px;max-height:240px;overflow-y:auto;';
  picker.innerHTML = '<div style="padding:8px;border-bottom:1px solid #f0f2f5;"><input id="gb-moji-search" placeholder="Search mojis..." style="width:100%;border:1px solid #dddfe2;border-radius:4px;padding:5px 8px;font-size:12px;font-family:Tahoma,Arial,sans-serif;outline:none;"></div><div id="gb-moji-list" style="display:flex;flex-wrap:wrap;padding:6px;gap:4px;"></div>';

  const composerWrap = document.getElementById('composer');
  if (composerWrap) {
    composerWrap.style.position = 'relative';
    composerWrap.appendChild(picker);
  }

  picker.querySelector('#gb-moji-search').addEventListener('input', function() {
    renderPickerMojis(this.value.trim().toLowerCase());
  });
}

function toggleMojiPicker() {
  const picker = document.getElementById('gb-moji-picker');
  if (!picker) return;
  const isOpen = picker.style.display !== 'none';
  picker.style.display = isOpen ? 'none' : 'block';
  if (!isOpen) {
    loadMojis(mojis => renderPickerMojis('', mojis));
    setTimeout(() => picker.querySelector('#gb-moji-search')?.focus(), 50);
  }
}

function renderPickerMojis(query, mojis) {
  if (mojis === undefined) {
    loadMojis(m => renderPickerMojis(query, m));
    return;
  }
  const list = document.getElementById('gb-moji-list');
  if (!list) return;
  const filtered = query ? mojis.filter(m => m.name.includes(query)) : mojis;
  if (!filtered.length) {
    list.innerHTML = '<div style="padding:8px;font-size:12px;color:#aaa;width:100%;">No mojis found.</div>';
    return;
  }
  list.innerHTML = filtered.map(m => `
    <button onclick="pickMoji(':${escHtml(m.name)}:')" title=":${escHtml(m.name)}:"
      style="background:none;border:1px solid transparent;border-radius:4px;cursor:pointer;padding:3px;display:flex;align-items:center;justify-content:center;"
      onmouseover="this.style.background='#f0f2f5'" onmouseout="this.style.background='none'">
      <img src="${escAttr(m.url)}" alt=":${escHtml(m.name)}:" width="28" height="28" style="display:block;image-rendering:pixelated;">
    </button>`).join('');
}

function pickMoji(token) {
  const ta = document.getElementById('post-content');
  if (!ta) return;
  insertAtCursor(ta, token);
  document.getElementById('gb-moji-picker').style.display = 'none';
}

// ── COLON AUTOCOMPLETE ────────────────────────────────────────
(function() {
  document.addEventListener('input', function(e) {
    if (e.target.id !== 'post-content') return;
    handleMojiAutocomplete(e.target);
  });
  document.addEventListener('keydown', function(e) {
    if (e.target.id !== 'post-content') return;
    const ac = document.getElementById('gb-moji-ac');
    if (!ac || ac.style.display === 'none') return;
    const items = ac.querySelectorAll('.ac-item');
    let active = ac.querySelector('.ac-item.ac-active');
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      const next = active ? (active.nextElementSibling || items[0]) : items[0];
      if (active) active.classList.remove('ac-active');
      next?.classList.add('ac-active');
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      const prev = active ? (active.previousElementSibling || items[items.length-1]) : items[items.length-1];
      if (active) active.classList.remove('ac-active');
      prev?.classList.add('ac-active');
    } else if (e.key === 'Enter' || e.key === 'Tab') {
      active = ac.querySelector('.ac-item.ac-active') || items[0];
      if (active) { e.preventDefault(); active.click(); }
    } else if (e.key === 'Escape') {
      ac.style.display = 'none';
    }
  });
})();

function handleMojiAutocomplete(ta) {
  const val = ta.value;
  const pos = ta.selectionStart;
  // Find last ':' before cursor that isn't followed by a space
  const before = val.slice(0, pos);
  const match = before.match(/:([a-z0-9_]*)$/);

  let ac = document.getElementById('gb-moji-ac');
  if (!match || match[1].length < 1) {
    if (ac) ac.style.display = 'none';
    return;
  }

  const query = match[1];
  loadMojis(mojis => {
    const results = mojis.filter(m => m.name.startsWith(query)).slice(0, 8);
    if (!results.length) { if (ac) ac.style.display = 'none'; return; }

    if (!ac) {
      ac = document.createElement('div');
      ac.id = 'gb-moji-ac';
      ac.style.cssText = 'position:absolute;z-index:400;background:white;border:1px solid #dddfe2;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,0.12);min-width:180px;overflow:hidden;';
      const composer = document.getElementById('composer');
      if (composer) { composer.style.position='relative'; composer.appendChild(ac); }
    }

    ac.innerHTML = results.map((m, i) => `
      <div class="ac-item ${i===0?'ac-active':''}" onclick="insertMojiAc(':${m.name}:', ${match[1].length})"
        style="display:flex;align-items:center;gap:8px;padding:6px 10px;cursor:pointer;font-size:13px;"
        onmouseover="document.querySelectorAll('.ac-item').forEach(x=>x.classList.remove('ac-active'));this.classList.add('ac-active')"
      >
        <img src="${escAttr(m.url)}" width="24" height="24" style="image-rendering:pixelated;border-radius:3px;">
        <span>:${escHtml(m.name)}:</span>
      </div>`).join('');

    ac.style.display = 'block';
    // Position above textarea
    ac.style.bottom = (ta.offsetHeight + 4) + 'px';
    ac.style.left   = '12px';
  });
}

function insertMojiAc(token, typedLen) {
  const ta = document.getElementById('post-content');
  if (!ta) return;
  const pos = ta.selectionStart;
  // Replace from the opening colon back to cursor
  const start = pos - typedLen - 1; // -1 for the ':'
  ta.value = ta.value.slice(0, start) + token + ta.value.slice(pos);
  ta.selectionStart = ta.selectionEnd = start + token.length;
  ta.focus();
  const ac = document.getElementById('gb-moji-ac');
  if (ac) ac.style.display = 'none';
}

