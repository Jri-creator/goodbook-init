<?php
// Project Goodbook — Setup Installer
// Run this once, then delete or rename it.

define('GOODBOOK_SETUP', true);

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db_host = trim($_POST['db_host'] ?? 'localhost');
    $db_name = trim($_POST['db_name'] ?? '');
    $db_user = trim($_POST['db_user'] ?? '');
    $db_pass = $_POST['db_pass'] ?? '';
    $site_name = trim($_POST['site_name'] ?? 'Goodbook');
    $site_url  = rtrim(trim($_POST['site_url'] ?? ''), '/');

    if (!$db_name || !$db_user) {
        $error = 'Database name and username are required.';
    } else {
        try {
            $pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            // Create tables
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS users (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    username VARCHAR(40) NOT NULL UNIQUE,
                    display_name VARCHAR(80) NOT NULL,
                    password_hash VARCHAR(255) NOT NULL,
                    avatar TEXT DEFAULT NULL,
                    cover TEXT DEFAULT NULL,
                    bio TEXT DEFAULT NULL,
                    intro_fields TEXT DEFAULT NULL,
                    profile_public TINYINT(1) NOT NULL DEFAULT 0,
                    wall_permission ENUM('only_me','friends','users','public') NOT NULL DEFAULT 'friends',
                    is_admin TINYINT(1) NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS friendships (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    requester_id INT NOT NULL,
                    addressee_id INT NOT NULL,
                    status ENUM('pending','accepted','declined') NOT NULL DEFAULT 'pending',
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_pair (requester_id, addressee_id),
                    FOREIGN KEY (requester_id) REFERENCES users(id) ON DELETE CASCADE,
                    FOREIGN KEY (addressee_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS posts (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    author_id INT NOT NULL,
                    wall_owner_id INT NOT NULL,
                    content TEXT NOT NULL,
                    image TEXT DEFAULT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE,
                    FOREIGN KEY (wall_owner_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS likes (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    post_id INT NOT NULL,
                    user_id INT NOT NULL,
                    UNIQUE KEY unique_like (post_id, user_id),
                    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS comments (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    post_id INT NOT NULL,
                    author_id INT NOT NULL,
                    content TEXT NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
                    FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS mojis (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(60) NOT NULL,
                    image_path TEXT NOT NULL,
                    uploaded_by INT NOT NULL,
                    active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_active_name (name, active),
                    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");

            // Write config.php
            $config = "<?php\n// Project Goodbook — Auto-generated config\n// DO NOT commit this file to version control.\n\ndefine('DB_HOST', " . var_export($db_host, true) . ");\ndefine('DB_NAME', " . var_export($db_name, true) . ");\ndefine('DB_USER', " . var_export($db_user, true) . ");\ndefine('DB_PASS', " . var_export($db_pass, true) . ");\ndefine('SITE_NAME', " . var_export($site_name, true) . ");\ndefine('SITE_URL', " . var_export($site_url, true) . ");\ndefine('UPLOAD_DIR', __DIR__ . '/uploads/');\ndefine('UPLOAD_URL', SITE_URL . '/uploads/');\ndefine('SETUP_COMPLETE', true);\n";

            file_put_contents(__DIR__ . '/config.php', $config);

            $success = true;
        } catch (PDOException $e) {
            $error = 'Database error: ' . htmlspecialchars($e->getMessage());
        }
    }
}

// Guess site URL
$guessed_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . rtrim(dirname($_SERVER['PHP_SELF'] ?? ''), '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/x-icon" href="favicon.ico">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Goodbook Setup</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: Tahoma, Arial, sans-serif; font-size: 13px; background: #e9ebee; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
  .box { background: white; border-radius: 8px; border: 1px solid #dddfe2; padding: 30px; width: 100%; max-width: 480px; box-shadow: 0 2px 12px rgba(0,0,0,0.1); }
  h1 { font-size: 22px; font-weight: 900; font-style: italic; color: #3b5998; margin-bottom: 4px; letter-spacing: -1px; }
  h1 span { color: #8b9dc3; }
  .subtitle { color: #888; font-size: 12px; margin-bottom: 20px; }
  .field { margin-bottom: 14px; }
  label { display: block; font-weight: 700; color: #555; margin-bottom: 4px; font-size: 12px; }
  input[type=text], input[type=password], input[type=url] { width: 100%; border: 1px solid #dddfe2; border-radius: 4px; padding: 8px 10px; font-size: 13px; font-family: Tahoma, Arial, sans-serif; outline: none; }
  input:focus { border-color: #3b5998; }
  .btn { background: #3b5998; color: white; border: none; border-radius: 4px; padding: 10px 20px; font-size: 14px; font-family: Tahoma, Arial, sans-serif; font-weight: 700; cursor: pointer; width: 100%; }
  .btn:hover { background: #4a6ab5; }
  .error { background: #fee; border: 1px solid #fcc; border-radius: 4px; padding: 10px; color: #c00; margin-bottom: 16px; font-size: 12px; }
  .success { background: #efe; border: 1px solid #cfc; border-radius: 4px; padding: 16px; color: #060; margin-bottom: 16px; }
  .success a { color: #3b5998; font-weight: 700; }
  hr { border: none; border-top: 1px solid #dddfe2; margin: 18px 0; }
  .hint { font-size: 11px; color: #aaa; margin-top: 3px; }
</style>
</head>
<body>
<div class="box">
  <h1>G<span>oo</span>dbook</h1>
  <div class="subtitle">One-time setup — fill in your database details and go.</div>

  <?php if ($error): ?>
    <div class="error"><?= $error ?></div>
  <?php endif; ?>

  <?php if ($success): ?>
    <div class="success">
      <strong>✅ Setup complete!</strong><br><br>
      Your database tables have been created and <code>config.php</code> has been written.<br><br>
      <strong>Important:</strong> Delete or rename <code>setup.php</code> before going public.<br><br>
      <a href="index.php">→ Go to Goodbook</a>
    </div>
  <?php else: ?>
  <form method="POST">
    <div class="field">
      <label>Site Name</label>
      <input type="text" name="site_name" value="Goodbook" required>
    </div>
    <div class="field">
      <label>Site URL</label>
      <input type="text" name="site_url" value="<?= htmlspecialchars($guessed_url) ?>" required>
      <div class="hint">No trailing slash. e.g. https://yourdomain.com or https://yourdomain.com/goodbook</div>
    </div>
    <hr>
    <div class="field">
      <label>Database Host</label>
      <input type="text" name="db_host" value="localhost" required>
    </div>
    <div class="field">
      <label>Database Name</label>
      <input type="text" name="db_name" value="" required>
    </div>
    <div class="field">
      <label>Database Username</label>
      <input type="text" name="db_user" value="" required>
    </div>
    <div class="field">
      <label>Database Password</label>
      <input type="password" name="db_pass" value="">
    </div>
    <button type="submit" class="btn">Install Goodbook →</button>
  </form>
  <?php endif; ?>
</div>
</body>
</html>
