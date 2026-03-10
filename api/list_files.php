<?php
// api/list_files.php
require_once 'config.php';
require_once 'db.php';

if (defined('PASSWORD_PROTECTION_ENABLED') && PASSWORD_PROTECTION_ENABLED === true) {
    require_once 'check_auth.php';
}

$albumSlug = $_GET['s'] ?? 'default';

try {
    // Pobierz ID albumu na podstawie sluga
    $stmtSlug = $pdo->prepare("SELECT id FROM albums WHERE slug = ?");
    $stmtSlug->execute([$albumSlug]);
    $albumId = $stmtSlug->fetchColumn();

    if (!$albumId) {
        header('Content-Type: application/json');
        echo json_encode([]);
        exit;
    }

    // Pobierz pliki tylko dla tego albumu
    $stmt = $pdo->prepare("SELECT filename FROM photos WHERE album_id = ? ORDER BY filename ASC");
    $stmt->execute([$albumId]);
    $files = $stmt->fetchAll(PDO::FETCH_COLUMN);

} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}

header('Content-Type: application/json');
echo json_encode(array_values($files));
?>