<?php
require_once 'check_auth.php'; // Ensure admin is logged in
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

    $results = [];
    foreach ($selections as $sel) {
        // Fetch selected photos for each selection with their original filenames
        $stmtPhotos = $pdo->prepare("
            SELECT p.filename, p.original_filename 
            FROM selected_photos sp 
            JOIN photos p ON sp.photo_filename = p.filename 
            WHERE sp.selection_id = ?
        ");
        $stmtPhotos->execute([$sel['id']]);
        $sel['photos_data'] = $stmtPhotos->fetchAll(PDO::FETCH_ASSOC);
        
        $results[] = $sel;
    }

    echo json_encode(['status' => 'success', 'data' => $results]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Błąd bazy danych: ' . $e->getMessage()]);
}
