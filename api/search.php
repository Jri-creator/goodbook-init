<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!logged_in()) json_response([], 200);

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) json_response([], 200);

$like = '%' . $q . '%';
$me = current_user();

$stmt = db()->prepare("
    SELECT u.id, u.username, u.display_name, u.avatar, u.profile_public
    FROM users u
    WHERE (u.display_name LIKE ? OR u.username LIKE ?) AND u.id != ?
      AND (
        u.profile_public = 1
        OR EXISTS (
            SELECT 1 FROM friendships f
            WHERE f.status = 'accepted'
              AND ((f.requester_id = u.id AND f.addressee_id = ?)
                OR (f.addressee_id = u.id AND f.requester_id = ?))
        )
      )
    ORDER BY u.display_name ASC
    LIMIT 8
");
$stmt->execute([$like, $like, $me['id'], $me['id'], $me['id']]);
$users = $stmt->fetchAll();

$out = array_map(function($u) {
    return [
        'id'           => (int)$u['id'],
        'username'     => $u['username'],
        'display_name' => $u['display_name'],
        'avatar_url'   => avatar_url($u['avatar'], 28),
    ];
}, $users);

json_response($out);
