<?php
// api/db.php

$dbPath = __DIR__ . '/../data/database.sqlite';

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // W środowisku produkcyjnym nie należy pokazywać dokładnego błędu użytkownikowi
    die("Błąd połączenia z bazą danych.");
}
?>
