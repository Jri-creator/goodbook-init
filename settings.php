<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

require_login();
$me = current_user();

$stmt = db()->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$me['id']]);
$user = $stmt->fetch();

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_profile') {
        $display_name    = trim($_POST['display_name'] ?? '');
        $bio             = trim($_POST['bio'] ?? '');
        $profile_public  = isset($_POST['profile_public']) ? 1 : 0;
        $wall_permission = $_POST['wall_permission'] ?? 'friends';
        $allowed_wp      = ['only_me', 'friends', 'users', 'public'];
        if (!in_array($wall_permission, $allowed_wp)) $wall_permission = 'friends';

        if (!$display_name) {
            $error = 'Display name is required.';
        } else {
            db()->prepare("UPDATE users SET display_name=?, bio=?, profile_public=?, wall_permission=? WHERE id=?")
               ->execute([$display_name, $bio, $profile_public, $wall_permission, $me['id']]);
            $_SESSION['gb_user']['display_name'] = $display_name;
            $user['display_name'] = $display_name;
            $user['bio'] = $bio;
            $user['profile_public'] = $profile_public;
            $user['wall_permission'] = $wall_permission;
            $success = 'Profile saved.';
        }

    } elseif ($action === 'change_password') {
        $current  = $_POST['current_password'] ?? '';
        $new      = $_POST['new_password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        if (!$current || !$new || !$confirm) {
            $error = 'All password fields are required.';
        } elseif (!password_verify($current, $user['password_hash'])) {
            $error = 'Current password is incorrect.';
        } elseif (strlen($new) < 6) {
            $error = 'New password must be at least 6 characters.';
        } elseif ($new !== $confirm) {
            $error = 'New passwords do not match.';
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            db()->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$hash, $me['id']]);
            $success = 'Password changed.';
        }

    } elseif ($action === 'delete_account') {
        $confirm_pw = $_POST['delete_confirm_password'] ?? '';
        if (password_verify($confirm_pw, $user['password_hash'])) {
            db()->prepare("DELETE FROM users WHERE id=?")->execute([$me['id']]);
            logout_user();
            header('Location: index.php');
            exit;
        } else {
            $error = 'Incorrect password — account not deleted.';
        }
    } elseif ($action === 'save_discord_webhook') {
    $webhook_url = trim($_POST['discord_webhook_url'] ?? '');
    $webhook_enabled = isset($_POST['discord_webhook_enabled']) ? 1 : 0;
    
    // User must have public profile to use webhooks
    if ($webhook_enabled && !$user['profile_public']) {
        $error = 'Your profile must be public to use Discord webhooks.';
    } elseif ($webhook_url && !validate_discord_webhook_url($webhook_url)) {
        $error = 'Invalid Discord webhook URL.';
    } else {
        db()->prepare("UPDATE users SET discord_webhook_url=?, discord_webhook_enabled=? WHERE id=?")
           ->execute([$webhook_url ?: null, $webhook_enabled, $me['id']]);
        $user['discord_webhook_url'] = $webhook_url;
        $user['discord_webhook_enabled'] = $webhook_enabled;
        $success = 'Discord webhook settings saved.';
    }
}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/x-icon" href="favicon.ico">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Settings — <?= h(SITE_NAME) ?></title>
<link rel="stylesheet" href="assets/style.css">
<?php include __DIR__ . '/includes/pwa.php'; ?>
</head>
<body>

<?php include __DIR__ . '/includes/topbar.php'; ?>

<div style="max-width:620px;margin:24px auto;padding:0 16px;">
  <h2 style="font-size:18px;font-weight:700;margin-bottom:16px;color:#1c1e21;">⚙️ Settings</h2>

  <?php if ($success): ?>
    <div style="background:#efe;border:1px solid #cfc;border-radius:4px;padding:10px;color:#060;margin-bottom:16px;font-size:13px;">✅ <?= h($success) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div style="background:#fee;border:1px solid #fcc;border-radius:4px;padding:10px;color:#c00;margin-bottom:16px;font-size:13px;">⚠️ <?= h($error) ?></div>
  <?php endif; ?>

  <!-- Profile Info -->
  <div class="card" style="margin-bottom:16px;">
    <div class="card-title">Profile Info</div>
    <form method="POST" style="padding:12px;">
      <input type="hidden" name="action" value="save_profile">

      <div class="settings-field">
        <label>Display Name</label>
        <input type="text" name="display_name" value="<?= h($user['display_name']) ?>" required>
      </div>
      <div class="settings-field">
        <label>Bio <span style="font-weight:400;color:#aaa;">(shown on your profile)</span></label>
        <textarea name="bio" rows="3" style="width:100%;border:1px solid #dddfe2;border-radius:4px;padding:8px;font-size:13px;font-family:Tahoma,Arial,sans-serif;resize:vertical;outline:none;"><?= h($user['bio'] ?? '') ?></textarea>
      </div>
      <div class="settings-field">
        <label>Profile Visibility</label>
        <label style="font-weight:400;display:flex;align-items:center;gap:6px;cursor:pointer;">
          <input type="checkbox" name="profile_public" <?= $user['profile_public'] ? 'checked' : '' ?>>
          Make my profile public (visible without logging in)
        </label>
        <div style="font-size:11px;color:#aaa;margin-top:4px;">When off, only you and your friends can see your profile.</div>
      </div>
      <div class="settings-field">
        <label>Who can post on my wall?</label>
        <select name="wall_permission" style="border:1px solid #dddfe2;border-radius:4px;padding:6px 8px;font-size:13px;font-family:Tahoma,Arial,sans-serif;outline:none;">
          <option value="only_me"  <?= $user['wall_permission']==='only_me'  ? 'selected':'' ?>>Only me</option>
          <option value="friends"  <?= $user['wall_permission']==='friends'  ? 'selected':'' ?>>Friends only</option>
          <option value="users"    <?= $user['wall_permission']==='users'    ? 'selected':'' ?>>Any logged-in user</option>
          <option value="public"   <?= $user['wall_permission']==='public'   ? 'selected':'' ?>>Everyone (including guests)</option>
        </select>
      </div>
      <button type="submit" class="auth-btn" style="width:auto;padding:8px 20px;margin-top:4px;">Save Changes</button>
    </form>
  </div>

    <!-- Discord Webhooks -->
<div class="card" style="margin-bottom:16px;">
  <div class="card-title">Discord Webhooks</div>
  <form method="POST" style="padding:12px;">
    <input type="hidden" name="action" value="save_discord_webhook">
    
    <div class="settings-field">
      <label>Webhook URL</label>
      <input type="url" name="discord_webhook_url" 
             value="<?= h($user['discord_webhook_url'] ?? '') ?>"
             placeholder="https://discord.com/api/webhooks/...">
      <div style="font-size:11px;color:#aaa;margin-top:4px;">
        Get this from Discord Server Settings → Webhooks. Your profile must be public to use this feature.
      </div>
    </div>
    
    <div class="settings-field">
      <label style="font-weight:400;display:flex;align-items:center;gap:6px;cursor:pointer;">
        <input type="checkbox" name="discord_webhook_enabled" 
               <?= ($user['discord_webhook_enabled'] && $user['profile_public']) ? 'checked' : '' ?>
               <?= !$user['profile_public'] ? 'disabled' : '' ?>>
        Send Discord notification when someone posts on my wall
      </label>
      <?php if (!$user['profile_public']): ?>
        <div style="font-size:11px;color:#c00;margin-top:4px;">⚠️ Your profile must be public to enable this feature.</div>
      <?php endif; ?>
      <div style="font-size:11px;color:#aaa;margin-top:4px;">Limited to 10 posts per hour.</div>
    </div>
    
    <button type="submit" class="auth-btn" style="width:auto;padding:8px 20px;margin-top:4px;">Save Webhook</button>
  </form>
</div>

  <!-- Username (read-only display) -->
  <div class="card" style="margin-bottom:16px;">
    <div class="card-title">Account Info</div>
    <div style="padding:12px;font-size:13px;color:#555;">
      <div><strong>Username:</strong> <?= h($user['username']) ?> <span style="color:#aaa;font-size:11px;">(cannot be changed)</span></div>
    </div>
  </div>

  <!-- Change Password -->
  <div class="card" style="margin-bottom:16px;">
    <div class="card-title">Change Password</div>
    <form method="POST" style="padding:12px;">
      <input type="hidden" name="action" value="change_password">
      <div class="settings-field">
        <label>Current Password</label>
        <input type="password" name="current_password" autocomplete="current-password">
      </div>
      <div class="settings-field">
        <label>New Password</label>
        <input type="password" name="new_password" autocomplete="new-password">
      </div>
      <div class="settings-field">
        <label>Confirm New Password</label>
        <input type="password" name="confirm_password" autocomplete="new-password">
      </div>
      <button type="submit" class="auth-btn" style="width:auto;padding:8px 20px;">Change Password</button>
    </form>
  </div>

  <!-- Danger Zone -->
  <div class="card" style="margin-bottom:16px;border-color:#fcc;">
    <div class="card-title" style="color:#c00;">⚠️ Danger Zone</div>
    <div style="padding:12px;">
      <p style="font-size:13px;color:#555;margin-bottom:12px;">Deleting your account is permanent. All your posts, comments, and friend connections will be removed.</p>
      <button onclick="document.getElementById('delete-form').style.display='block';this.style.display='none';" style="background:#e44;color:white;border:none;border-radius:4px;padding:8px 16px;font-size:13px;font-family:Tahoma,Arial,sans-serif;cursor:pointer;">Delete My Account</button>
      <form method="POST" id="delete-form" style="display:none;margin-top:12px;">
        <input type="hidden" name="action" value="delete_account">
        <div class="settings-field">
          <label>Confirm with your password</label>
          <input type="password" name="delete_confirm_password" autocomplete="current-password">
        </div>
        <button type="submit" style="background:#c00;color:white;border:none;border-radius:4px;padding:8px 16px;font-size:13px;font-family:Tahoma,Arial,sans-serif;cursor:pointer;font-weight:700;">Yes, permanently delete my account</button>
      </form>
    </div>
  </div>

</div>

<style>
.settings-field { margin-bottom: 14px; }
.settings-field label { display: block; font-size: 12px; font-weight: 700; color: #555; margin-bottom: 4px; }
.settings-field input[type=text], .settings-field input[type=password] {
  width: 100%; border: 1px solid #dddfe2; border-radius: 4px; padding: 8px 10px;
  font-size: 13px; font-family: Tahoma, Arial, sans-serif; outline: none;
}
.settings-field input:focus { border-color: #3b5998; }
</style>
</body>
</html>
