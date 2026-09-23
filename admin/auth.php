<?php
// admin/auth.php
// Naprawa braku ukośnika na końcu URL (ważne dla linków relatywnych)
if (strpos($_SERVER['REQUEST_URI'], '/admin') !== false && strpos($_SERVER['REQUEST_URI'], '/admin/') === false && empty($_SERVER['QUERY_STRING'])) {
    header("Location: " . $_SERVER['REQUEST_URI'] . "/");
    exit;
}
// Bezpieczne parametry ciasteczek sesyjnych
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    ]);
    session_start();
}
require_once __DIR__ . '/../api/csrf.php';

if (!file_exists(__DIR__ . '/../api/config.php')) {
    header('Location: ../install.php');
    exit;
}
require_once '../api/config.php';

if (!defined('ADMIN_PASSWORD_HASH')) {
    die("Brak zdefiniowanego hasła administratora w config.php");
}

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}
?>
