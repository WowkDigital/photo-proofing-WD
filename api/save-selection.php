<?php
// api/save-selection.php
require_once 'config.php';
require_once 'db.php';
require_once 'telegram_notify.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Niedozwolona metoda.']);
    exit;
}

$json_data = file_get_contents('php://input');
$data = json_decode($json_data);

if ($data === null || !isset($data->selectedFiles) || !isset($data->clientData)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Nieprawidłowe dane.']);
    exit;
}

$albumSlug = $_GET['s'] ?? 'default';
$stmtAlbum = $pdo->prepare("SELECT id, internal_name FROM albums WHERE slug = ?");
$stmtAlbum->execute([$albumSlug]);
$albumRow = $stmtAlbum->fetch();
$albumId = $albumRow['id'] ?? null;
$albumName = $albumRow['internal_name'] ?? 'Nieznany album';

if (!$albumId) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Album nie istnieje.']);
    exit;
}

// Logowanie do pliku (Backup)
$log_directory = dirname(__DIR__) . '/selection_logs/';
if (!is_dir($log_directory)) mkdir($log_directory, 0755, true);
$log_file = $log_directory . 'selections.log';

$clientData = $data->clientData;
$selectedFiles = $data->selectedFiles;

// Sanityzacja dla logu tekstowego
$name = htmlspecialchars($clientData->name ?? 'Nie podano', ENT_QUOTES, 'UTF-8');
// ... reszta logiki logowania do pliku (skrócona na potrzeby tego narzędzia, ale w produkcji warto zachować)
// Zapiszmy chociaż podstawy do logu
$log_entry = date('Y-m-d H:i:s') . " - Klient: $name - Plików: " . count($selectedFiles) . "\n";
file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);


// Zapis do bazy danych
try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("INSERT INTO selections (
        client_name, client_email, client_phone, client_instagram, client_telegram, client_facebook, client_notes, ip_address, album_id
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $stmt->execute([
        $clientData->name ?? '',
        $clientData->email ?? '',
        $clientData->phone ?? '',
        $clientData->instagram ?? '',
        $clientData->telegram ?? '',
        $clientData->facebook ?? '',
        $clientData->notes ?? '',
        $_SERVER['REMOTE_ADDR'],
        $albumId
    ]);

    $selectionId = $pdo->lastInsertId();

    $stmtPhoto = $pdo->prepare("INSERT INTO selected_photos (selection_id, photo_filename) VALUES (?, ?)");

    foreach ($selectedFiles as $filename) {
        $stmtPhoto->execute([$selectionId, $filename]);
    }

    $pdo->commit();

    // Powiadomienie Telegram (wydzielone do funkcji)
    $originalFiles = $data->originalFiles ?? $selectedFiles;
    sendTelegramNotification($albumName, $clientData, $originalFiles);

    echo json_encode(['status' => 'success', 'message' => 'Wybór został zapisany w bazie.']);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Błąd bazy danych: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Wystąpił błąd podczas zapisu do bazy.']);
}
