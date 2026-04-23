<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!logged_in()) json_response(['error' => 'Not logged in'], 401);
$me = current_user();

$action = $_POST['action'] ?? '';

// ── SAVE INTRO ───────────────────────────────────────────────
if ($action === 'save_intro') {
    $raw = $_POST['intro'] ?? '[]';
    $bio = mb_substr(trim($_POST['bio'] ?? ''), 0, 500);
    $intro = json_decode($raw, true);
    if (!is_array($intro)) $intro = [];

    $clean = array_map(function($item) {
        return [
            'icon' => mb_substr(strip_tags($item['icon'] ?? '📍'), 0, 4),
            'text' => mb_substr(strip_tags($item['text'] ?? ''), 0, 200),
        ];
    }, array_slice($intro, 0, 20));

    db()->prepare("UPDATE users SET intro_fields=?, bio=? WHERE id=?")
       ->execute([json_encode($clean), $bio, $me['id']]);
    json_response(['ok' => true]);
}

// ── UPLOAD AVATAR ────────────────────────────────────────────
if ($action === 'upload_avatar') {
    if (!isset($_FILES['avatar'])) json_response(['error' => 'No file'], 400);

    // Delete old avatar
    $stmt = db()->prepare("SELECT avatar FROM users WHERE id=? LIMIT 1");
    $stmt->execute([$me['id']]);
    $old = $stmt->fetchColumn();
    if ($old && defined('UPLOAD_DIR') && file_exists(UPLOAD_DIR . $old)) {
        unlink(UPLOAD_DIR . $old);
    }

    $path = upload_image($_FILES['avatar'], 'avatars', 400);
    if (!$path) json_response(['error' => 'Upload failed'], 500);

    db()->prepare("UPDATE users SET avatar=? WHERE id=?")->execute([$path, $me['id']]);
    $_SESSION['gb_user']['avatar'] = $path;

    json_response(['ok' => true, 'url' => UPLOAD_URL . $path]);
}

// ── UPLOAD COVER ─────────────────────────────────────────────
if ($action === 'upload_cover') {
    if (!isset($_FILES['cover'])) json_response(['error' => 'No file'], 400);

    // Delete old cover
    $stmt = db()->prepare("SELECT cover FROM users WHERE id=? LIMIT 1");
    $stmt->execute([$me['id']]);
    $old = $stmt->fetchColumn();
    if ($old && defined('UPLOAD_DIR') && file_exists(UPLOAD_DIR . $old)) {
        unlink(UPLOAD_DIR . $old);
    }

    $path = upload_image($_FILES['cover'], 'covers', 1200);
    if (!$path) json_response(['error' => 'Upload failed'], 500);

    db()->prepare("UPDATE users SET cover=? WHERE id=?")->execute([$path, $me['id']]);
    json_response(['ok' => true, 'url' => UPLOAD_URL . $path]);
}

json_response(['error' => 'Unknown action'], 400);
