<?php
// admin/auth.php
// Naprawa braku ukośnika na końcu URL (ważne dla linków relatywnych)
if (strpos($_SERVER['REQUEST_URI'], '/admin') !== false && strpos($_SERVER['REQUEST_URI'], '/admin/') === false && empty($_SERVER['QUERY_STRING'])) {
    header("Location: " . $_SERVER['REQUEST_URI'] . "/");
    exit;
}
session_start();
require_once '../api/config.php';

if (!defined('ADMIN_PASSWORD_HASH')) {
    die("Brak zdefiniowanego hasła administratora w config.php");
}

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}
?>
