<?php
// api/rebuild_db.php
require_once 'db.php';

try {
    // 0. Sprawdź, czy baza jest zablokowana lub uszkodzona, próbując prostego selecta
    // Jeśli nie można połączyć, skrypt 'db.php' i tak rzuci wyjątek, ale tutaj możemy być bardziej agresywni.
    
    // 1. DROP TABLE aby wyczyścić wszystko
    $tables = ['selections', 'selected_photos', 'photos', 'albums'];
    foreach ($tables as $table) {
        $pdo->exec("DROP TABLE IF EXISTS $table");
    }

    echo "Tabele usunięte.\n";

    // 2. Odtwórz strukturę z setup_db.php (wklejone ręcznie dla pewności)
    $pdo->exec("CREATE TABLE IF NOT EXISTS photos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        filename TEXT NOT NULL UNIQUE,
        original_filename TEXT,
        upload_date DATETIME DEFAULT CURRENT_TIMESTAMP,
        album_id INTEGER
    )");

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
        ip_address TEXT,
        album_id INTEGER
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS selected_photos (
        selection_id INTEGER NOT NULL,
        photo_filename TEXT NOT NULL,
        FOREIGN KEY (selection_id) REFERENCES selections(id) ON DELETE CASCADE
    )");

    echo "Podstawowa struktura odtworzona.\n";

    // 3. Odtwórz strukturę albumów z update_db_albums.php
    $pdo->exec("CREATE TABLE IF NOT EXISTS albums (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        slug TEXT NOT NULL UNIQUE,
        internal_name TEXT,
        public_title TEXT,
        encryption_key_hash TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    echo "Struktura albumów odtworzona.\n";

    // 4. Stwórz domyślny album (resetuje stan do czystego)
    $pdo->exec("INSERT INTO albums (slug, internal_name, public_title) VALUES ('default', 'Główny Album', 'Galeria Zdjęć')");
    
    echo "Baza danych została CAŁKOWICIE zresetowana.\n";

} catch (PDOException $e) {
    die("Błąd resetowania bazy: " . $e->getMessage());
}
?>
