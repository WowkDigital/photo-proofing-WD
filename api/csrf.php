<?php
// api/csrf.php
// Moduł ochrony przed atakami CSRF

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Generuje lub pobiera istniejący token CSRF dla bieżącej sesji.
 * @return string
 */
function get_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Generuje ukryte pole input HTML z tokenem CSRF.
 * @return string
 */
function csrf_field(): string {
    $token = htmlspecialchars(get_csrf_token(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

/**
 * Weryfikuje token CSRF przesłany w żądaniu POST lub nagłówku HTTP X-CSRF-Token.
 * @param bool $terminateOnError Czy natychmiast zakończyć działanie z kodem 403 przy błędzie
 * @return bool
 */
function verify_csrf_token(bool $terminateOnError = true): bool {
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if (empty($sessionToken)) {
        if ($terminateOnError) {
            http_response_code(403);
            die(json_encode(['success' => false, 'error' => 'Błąd CSRF: brak aktywnej sesji tokena.']));
        }
        return false;
    }

    $submittedToken = $_POST['csrf_token'] ?? '';
    if (empty($submittedToken)) {
        $submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    }

    if (!is_string($submittedToken) || !hash_equals($sessionToken, $submittedToken)) {
        if ($terminateOnError) {
            http_response_code(403);
            die(json_encode(['success' => false, 'error' => 'Nieprawidłowy token CSRF.']));
        }
        return false;
    }

    return true;
}
