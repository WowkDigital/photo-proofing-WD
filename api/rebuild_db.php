<?php
// api/rebuild_db.php
require_once '../admin/auth.php';
require_once 'db.php';

// Zabezpieczenie przed przypadkowym wywołaniem: wymaga jawnego żądania POST z potwierdzeniem
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['confirm_reset'])) {
    http_response_code(403);
    die("Dostęp zablokowany: skrypt resetowania bazy wymaga jawnego potwierdzenia metodą POST.");
}
verify_csrf_token(true);

// Bezpieczne odtworzenie bazy na podstawie pełnego skryptu init_db.php
require_once 'init_db.php';

