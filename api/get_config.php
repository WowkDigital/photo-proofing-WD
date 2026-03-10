<?php
// api/get_config.php
header('Content-Type: application/json');
require_once 'config.php';
require_once 'db.php';

$albumSlug = $_GET['s'] ?? 'default';

try {
    $stmt = $pdo->prepare("SELECT public_title FROM albums WHERE slug = ?");
    $stmt->execute([$albumSlug]);
    $title = $stmt->fetchColumn();
} catch (PDOException $e) {
    $title = ALBUM_TITLE;
}

$publicConfig = [
    'albumTitle' => $title ?: ALBUM_TITLE,
    'contactLinks' => [
        'telegram' => defined('CONTACT_TELEGRAM') ? CONTACT_TELEGRAM : '#',
        'facebook' => defined('CONTACT_FACEBOOK') ? CONTACT_FACEBOOK : '#',
        'signal' => defined('CONTACT_SIGNAL') ? CONTACT_SIGNAL : '#',
        'whatsapp' => defined('CONTACT_WHATSAPP') ? CONTACT_WHATSAPP : '#',
        'instagram' => defined('CONTACT_INSTAGRAM') ? CONTACT_INSTAGRAM : '#',
    ]
];

echo json_encode($publicConfig);
?>