<?php
// api/telegram_notify.php

require_once 'config.php';

/**
 * Wysyła powiadomienie do bota Telegram o nowym wyborze zdjęć.
 * 
 * @param string $albumName Nazwa albumu
 * @param object $clientData Dane klienta (name, email, phone, instagram, telegram, facebook, notes)
 * @param array $selectedFiles Lista wybranych nazw plików
 * @return bool True jeśli wysłano (lub funkcja wyłączona), False w przypadku błędu
 */
function sendTelegramNotification($albumName, $clientData, $selectedFiles) {
    if (!defined('TELEGRAM_BOT_ENABLED') || !TELEGRAM_BOT_ENABLED) {
        return true;
    }

    if (!defined('TELEGRAM_BOT_TOKEN') || empty(TELEGRAM_BOT_TOKEN) || 
        !defined('TELEGRAM_CHAT_ID') || empty(TELEGRAM_CHAT_ID)) {
        error_log("Telegram bot enabled but token or chat_id is missing.");
        return false;
    }

    $msg = "📸 *Nowy wybór zdjęć!*\n";
    $msg .= "----------------------------\n";
    $msg .= "📂 *Album:* " . $albumName . "\n";
    $msg .= "👤 *Klient:* " . ($clientData->name ?? 'Brak') . "\n";
    $msg .= "📧 *Email:* " . ($clientData->email ?? 'Brak') . "\n";
    $msg .= "📞 *Tel:* " . ($clientData->phone ?? 'Brak') . "\n";
    
    if (!empty($clientData->instagram) || !empty($clientData->telegram) || !empty($clientData->facebook)) {
        $msg .= "🔗 *Sociale:* ";
        if (!empty($clientData->instagram)) $msg .= "IG: @" . ltrim($clientData->instagram, '@') . " ";
        if (!empty($clientData->telegram)) $msg .= "TG: @" . ltrim($clientData->telegram, '@') . " ";
        if (!empty($clientData->facebook)) $msg .= "FB: " . $clientData->facebook . " ";
        $msg .= "\n";
    }

    if (!empty($clientData->notes)) {
        $msg .= "📝 *Notatki:* " . $clientData->notes . "\n";
    }

    $msg .= "🖼️ *Liczba zdjęć:* " . count($selectedFiles) . "\n\n";
    $msg .= "*Lista plików:*\n";
    
    // Ograniczamy listę plików w jednej wiadomości (Telegram ma limit 4096 znaków)
    $maxFiles = 100; // Rozsądny limit początkowy
    $currentCount = 0;
    foreach ($selectedFiles as $file) {
        $line = "`" . $file . "`\n";
        // Sprawdzamy czy dodanie kolejnej linii nie przekroczy limitu (z marginesem)
        if (mb_strlen($msg . $line) > 3900) {
            $msg .= "... i " . (count($selectedFiles) - $currentCount) . " więcej plików.";
            break;
        }
        $msg .= $line;
        $currentCount++;
    }

    $url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendMessage";
    $payload = [
        'chat_id' => TELEGRAM_CHAT_ID,
        'text' => $msg,
        'parse_mode' => 'Markdown'
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        error_log("Telegram API Error: " . $result);
        return false;
    }

    return true;
}
