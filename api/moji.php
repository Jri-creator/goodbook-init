<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$me = current_user();

// ── LIST (public, for autocomplete/picker) ────────────────────
if ($action === 'list') {
    $mojis = get_active_mojis();
    json_response(['ok' => true, 'mojis' => $mojis]);
}

// All other actions require login
if (!logged_in()) {
    json_response(['error' => 'Not logged in'], 401);
}

// ── UPLOAD ────────────────────────────────────────────────────
if ($action === 'upload') {
    $name = trim($_POST['name'] ?? '');
    $agreed = $_POST['agreed'] ?? '';

    if (!$agreed) json_response(['error' => 'You must agree to the content policy.'], 400);
    if (!$name)   json_response(['error' => 'Moji name is required.'], 400);

    // Validate name: lowercase letters, numbers, underscores only
    $name = strtolower($name);
    if (!preg_match('/^[a-z0-9_]{2,40}$/', $name)) {
        json_response(['error' => 'Name must be 2–40 characters: letters, numbers, underscores only.'], 400);
    }

    // Block reserved names (standard emoji shortcodes that could cause confusion)
    $reserved = ['image', 'moji', 'emoji', 'here', 'everyone', 'channel'];
    if (in_array($name, $reserved)) {
        json_response(['error' => 'That name is reserved.'], 400);
    }

    // Check name not already active
    $stmt = db()->prepare("SELECT id FROM mojis WHERE name = ? AND active = 1 LIMIT 1");
    $stmt->execute([$name]);
    if ($stmt->fetch()) {
        json_response(['error' => "A moji named :{$name}: already exists."], 409);
    }

    // Validate uploaded image (already cropped to square by client canvas)
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        json_response(['error' => 'Image upload failed.'], 400);
    }

    $allowed_mime = ['image/png', 'image/gif', 'image/webp'];
    $mime = mime_content_type($_FILES['image']['tmp_name']);
    if (!in_array($mime, $allowed_mime)) {
        json_response(['error' => 'Only PNG, GIF, and WebP images are allowed.'], 400);
    }

    // Server-side resize to exactly 128×128 (stored at high quality, displayed at 32px)
    $ext_map = ['image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $ext = $ext_map[$mime];

    $dir = UPLOAD_DIR . 'mojis/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $filename = $name . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = $dir . $filename;

    if ($mime === 'image/gif') {
        // Keep GIF as-is (animated GIF support)
        if (!move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
            json_response(['error' => 'Failed to save image.'], 500);
        }
    } else {
        // Resize to 128×128 with GD
        $src_data = file_get_contents($_FILES['image']['tmp_name']);
        $img = imagecreatefromstring($src_data);
        if (!$img) json_response(['error' => 'Could not process image.'], 400);

        $out = imagecreatetruecolor(128, 128);
        if ($mime === 'image/png' || $mime === 'image/webp') {
            imagealphablending($out, false);
            imagesavealpha($out, true);
            $transparent = imagecolorallocatealpha($out, 0, 0, 0, 127);
            imagefill($out, 0, 0, $transparent);
        }
        imagecopyresampled($out, $img, 0, 0, 0, 0, 128, 128, imagesx($img), imagesy($img));
        imagedestroy($img);

        switch ($mime) {
            case 'image/png':  imagepng($out, $dest, 6); break;
            case 'image/webp': imagewebp($out, $dest, 90); break;
        }
        imagedestroy($out);
    }

    // Save to DB
    db()->prepare("INSERT INTO mojis (name, image_path, uploaded_by, active) VALUES (?, ?, ?, 1)")
       ->execute([$name, 'mojis/' . $filename, $me['id']]);

    $new_id = (int)db()->lastInsertId();
    json_response([
        'ok'   => true,
        'moji' => [
            'id'   => $new_id,
            'name' => $name,
            'url'  => UPLOAD_URL . 'mojis/' . $filename,
        ]
    ]);
}

// ── DELETE (admin only) ───────────────────────────────────────
if ($action === 'delete') {
    // Load full user record to check is_admin
    $stmt = db()->prepare("SELECT is_admin FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$me['id']]);
    $u = $stmt->fetch();
    if (!$u || !$u['is_admin']) json_response(['error' => 'Admin only.'], 403);

    $moji_id = (int)($_POST['moji_id'] ?? 0);
    if (!$moji_id) json_response(['error' => 'No moji ID.'], 400);

    // Set active = 0. Image file stays on disk permanently.
    db()->prepare("UPDATE mojis SET active = 0 WHERE id = ?")->execute([$moji_id]);
    json_response(['ok' => true]);
}

// ── ADMIN RESTORE ─────────────────────────────────────────────
if ($action === 'restore') {
    $stmt = db()->prepare("SELECT is_admin FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$me['id']]);
    $u = $stmt->fetch();
    if (!$u || !$u['is_admin']) json_response(['error' => 'Admin only.'], 403);

    $moji_id = (int)($_POST['moji_id'] ?? 0);
    if (!$moji_id) json_response(['error' => 'No moji ID.'], 400);

    // Check name isn't taken by another active moji
    $stmt = db()->prepare("SELECT name FROM mojis WHERE id = ? LIMIT 1");
    $stmt->execute([$moji_id]);
    $row = $stmt->fetch();
    if (!$row) json_response(['error' => 'Moji not found.'], 404);

    $name = $row['name'];
    $stmt = db()->prepare("SELECT id FROM mojis WHERE name = ? AND active = 1 AND id != ? LIMIT 1");
    $stmt->execute([$name, $moji_id]);
    if ($stmt->fetch()) {
        json_response(['error' => "Another active moji already uses :{$name}:"], 409);
    }

    db()->prepare("UPDATE mojis SET active = 1 WHERE id = ?")->execute([$moji_id]);
    json_response(['ok' => true]);
}

json_response(['error' => 'Unknown action'], 400);
