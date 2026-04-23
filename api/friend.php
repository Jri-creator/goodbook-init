<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!logged_in()) json_response(['error' => 'Not logged in'], 401);

$me     = current_user();
$action  = $_POST['action'] ?? '';
$user_id = (int)($_POST['user_id'] ?? 0);

if (!$user_id || $user_id === $me['id']) {
    json_response(['error' => 'Invalid user ID'], 400);
}

// Verify target user exists
$stmt = db()->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$user_id]);
if (!$stmt->fetch()) json_response(['error' => 'User not found'], 404);

switch ($action) {

    case 'request':
        // Check not already friends or pending
        $stmt = db()->prepare("
            SELECT id, status FROM friendships
            WHERE (requester_id=? AND addressee_id=?) OR (requester_id=? AND addressee_id=?)
            LIMIT 1
        ");
        $stmt->execute([$me['id'], $user_id, $user_id, $me['id']]);
        $existing = $stmt->fetch();

        if ($existing) {
            json_response(['error' => 'Relationship already exists', 'status' => $existing['status']], 409);
        }

        db()->prepare("INSERT INTO friendships (requester_id, addressee_id, status) VALUES (?, ?, 'pending')")
           ->execute([$me['id'], $user_id]);
        json_response(['ok' => true]);
        break;

    case 'accept':
        $stmt = db()->prepare("
            UPDATE friendships SET status='accepted'
            WHERE requester_id=? AND addressee_id=? AND status='pending'
        ");
        $stmt->execute([$user_id, $me['id']]);
        if ($stmt->rowCount() === 0) json_response(['error' => 'Request not found'], 404);
        json_response(['ok' => true]);
        break;

    case 'decline':
        db()->prepare("
            DELETE FROM friendships
            WHERE requester_id=? AND addressee_id=? AND status='pending'
        ")->execute([$user_id, $me['id']]);
        json_response(['ok' => true]);
        break;

    case 'remove':
        db()->prepare("
            DELETE FROM friendships
            WHERE (requester_id=? AND addressee_id=?) OR (requester_id=? AND addressee_id=?)
        ")->execute([$me['id'], $user_id, $user_id, $me['id']]);
        json_response(['ok' => true]);
        break;

    default:
        json_response(['error' => 'Unknown action'], 400);
}
