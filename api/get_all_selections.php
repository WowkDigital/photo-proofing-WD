<?php
// api/get_all_selections.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Content-Type: application/json');
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Wymagane uprawnienia administratora.']);
    exit;
}
require_once 'db.php';

header('Content-Type: application/json');

try {
    // Fetch all selections with album info
    $stmt = $pdo->query("
        SELECT 
            s.*, 
            a.internal_name as album_name,
            a.encryption_key_hash
        FROM selections s
        JOIN albums a ON s.album_id = a.id
        ORDER BY s.selection_date DESC
    ");
    $selections = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Optymalizacja: pobranie wszystkich zdjęć jednym zapytaniem (eliminacja N+1)
    $selectionIds = array_column($selections, 'id');
    $photosBySelection = [];
    if (!empty($selectionIds)) {
        $inPlaceholders = implode(',', array_fill(0, count($selectionIds), '?'));
        $stmtPhotos = $pdo->prepare("
            SELECT sp.selection_id, p.filename, p.original_filename 
            FROM selected_photos sp 
            JOIN photos p ON sp.photo_filename = p.filename 
            WHERE sp.selection_id IN ($inPlaceholders)
        ");
        $stmtPhotos->execute($selectionIds);
        while ($pRow = $stmtPhotos->fetch(PDO::FETCH_ASSOC)) {
            $photosBySelection[$pRow['selection_id']][] = [
                'filename' => $pRow['filename'],
                'original_filename' => $pRow['original_filename']
            ];
        }
    }

    $results = [];
    foreach ($selections as $sel) {
        $sel['photos_data'] = $photosBySelection[$sel['id']] ?? [];
        $results[] = $sel;
    }

    require_once 'logger.php';
    Logger::action('Eksportowano listę wyborów klientów');

    echo json_encode(['status' => 'success', 'data' => $results]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Błąd bazy danych: ' . $e->getMessage()]);
}
