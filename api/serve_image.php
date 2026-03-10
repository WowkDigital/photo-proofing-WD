<?php
// Dołącz plik konfiguracyjny, aby sprawdzić, czy ochrona jest aktywna
require_once 'config.php';

// Wymagaj logowania tylko jeśli ochrona hasłem jest włączona
if (PASSWORD_PROTECTION_ENABLED === true) {
    require_once 'check_auth.php';
}

// Pobieramy nazwę pliku i typ (miniaturka/pełny) z parametrów URL
$filename = $_GET['file'] ?? null;
$type = $_GET['type'] ?? 'thumb'; // Domyślnie miniaturka

if (!$filename) {
    http_response_code(400); // Bad Request
    exit('Brak nazwy pliku.');
}

// BEZPIECZEŃSTWO: Upewnij się, że nazwa pliku nie zawiera prób wyjścia z folderu (np. ../)
// Funkcja basename() skutecznie usuwa wszelkie informacje o ścieżce
$safe_filename = basename($filename);

// Ustalanie ścieżki w zależności od typu
$base_path = dirname(__DIR__) . '/photos/'; // Ścieżka do folderu /photos
$file_path = '';

if ($type === 'thumb') {
    $file_path = $base_path . 'thumbnails/' . $safe_filename;
} elseif ($type === 'full') {
    $file_path = $base_path . $safe_filename;
} else {
    http_response_code(400);
    exit('Nieprawidłowy typ obrazu.');
}

// Sprawdź, czy plik istnieje i czy można go odczytać
if (!file_exists($file_path) || !is_readable($file_path)) {
    http_response_code(404); // Not Found
    exit('Plik nie istnieje lub brak uprawnień do odczytu.');
}

// ZMIANA: Ustawiamy uniwersalny nagłówek dla danych binarnych.
// Nie jest to już obraz, a zaszyfrowany plik, który przeglądarka
// ma pobrać jako surowe dane (strumień oktetów).
header('Content-Type: application/octet-stream');
header('Content-Length: ' . filesize($file_path));
// Poniższy nagłówek sugeruje przeglądarce, by nie indeksowała pliku
header('X-Robots-Tag: noindex, nofollow');
// Dodajemy nagłówek, który zapobiega cache'owaniu zaszyfrowanych plików przez pośredników
header('Cache-Control: no-cache, must-revalidate');

// Wyczyść bufor wyjściowy i wyślij plik do przeglądarki
ob_clean();
flush();
readfile($file_path);
exit;