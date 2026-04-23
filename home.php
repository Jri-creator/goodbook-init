<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

require_login();
$me = current_user();

// Full user record for avatar etc
$stmt = db()->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$me['id']]);
$me_full = $stmt->fetch();

// Pending friend requests for me
$stmt = db()->prepare("
    SELECT u.id, u.username, u.display_name, u.avatar
    FROM friendships f
    JOIN users u ON u.id = f.requester_id
    WHERE f.addressee_id = ? AND f.status = 'pending'
    ORDER BY f.created_at DESC
");
$stmt->execute([$me['id']]);
$pending_requests = $stmt->fetchAll();

// Feed: posts from self + friends, newest first
$stmt = db()->prepare("
    SELECT p.*, u.username, u.display_name, u.avatar AS author_avatar,
           wo.username AS wall_username, wo.display_name AS wall_display_name,
           (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id) AS like_count,
           (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id AND l.user_id = ?) AS i_liked,
           (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id) AS comment_count
    FROM posts p
    JOIN users u ON u.id = p.author_id
    JOIN users wo ON wo.id = p.wall_owner_id
    WHERE p.author_id = ?
       OR p.wall_owner_id = ?
       OR p.author_id IN (
           SELECT CASE WHEN requester_id = ? THEN addressee_id ELSE requester_id END
           FROM friendships WHERE (requester_id = ? OR addressee_id = ?) AND status = 'accepted'
       )
    ORDER BY p.created_at DESC
    LIMIT 50
");
$stmt->execute([$me['id'], $me['id'], $me['id'], $me['id'], $me['id'], $me['id']]);
$posts = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/x-icon" href="favicon.ico">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h(SITE_NAME) ?> — Home</title>
<link rel="stylesheet" href="assets/style.css">
<?php include __DIR__ . '/includes/pwa.php'; ?>
</head>
<body>

<?php include __DIR__ . '/includes/topbar.php'; ?>

<div id="main" style="max-width:740px;margin:16px auto;padding:0 16px;display:grid;grid-template-columns:240px 1fr;gap:16px;">

  <!-- LEFT: Mini profile + friend requests -->
  <div>
    <div class="card" style="margin-bottom:12px;">
      <div style="display:flex;align-items:center;gap:10px;padding:12px;">
        <img src="<?= avatar_url($me_full['avatar'], 48) ?>" style="width:48px;height:48px;border-radius:50%;object-fit:cover;border:2px solid #dddfe2;">
        <div>
          <div style="font-weight:700;font-size:13px;"><a href="profile.php?u=<?= h($me['username']) ?>" style="color:#3b5998;text-decoration:none;"><?= h($me_full['display_name']) ?></a></div>
          <div style="font-size:11px;color:#888;">@<?= h($me['username']) ?></div>
        </div>
      </div>
      <div style="border-top:1px solid #dddfe2;padding:8px 12px;display:flex;flex-direction:column;gap:4px;">
        <a href="profile.php?u=<?= h($me['username']) ?>" class="side-link">👤 My Profile</a>
        <a href="settings.php" class="side-link">⚙️ Settings</a>
        <a href="api/auth.php?action=logout" class="side-link" style="color:#c00;">↩ Log Out</a>
      </div>
    </div>

    <?php if ($pending_requests): ?>
    <div class="card" style="margin-bottom:12px;">
      <div class="card-title">Friend Requests <span style="background:#e44;color:white;border-radius:10px;font-size:11px;padding:1px 6px;font-weight:700;"><?= count($pending_requests) ?></span></div>
      <?php foreach ($pending_requests as $req): ?>
      <div style="display:flex;align-items:center;gap:8px;padding:8px 12px;border-top:1px solid #f0f2f5;" id="freq-<?= $req['id'] ?>">
        <a href="profile.php?u=<?= h($req['username']) ?>">
          <img src="<?= avatar_url($req['avatar'], 36) ?>" style="width:36px;height:36px;border-radius:50%;object-fit:cover;">
        </a>
        <div style="flex:1;min-width:0;">
          <div style="font-weight:700;font-size:12px;"><a href="profile.php?u=<?= h($req['username']) ?>" style="color:#3b5998;text-decoration:none;"><?= h($req['display_name']) ?></a></div>
          <div style="display:flex;gap:4px;margin-top:4px;">
            <button class="btn-sm btn-primary" onclick="respondFriend(<?= $req['id'] ?>,'accept')">Accept</button>
            <button class="btn-sm btn-cancel" onclick="respondFriend(<?= $req['id'] ?>,'decline')">Decline</button>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- RIGHT: Composer + Feed -->
  <div>
    <!-- Composer -->
    <div class="card" id="composer" style="padding:12px;margin-bottom:12px;">
      <div style="display:flex;gap:10px;align-items:flex-start;">
        <img src="<?= avatar_url($me_full['avatar'], 40) ?>" style="width:40px;height:40px;border-radius:50%;object-fit:cover;border:1px solid #dddfe2;flex-shrink:0;">
        <textarea id="post-content" placeholder="What's on your mind, <?= h($me_full['display_name']) ?>? Markdown supported." style="flex:1;border:1px solid #dddfe2;border-radius:20px;padding:10px 14px;font-size:13px;font-family:Tahoma,Arial,sans-serif;resize:none;min-height:40px;outline:none;background:#f0f2f5;" onclick="expandComposer()" rows="1"></textarea>
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

    <!-- Feed -->
    <div id="feed">
      <?php if (empty($posts)): ?>
        <div class="card" style="padding:24px;text-align:center;color:#888;">
          <div style="font-size:32px;margin-bottom:8px;">👋</div>
          <div style="font-weight:700;">Your feed is empty.</div>
          <div style="font-size:12px;margin-top:4px;">Add some friends or make your first post!</div>
        </div>
      <?php else: ?>
        <?php foreach ($posts as $post): ?>
          <?php include __DIR__ . '/includes/post_card.php'; ?>
        <?php endforeach; ?>
      <?php endif; ?>
      <?php if (!empty($posts)): ?>
        <div class="feed-end" style="text-align:center;padding:20px 0 8px;color:#aaa;font-size:12px;">
          🎉 You're all caught up! <a href="index.php" style="color:#3b5998;text-decoration:none;">Add more friends</a> to see more posts.
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script src="assets/goodbook.js"></script>
<script>
const ME_ID = <?= (int)$me['id'] ?>;
const ME_USERNAME = <?= json_encode($me['username']) ?>;
const GB_UPLOAD_URL = <?= json_encode(UPLOAD_URL) ?>;
const CONTEXT = 'home';

function respondFriend(userId, action) {
  fetch('api/friend.php', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: 'action=' + action + '&user_id=' + userId
  }).then(r => r.json()).then(d => {
    if (d.ok) {
      document.getElementById('freq-' + userId)?.remove();
    }
  });
}
</script>
</body>
</html>
