<?php
// includes/db.php — PDO connection singleton

if (!defined('SETUP_COMPLETE') && !defined('GOODBOOK_SETUP')) {
    if (!file_exists(__DIR__ . '/../config.php')) {
        header('Location: setup.php');
        exit;
    }
}

if (file_exists(__DIR__ . '/../config.php') && !defined('DB_HOST')) {
    require_once __DIR__ . '/../config.php';
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
        // Store all datetimes as UTC so client-side JS can convert correctly
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}
