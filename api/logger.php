<?php
// api/logger.php

class Logger {
    public static function log($type, $message, $details = null) {
        global $pdo;
        
        // Jeśli $pdo nie jest zdefiniowane, spróbuj je załadować
        if (!isset($pdo)) {
            require_once __DIR__ . '/db.php';
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO logs (event_type, message, details, ip_address) VALUES (?, ?, ?, ?)");
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $detailsJson = $details ? (is_string($details) ? $details : json_encode($details)) : null;
            $stmt->execute([$type, $message, $detailsJson, $ip]);
        } catch (Exception $e) {
            // Ciche niepowodzenie logowania, aby nie przerywać działania aplikacji
            error_log("Logging error: " . $e->getMessage());
        }
    }

    // Skróty dla typowych zdarzeń
    public static function info($msg, $details = null) { self::log('INFO', $msg, $details); }
    public static function warn($msg, $details = null) { self::log('WARNING', $msg, $details); }
    public static function error($msg, $details = null) { self::log('ERROR', $msg, $details); }
    public static function auth($msg, $details = null) { self::log('AUTH', $msg, $details); }
    public static function action($msg, $details = null) { self::log('ACTION', $msg, $details); }
}
