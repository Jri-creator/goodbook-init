<?php
// upgrade.php — Run this ONCE on existing Goodbook installs to add:
//   - is_admin column to users table
//   - mojis table
//   - discord webhook columns
// Safe to run multiple times (uses IF NOT EXISTS / IF EXISTS guards).
// Delete this file when done.

define('GOODBOOK_SETUP', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$log = [];

try {
    // Add is_admin column if not present
    try {
        db()->exec("ALTER TABLE users ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0");
        $log[] = '✅ Added is_admin column to users.';
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate column')) {
            $log[] = '— is_admin column already exists, skipped.';
        } else throw $e;
    }

    // Create mojis table if not present
    db()->exec("
        CREATE TABLE IF NOT EXISTS mojis (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(60) NOT NULL,
            image_path TEXT NOT NULL,
            uploaded_by INT NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_active_name (name, active),
            FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $log[] = '✅ mojis table ready.';

    // Add Discord webhook columns if not present
    try {
        db()->exec("ALTER TABLE users ADD COLUMN discord_webhook_url TEXT DEFAULT NULL");
        $log[] = '✅ Added discord_webhook_url column to users.';
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate column')) {
            $log[] = '— discord_webhook_url column already exists, skipped.';
        } else throw $e;
    }

    try {
        db()->exec("ALTER TABLE users ADD COLUMN discord_webhook_enabled TINYINT(1) NOT NULL DEFAULT 0");
        $log[] = '✅ Added discord_webhook_enabled column to users.';
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate column')) {
            $log[] = '— discord_webhook_enabled column already exists, skipped.';
        } else throw $e;
    }

    try {
        db()->exec("ALTER TABLE users ADD COLUMN discord_posts_today INT NOT NULL DEFAULT 0");
        $log[] = '✅ Added discord_posts_today column to users.';
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate column')) {
            $log[] = '— discord_posts_today column already exists, skipped.';
        } else throw $e;
    }

    try {
        db()->exec("ALTER TABLE users ADD COLUMN discord_last_reset DATETIME DEFAULT NULL");
        $log[] = '✅ Added discord_last_reset column to users.';
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate column')) {
            $log[] = '— discord_last_reset column already exists, skipped.';
        } else throw $e;
    }

    $success = true;
} catch (PDOException $e) {
    $log[] = '❌ Error: ' . $e->getMessage();
    $success = false;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Goodbook Upgrade</title>
<style>
  body { font-family: Tahoma, Arial, sans-serif; font-size: 13px; background: #e9ebee; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
  .box { background: white; border-radius: 8px; border: 1px solid #dddfe2; padding: 30px; max-width: 480px; width: 90%; box-shadow: 0 2px 12px rgba(0,0,0,0.1); }
  h1 { font-size: 20px; font-weight: 900; font-style: italic; color: #3b5998; margin-bottom: 16px; }
  .log-line { padding: 5px 0; border-bottom: 1px solid #f0f2f5; font-size: 13px; }
  .note { background: #fff8e1; border: 1px solid #ffe082; border-radius: 4px; padding: 10px; margin-top: 16px; font-size: 12px; color: #555; }
  a { color: #3b5998; }
</style>
</head>
<body>
<div class="box">
  <h1>Goodbook Upgrade</h1>
  <?php foreach ($log as $line): ?>
    <div class="log-line"><?= htmlspecialchars($line) ?></div>
  <?php endforeach; ?>
  <?php if ($success ?? false): ?>
    <div class="note">
      ✅ <strong>Upgrade complete.</strong><br><br>
      <strong>Delete this file now.</strong> <a href="index.php">← Back to Goodbook</a>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
