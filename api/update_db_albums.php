<?php
// api/update_db_albums.php
require_once 'db.php';

try {
    // 1. Tabela albumów
    $pdo->exec("CREATE TABLE IF NOT EXISTS albums (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        slug TEXT NOT NULL UNIQUE,
        internal_name TEXT,
        public_title TEXT,
        encryption_key_hash TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // 2. Dodaj album_id do photos
    // SQLite nie wspiera prostego ADD COLUMN z kluczem obcym łatwo, ale możemy dodać kolumnę
    try {
        $pdo->exec("ALTER TABLE photos ADD COLUMN album_id INTEGER REFERENCES albums(id)");
    } catch (Exception $e) {
        // Kolumna prawdopodobnie już istnieje
    }

    // 3. Dodaj album_id do selections
    try {
        $pdo->exec("ALTER TABLE selections ADD COLUMN album_id INTEGER REFERENCES albums(id)");
    } catch (Exception $e) {
        // Kolumna prawdopodobnie już istnieje
    }

    // 4. Stwórz domyślny album jeśli nie ma żadnego
    $stmt = $pdo->query("SELECT COUNT(*) FROM albums");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO albums (slug, internal_name, public_title) VALUES ('default', 'Główny Album', 'Galeria Zdjęć')");
        $defaultId = $pdo->lastInsertId();
        
        // Przypisz istniejące zdjęcia i sesje do domyślnego albumu
        $pdo->exec("UPDATE photos SET album_id = $defaultId WHERE album_id IS NULL");
        $pdo->exec("UPDATE selections SET album_id = $defaultId WHERE album_id IS NULL");
    }

    echo "Baza danych została zaktualizowana o obsługę albumów.";

} catch (PDOException $e) {
    die("Błąd aktualizacji bazy: " . $e->getMessage());
}
?>
