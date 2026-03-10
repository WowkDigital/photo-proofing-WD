<?php
// api/sync_photos.php
require_once '../admin/auth.php';
require_once 'db.php';
require_once 'config.php';

// Check auth (only admin can trigger sync via web, or CLI)
if (php_sapi_name() !== 'cli') {
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        die('Brak dostępu.');
    }
}

$photoDir = __DIR__ . '/../photos/';
$files = scandir($photoDir);

$count = 0;
$pdo->beginTransaction();

try {
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO photos (filename, original_filename) VALUES (:filename, :original_filename)");

    foreach ($files as $file) {
        if ($file === '.' || $file === '..' || is_dir($photoDir . $file)) continue;
        
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if ($ext === 'enc') {
            // Extract original filename if possible, otherwise use current name
            // Filename format: prefix_originalName.enc
            // We strip prefix (4 digits + _) and extension
            $parts = explode('_', $file, 2);
            $originalName = (count($parts) > 1) ? pathinfo($parts[1], PATHINFO_FILENAME) : pathinfo($file, PATHINFO_FILENAME);

            $stmt->execute([':filename' => $file, ':original_filename' => $originalName]);
            $count++;
        }
    }
    $pdo->commit();
    echo "Zsynchronizowano $count zdjęć.";
} catch (Exception $e) {
    $pdo->rollBack();
    echo "Błąd synchronizacji: " . $e->getMessage();
}
?>
