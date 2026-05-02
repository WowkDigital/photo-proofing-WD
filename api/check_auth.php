<?php
session_start();

// Jeśli sesja nie istnieje lub nie jest ustawiona na true, zakończ działanie
if ((!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) && 
    (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true)) {
    // Dla żądań API zwracamy błąd 403 (Forbidden)
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Brak autoryzacji.']);
    exit;
}