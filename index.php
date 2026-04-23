<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

// Redirect logged-in users to home
if (logged_in()) {
    header('Location: home.php');
    exit;
}

$mode = $_GET['mode'] ?? 'login'; // 'login' or 'register'
$error = '';

// ── LOGIN ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'login') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $mode = 'login';

        if ($username && $password) {
            $stmt = db()->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            if ($user && password_verify($password, $user['password_hash'])) {
                login_user($user);
                header('Location: home.php');
                exit;
            } else {
                $error = 'Invalid username or password.';
            }
        } else {
            $error = 'Please fill in all fields.';
        }

    } elseif ($_POST['action'] === 'register') {
        $mode = 'register';
        $username     = slugify(trim($_POST['username'] ?? ''));
        $display_name = trim($_POST['display_name'] ?? '');
        $password     = $_POST['password'] ?? '';
        $password2    = $_POST['password2'] ?? '';

        if (!$username || !$display_name || !$password) {
            $error = 'All fields are required.';
        } elseif (strlen($username) < 3 || strlen($username) > 40) {
            $error = 'Username must be 3–40 characters.';
        } elseif (!preg_match('/^[a-z0-9_]+$/', $username)) {
            $error = 'Username may only contain letters, numbers, and underscores.';
        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters.';
        } elseif ($password !== $password2) {
            $error = 'Passwords do not match.';
        } else {
            $stmt = db()->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $error = 'That username is already taken.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = db()->prepare("INSERT INTO users (username, display_name, password_hash) VALUES (?, ?, ?)");
                $stmt->execute([$username, $display_name, $hash]);
                $new_id = db()->lastInsertId();
                $user = db()->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
                $user->execute([$new_id]);
                login_user($user->fetch());
                header('Location: home.php');
                exit;
            }
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
<title><?= h(SITE_NAME) ?> — Connect with people</title>
<link rel="stylesheet" href="assets/style.css">
<style>
  /* Landing-specific */
  body { display: flex; flex-direction: column; min-height: 100vh; }
  #landing {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 40px;
    padding: 40px 20px;
    flex-wrap: wrap;
  }
  .landing-left { max-width: 380px; }
  .landing-logo { font-size: 36px; font-weight: 900; font-style: italic; color: #3b5998; letter-spacing: -2px; margin-bottom: 8px; }
  .landing-logo span { color: #8b9dc3; }
  .landing-tagline { font-size: 22px; color: #1c1e21; font-weight: 700; margin-bottom: 8px; line-height: 1.3; }
  .landing-sub { font-size: 14px; color: #606770; }
  .auth-box {
    background: white;
    border-radius: 8px;
    border: 1px solid #dddfe2;
    padding: 24px;
    width: 100%;
    max-width: 360px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.1);
  }
  .auth-tabs { display: flex; margin-bottom: 16px; border-bottom: 1px solid #dddfe2; }
  .auth-tab {
    flex: 1; padding: 8px; text-align: center; font-weight: 700; font-size: 13px;
    cursor: pointer; color: #888; border-bottom: 3px solid transparent; margin-bottom: -1px;
    background: none; border-top: none; border-left: none; border-right: none;
    font-family: Tahoma, Arial, sans-serif;
  }
  .auth-tab.active { color: #3b5998; border-bottom-color: #3b5998; }
  .auth-panel { display: none; }
  .auth-panel.active { display: block; }
  .auth-field { margin-bottom: 10px; }
  .auth-field label { display: block; font-size: 11px; font-weight: 700; color: #555; margin-bottom: 3px; }
  .auth-field input {
    width: 100%; border: 1px solid #dddfe2; border-radius: 4px; padding: 8px 10px;
    font-size: 13px; font-family: Tahoma, Arial, sans-serif; outline: none;
  }
  .auth-field input:focus { border-color: #3b5998; }
  .auth-btn {
    background: #3b5998; color: white; border: none; border-radius: 4px;
    padding: 10px; font-size: 14px; font-family: Tahoma, Arial, sans-serif;
    font-weight: 700; cursor: pointer; width: 100%; margin-top: 4px;
  }
  .auth-btn:hover { background: #4a6ab5; }
  .auth-error { background: #fee; border: 1px solid #fcc; border-radius: 4px; padding: 8px 10px; color: #c00; font-size: 12px; margin-bottom: 10px; }
  .auth-hint { font-size: 11px; color: #aaa; margin-top: 4px; }
  footer { text-align: center; padding: 14px; font-size: 11px; color: #aaa; border-top: 1px solid #dddfe2; background: white; }
</style>
<?php include __DIR__ . '/includes/pwa.php'; ?>
</head>
<body>
<div id="landing">
  <div class="landing-left">
    <div class="landing-logo">G<span>oo</span>dbook</div>
    <div class="landing-tagline">Connect with friends and the world around you.</div>
    <div class="landing-sub">Share what's on your mind. See what your friends are up to.</div>
  </div>

  <div class="auth-box">
    <div class="auth-tabs">
      <button class="auth-tab <?= $mode === 'login' ? 'active' : '' ?>" onclick="switchTab('login')">Log In</button>
      <button class="auth-tab <?= $mode === 'register' ? 'active' : '' ?>" onclick="switchTab('register')">Sign Up</button>
    </div>

    <?php if ($error): ?>
      <div class="auth-error"><?= h($error) ?></div>
    <?php endif; ?>

    <!-- LOGIN -->
    <div class="auth-panel <?= $mode === 'login' ? 'active' : '' ?>" id="panel-login">
      <form method="POST">
        <input type="hidden" name="action" value="login">
        <div class="auth-field">
          <label>Username</label>
          <input type="text" name="username" autofocus autocomplete="username">
        </div>
        <div class="auth-field">
          <label>Password</label>
          <input type="password" name="password" autocomplete="current-password">
        </div>
        <button type="submit" class="auth-btn">Log In</button>
      </form>
    </div>

    <!-- REGISTER -->
    <div class="auth-panel <?= $mode === 'register' ? 'active' : '' ?>" id="panel-register">
      <form method="POST">
        <input type="hidden" name="action" value="register">
        <div class="auth-field">
          <label>Display Name</label>
          <input type="text" name="display_name" placeholder="Your name as shown on your profile">
        </div>
        <div class="auth-field">
          <label>Username</label>
          <input type="text" name="username" placeholder="letters, numbers, underscores" id="reg-username">
          <div class="auth-hint">Your profile URL: <?= h(SITE_URL) ?>/profile.php?u=<span id="username-preview">you</span></div>
        </div>
        <div class="auth-field">
          <label>Password</label>
          <input type="password" name="password" autocomplete="new-password">
        </div>
        <div class="auth-field">
          <label>Confirm Password</label>
          <input type="password" name="password2" autocomplete="new-password">
        </div>
        <button type="submit" class="auth-btn">Create Account</button>
      </form>
    </div>
  </div>
</div>

<footer><?= h(SITE_NAME) ?> &copy; <?= date('Y') ?></footer>

<script>
function switchTab(tab) {
  document.querySelectorAll('.auth-tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.auth-panel').forEach(p => p.classList.remove('active'));
  document.querySelector('.auth-tab:' + (tab === 'login' ? 'first-child' : 'last-child')).classList.add('active');
  document.getElementById('panel-' + tab).classList.add('active');
}
document.getElementById('reg-username').addEventListener('input', function() {
  document.getElementById('username-preview').textContent = this.value || 'you';
});
</script>
</body>
</html>
