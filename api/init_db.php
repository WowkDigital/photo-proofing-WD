<?php
// api/init_db.php
require_once '../admin/auth.php';
require_once 'db.php';

echo "Rozpoczynanie inicjalizacji bazy danych...\n";

try {
    $pdo->beginTransaction();

    // 1. Tabela albumów
    $pdo->exec("CREATE TABLE IF NOT EXISTS albums (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        slug TEXT NOT NULL UNIQUE,
        internal_name TEXT,
        public_title TEXT,
        encryption_key_hash TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    echo "- Tabela 'albums' gotowa.\n";

    // 2. Tabela zdjęć
    $pdo->exec("CREATE TABLE IF NOT EXISTS photos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        filename TEXT NOT NULL UNIQUE,
        original_filename TEXT,
        upload_date DATETIME DEFAULT CURRENT_TIMESTAMP,
        album_id INTEGER REFERENCES albums(id) ON DELETE SET NULL
    )");
    echo "- Tabela 'photos' gotowa.\n";

    // 3. Tabela wyborów klientów (sesji)
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
        album_id INTEGER REFERENCES albums(id) ON DELETE SET NULL
    )");
    echo "- Tabela 'selections' gotowa.\n";

    // 4. Tabela łącząca wybory ze zdjęciami
    $pdo->exec("CREATE TABLE IF NOT EXISTS selected_photos (
        selection_id INTEGER NOT NULL,
        photo_filename TEXT NOT NULL,
        FOREIGN KEY (selection_id) REFERENCES selections(id) ON DELETE CASCADE
    )");
    echo "- Tabela 'selected_photos' gotowa.\n";

    // 5. Tabela Sejfu Kluczy (Nowość)
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_vault (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        key_hash TEXT NOT NULL UNIQUE,
        encrypted_data TEXT NOT NULL,
        iv TEXT NOT NULL,
        tag TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    echo "- Tabela 'admin_vault' gotowa.\n";

    // 6. Tabela ustawień (jeśli nie istnieje)
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT
    )");
    echo "- Tabela 'settings' gotowa.\n";

    // 7. Dodanie domyślnego albumu
    $stmt = $pdo->query("SELECT COUNT(*) FROM albums");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO albums (slug, internal_name, public_title) VALUES ('default', 'Główny Album', 'Galeria Zdjęć')");
        echo "- Domyślny album został utworzony.\n";
    }

    // 8. Inicjalizacja soli dla sejfu
    $stmtSalt = $pdo->prepare("SELECT value FROM settings WHERE key = 'VAULT_SALT'");
    $stmtSalt->execute();
    if (!$stmtSalt->fetch()) {
        $salt = bin2hex(random_bytes(16));
        $pdo->prepare("INSERT INTO settings (key, value) VALUES ('VAULT_SALT', ?)")->execute([$salt]);
        echo "- Sól sejfu została wygenerowana.\n";
    }

    $pdo->commit();
    echo "\nSUKCES: Baza danych została w pełni zainicjalizowana.\n";

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    die("\nBŁĄD INICJALIZACJI: " . $e->getMessage() . "\n");
}
