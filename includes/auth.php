<?php
// includes/auth.php — session helpers

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function current_user(): ?array {
    return $_SESSION['gb_user'] ?? null;
}

function logged_in(): bool {
    return isset($_SESSION['gb_user']);
}

function require_login(string $redirect = 'index.php'): void {
    if (!logged_in()) {
        header('Location: ' . $redirect);
        exit;
    }
}

function login_user(array $user): void {
    // Store safe subset in session (no password hash)
    $_SESSION['gb_user'] = [
        'id'           => $user['id'],
        'username'     => $user['username'],
        'display_name' => $user['display_name'],
        'avatar'       => $user['avatar'],
    ];
}

function logout_user(): void {
    $_SESSION = [];
    session_destroy();
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
