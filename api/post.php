<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/discord.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$me = current_user();

// Read-only actions are open to guests; everything else requires login
$guest_allowed = ['get_comments'];
if (!logged_in() && !in_array($action, $guest_allowed)) {
    json_response(['error' => 'Not logged in'], 401);
}

// ── CREATE POST ──────────────────────────────────────────────
if ($action === 'create') {
    $content      = trim($_POST['content'] ?? '');
    $wall_owner_id = (int)($_POST['wall_owner_id'] ?? 0);

    if (!$content) json_response(['error' => 'Content required'], 400);
    if (!$wall_owner_id) json_response(['error' => 'No wall specified'], 400);

    // Fetch wall owner
    $stmt = db()->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$wall_owner_id]);
    $wall_owner = $stmt->fetch();
    if (!$wall_owner) json_response(['error' => 'Wall owner not found'], 404);

    // Permission check
    if (!can_post_on_wall($wall_owner, $me)) {
        json_response(['error' => 'You cannot post on this wall'], 403);
    }

    // Resolve :moji_name: → [moji:ID] before storing
    $content = encode_mojis($content);

    // Handle image upload
    $image_path = null;
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $image_path = upload_image($_FILES['image'], 'posts');
    }

    $stmt = db()->prepare("INSERT INTO posts (author_id, wall_owner_id, content, image) VALUES (?, ?, ?, ?)");
    $stmt->execute([$me['id'], $wall_owner_id, $content, $image_path]);
    $post_id = (int)db()->lastInsertId();

    // Send to Discord if enabled
    // require_once __DIR__ . '/../includes/discord.php';
    send_discord_webhook($wall_owner, $post, $me);

    // Fetch full post for response
    $stmt = db()->prepare("
        SELECT p.*, u.username, u.display_name, u.avatar AS author_avatar,
               wo.username AS wall_username, wo.display_name AS wall_display_name,
               0 AS like_count, 0 AS i_liked, 0 AS comment_count
        FROM posts p
        JOIN users u ON u.id = p.author_id
        JOIN users wo ON wo.id = p.wall_owner_id
        WHERE p.id = ?
    ");
    $stmt->execute([$post_id]);
    $post = $stmt->fetch();

    // Add resolved URLs and server-rendered content for JS
    $post['author_avatar_url'] = avatar_url($post['author_avatar'], 40);
    $post['image_url'] = $post['image'] ? UPLOAD_URL . $post['image'] : null;
    $post['rendered_content'] = format_post_content($post['content']);

    json_response(['ok' => true, 'post' => $post]);
}

// ── DELETE POST ──────────────────────────────────────────────
if ($action === 'delete') {
    $post_id = (int)($_POST['post_id'] ?? 0);
    if (!$post_id) json_response(['error' => 'No post ID'], 400);

    $stmt = db()->prepare("SELECT * FROM posts WHERE id = ? LIMIT 1");
    $stmt->execute([$post_id]);
    $post = $stmt->fetch();

    if (!$post) json_response(['error' => 'Post not found'], 404);

    // Only author or wall owner can delete
    if ((int)$post['author_id'] !== $me['id'] && (int)$post['wall_owner_id'] !== $me['id']) {
        json_response(['error' => 'Not authorized'], 403);
    }

    // Delete image if exists
    if ($post['image'] && defined('UPLOAD_DIR')) {
        $img_path = UPLOAD_DIR . $post['image'];
        if (file_exists($img_path)) unlink($img_path);
    }

    db()->prepare("DELETE FROM posts WHERE id = ?")->execute([$post_id]);
    json_response(['ok' => true]);
}

// ── TOGGLE LIKE ──────────────────────────────────────────────
if ($action === 'like') {
    $post_id = (int)($_POST['post_id'] ?? 0);
    if (!$post_id) json_response(['error' => 'No post ID'], 400);

    // Check if already liked
    $stmt = db()->prepare("SELECT id FROM likes WHERE post_id = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$post_id, $me['id']]);
    $existing = $stmt->fetch();

    if ($existing) {
        db()->prepare("DELETE FROM likes WHERE post_id = ? AND user_id = ?")->execute([$post_id, $me['id']]);
        $liked = false;
    } else {
        db()->prepare("INSERT INTO likes (post_id, user_id) VALUES (?, ?)")->execute([$post_id, $me['id']]);
        $liked = true;
    }

    $stmt = db()->prepare("SELECT COUNT(*) FROM likes WHERE post_id = ?");
    $stmt->execute([$post_id]);
    $count = (int)$stmt->fetchColumn();

    json_response(['ok' => true, 'liked' => $liked, 'count' => $count]);
}

// ── GET COMMENTS ─────────────────────────────────────────────
if ($action === 'get_comments') {
    $post_id = (int)($_GET['post_id'] ?? 0);
    if (!$post_id) json_response(['error' => 'No post ID'], 400);

    $stmt = db()->prepare("
        SELECT c.*, u.username, u.display_name, u.avatar
        FROM comments c
        JOIN users u ON u.id = c.author_id
        WHERE c.post_id = ?
        ORDER BY c.created_at ASC
    ");
    $stmt->execute([$post_id]);
    $comments = $stmt->fetchAll();

    $out = array_map(function($c) {
        return [
            'id'           => (int)$c['id'],
            'author_id'    => (int)$c['author_id'],
            'display_name' => $c['display_name'],
            'username'     => $c['username'],
            'avatar_url'   => avatar_url($c['avatar'], 28),
            'content'      => $c['content'],
            'time_ago'     => time_ago($c['created_at']),
        ];
    }, $comments);

    json_response(['ok' => true, 'comments' => $out]);
}

// ── ADD COMMENT ──────────────────────────────────────────────
if ($action === 'add_comment') {
    $post_id = (int)($_POST['post_id'] ?? 0);
    $content = trim($_POST['content'] ?? '');

    if (!$post_id || !$content) json_response(['error' => 'Missing fields'], 400);

    $content = encode_mojis($content);

    db()->prepare("INSERT INTO comments (post_id, author_id, content) VALUES (?, ?, ?)")
       ->execute([$post_id, $me['id'], $content]);

    $comment_id = (int)db()->lastInsertId();
    $stmt = db()->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$me['id']]);
    $author = $stmt->fetch();

    json_response([
        'ok' => true,
        'comment' => [
            'id'           => $comment_id,
            'author_id'    => $me['id'],
            'display_name' => $author['display_name'],
            'username'     => $author['username'],
            'avatar_url'   => avatar_url($author['avatar'], 28),
            'content'      => $content,
            'time_ago'     => 'just now',
        ]
    ]);
}

json_response(['error' => 'Unknown action'], 400);
