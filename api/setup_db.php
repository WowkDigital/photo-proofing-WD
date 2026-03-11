<?php
// api/setup_db.php
require_once '../admin/auth.php';
require_once 'db.php';

try {
    // Tabela zdjęć
    $pdo->exec("CREATE TABLE IF NOT EXISTS photos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        filename TEXT NOT NULL UNIQUE,
        original_filename TEXT,
        upload_date DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Tabela wyborów klientów (sesji)
    $pdo->exec("CREATE TABLE IF NOT EXISTS selections (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        client_name TEXT NOT NULL,
        client_email TEXT,
        client_phone TEXT,
        client_instagram TEXT,
        client_telegram TEXT,
        client_facebook TEXT,
        client_notes TEXT,
        selection_date DATETIME DEFAULT CURRENT_TIMESTAMP,
        ip_address TEXT
    )");

    // Tabela łącząca wybory ze zdjęciami (Many-to-Many)
    // Przechowujemy filename, bo zdjęcia mogą być usunięte z bazy, ale w historii chcemy wiedzieć co to było
    // Albo photo_id. Lepiej photo_id + CASCADE.
    // Ale w tym systemie pliki są nazywane hashem/prefixem. 
    $pdo->exec("CREATE TABLE IF NOT EXISTS selected_photos (
        selection_id INTEGER NOT NULL,
        photo_filename TEXT NOT NULL,
        FOREIGN KEY (selection_id) REFERENCES selections(id) ON DELETE CASCADE
    )");

    echo "Baza danych została zainicjalizowana pomyślnie.";

} catch (PDOException $e) {
    die("Błąd inicjalizacji bazy: " . $e->getMessage());
}
