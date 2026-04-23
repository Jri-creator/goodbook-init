<?php
// includes/helpers.php

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function slugify(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9_]+/', '_', $s);
    $s = preg_replace('/_+/', '_', $s);
    return trim($s, '_');
}

function time_ago(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)         return 'just now';
    if ($diff < 3600)       return floor($diff / 60) . 'm ago';
    if ($diff < 86400)      return floor($diff / 3600) . 'h ago';
    if ($diff < 604800)     return floor($diff / 86400) . 'd ago';
    return date('M j, Y', strtotime($datetime));
}

function format_post_content(string $text): string {
    static $pd = null;
    if ($pd === null) {
        require_once __DIR__ . '/Parsedown.php';
        $pd = new Parsedown();
        $pd->setSafeMode(true);
        $pd->setBreaksEnabled(true);
    }
    // First render Markdown (input is already moji-encoded as [moji:ID])
    $html = $pd->text($text);
    // Then replace [moji:ID] embed tokens with <img> tags
    $html = render_mojis($html);
    // Auto-link bare URLs not already wrapped by Parsedown
    $html = preg_replace(
        '~(?<!["\'=>])(https?://[^\s<>"\']+)~i',
        '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>',
        $html
    );
    return $html;
}

// Convert :moji_name: tokens in raw user input → [moji:ID] embed tokens.
// Called at post SAVE time so the ID is locked in permanently.
function encode_mojis(string $text): string {
    // Find all :word: candidates
    return preg_replace_callback('/:([\w]+):/', function($m) {
        $name = $m[1];
        static $cache = [];
        if (array_key_exists($name, $cache)) return $cache[$name];
        $stmt = db()->prepare("SELECT id FROM mojis WHERE name = ? AND active = 1 LIMIT 1");
        $stmt->execute([$name]);
        $row = $stmt->fetch();
        $cache[$name] = $row ? '[moji:' . $row['id'] . ']' : $m[0];
        return $cache[$name];
    }, $text);
}

// Convert [moji:ID] embed tokens → <img> tags at RENDER time.
// Image file is permanent; even deleted mojis still serve the image.
function render_mojis(string $html): string {
    return preg_replace_callback('/\[moji:(\d+)\]/', function($m) {
        $id = (int)$m[1];
        static $cache = [];
        if (array_key_exists($id, $cache)) return $cache[$id];
        $stmt = db()->prepare("SELECT name, image_path FROM mojis WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) { $cache[$id] = ''; return ''; }
        $url = h(UPLOAD_URL . $row['image_path']);
        $name = h($row['name']);
        $cache[$id] = "<img src=\"{$url}\" alt=\":${name}:\" title=\":${name}:\" class=\"moji\" width=\"32\" height=\"32\" style=\"vertical-align:middle;display:inline;\">";
        return $cache[$id];
    }, $html);
}

// Load all active mojis as name→{id,url} for JS autocomplete/picker
function get_active_mojis(): array {
    $stmt = db()->prepare("SELECT id, name, image_path FROM mojis WHERE active = 1 ORDER BY name ASC");
    $stmt->execute();
    $rows = $stmt->fetchAll();
    return array_map(fn($r) => [
        'id'   => (int)$r['id'],
        'name' => $r['name'],
        'url'  => UPLOAD_URL . $r['image_path'],
    ], $rows);
}

// Upload an image file, returns relative path or null on failure.
// Resizes to max dimension on server side (GD).
function upload_image(array $file, string $subfolder = 'misc', int $max_dim = 1200): ?string {
    if (!defined('UPLOAD_DIR')) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) return null;

    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, $allowed, true)) return null;

    $ext_map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $ext = $ext_map[$mime];

    $dir = UPLOAD_DIR . $subfolder . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $filename = bin2hex(random_bytes(12)) . '.' . $ext;
    $dest = $dir . $filename;

    // Attempt GD resize
    if (function_exists('imagecreatefromstring') && $mime !== 'image/gif') {
        $src_data = file_get_contents($file['tmp_name']);
        $img = imagecreatefromstring($src_data);
        if ($img) {
            $w = imagesx($img);
            $h = imagesy($img);
            if ($w > $max_dim || $h > $max_dim) {
                $scale = min($max_dim / $w, $max_dim / $h);
                $nw = (int)($w * $scale);
                $nh = (int)($h * $scale);
                $resized = imagecreatetruecolor($nw, $nh);
                if ($mime === 'image/png') {
                    imagealphablending($resized, false);
                    imagesavealpha($resized, true);
                }
                imagecopyresampled($resized, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
                imagedestroy($img);
                $img = $resized;
            }
            switch ($mime) {
                case 'image/jpeg': imagejpeg($img, $dest, 88); break;
                case 'image/png':  imagepng($img, $dest, 7);  break;
                case 'image/webp': imagewebp($img, $dest, 88); break;
            }
            imagedestroy($img);
            return $subfolder . '/' . $filename;
        }
    }

    // Fallback: just move
    if (move_uploaded_file($file['tmp_name'], $dest)) {
        return $subfolder . '/' . $filename;
    }
    return null;
}

// Get a user's avatar URL, falling back to SVG placeholder
function avatar_url(?string $avatar_path, int $size = 40): string {
    if ($avatar_path && defined('UPLOAD_URL')) {
        return UPLOAD_URL . h($avatar_path);
    }
    // Inline SVG placeholder
    return "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 {$size} {$size}'%3E%3Crect width='{$size}' height='{$size}' fill='%23c8c8c8'/%3E%3Ccircle cx='" . ($size/2) . "' cy='" . ($size*0.38) . "' r='" . ($size*0.22) . "' fill='%23aaa'/%3E%3Cellipse cx='" . ($size/2) . "' cy='" . ($size*0.82) . "' rx='" . ($size*0.28) . "' ry='" . ($size*0.19) . "' fill='%23aaa'/%3E%3C/svg%3E";
}

// Friendship helpers
function friendship_status(int $viewer_id, int $profile_id): string {
    // Returns: 'self', 'friends', 'pending_sent', 'pending_received', 'none'
    if ($viewer_id === $profile_id) return 'self';
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT status, requester_id FROM friendships
        WHERE (requester_id = ? AND addressee_id = ?)
           OR (requester_id = ? AND addressee_id = ?)
        LIMIT 1
    ");
    $stmt->execute([$viewer_id, $profile_id, $profile_id, $viewer_id]);
    $row = $stmt->fetch();
    if (!$row) return 'none';
    if ($row['status'] === 'accepted') return 'friends';
    if ($row['status'] === 'pending') {
        return $row['requester_id'] === $viewer_id ? 'pending_sent' : 'pending_received';
    }
    return 'none';
}

function can_view_profile(array $profile, ?array $viewer): bool {
    if ($profile['profile_public']) return true;
    if (!$viewer) return false;
    if ($viewer['id'] === $profile['id']) return true;
    return friendship_status($viewer['id'], $profile['id']) === 'friends';
}

function can_post_on_wall(array $profile, ?array $viewer): bool {
    $perm = $profile['wall_permission'];
    if ($perm === 'only_me') {
        return $viewer && $viewer['id'] === $profile['id'];
    }
    if ($perm === 'friends') {
        if (!$viewer) return false;
        if ($viewer['id'] === $profile['id']) return true;
        return friendship_status($viewer['id'], $profile['id']) === 'friends';
    }
    if ($perm === 'users') {
        return $viewer !== null;
    }
    if ($perm === 'public') {
        return true; // anyone — but we still require login to actually submit
    }
    return false;
}

function json_response(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
