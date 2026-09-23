<?php
// api/db.php

$dbPath = __DIR__ . '/../data/database.sqlite';

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Wymuszenie integralności referencyjnej w SQLite
    $pdo->exec('PRAGMA foreign_keys = ON;');

    // Indeksy wydajnościowe dla bazy SQLite
    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_photos_album ON photos(album_id);
        CREATE INDEX IF NOT EXISTS idx_selections_album ON selections(album_id);
        CREATE INDEX IF NOT EXISTS idx_selected_photos_sel ON selected_photos(selection_id);
    ");

    // Automatyczna migracja: sprawdzenie kolumny status w tabeli selections
    $columns = $pdo->query("PRAGMA table_info(selections)")->fetchAll();
    $hasStatus = false;
    foreach ($columns as $col) {
        if ($col['name'] === 'status') {
            $hasStatus = true;
            break;
        }
    }
    if (!$hasStatus && !empty($columns)) {
        $pdo->exec("ALTER TABLE selections ADD COLUMN status TEXT DEFAULT 'new'");
    }
} catch (PDOException $e) {
    // W środowisku produkcyjnym nie należy pokazywać dokładnego błędu użytkownikowi
    die("Błąd połączenia z bazą danych.");
}

