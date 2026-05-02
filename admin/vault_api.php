<?php
// admin/vault_api.php
require_once 'auth.php';
require_once '../api/db.php';
require_once '../api/crypto_helper.php';

header('Content-Type: application/json');

if (!isset($_SESSION['vault_key'])) {
    echo json_encode(['success' => false, 'error' => 'Vault key not initialized in session.']);
    exit;
}

$vaultKey = hex2bin($_SESSION['vault_key']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? '';

    if ($action === 'add_key') {
        $keyHex = $data['key_hex'] ?? '';
        $keyHash = $data['key_hash'] ?? '';

        if (!$keyHex || !$keyHash) {
            echo json_encode(['success' => false, 'error' => 'Missing key data.']);
            exit;
        }

        $encrypted = VaultCrypto::encrypt($keyHex, $vaultKey);

        try {
            $stmt = $pdo->prepare("INSERT OR REPLACE INTO admin_vault (key_hash, encrypted_data, iv, tag) VALUES (?, ?, ?, ?)");
            $stmt->execute([$keyHash, $encrypted['encrypted'], $encrypted['iv'], $encrypted['tag']]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    if ($action === 'get_all') {
        try {
            $stmt = $pdo->query("SELECT key_hash, encrypted_data, iv, tag FROM admin_vault");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $results = [];

            foreach ($rows as $row) {
                $decrypted = VaultCrypto::decrypt($row['encrypted_data'], $row['iv'], $row['tag'], $vaultKey);
                if ($decrypted) {
                    $results[$row['key_hash']] = $decrypted;
                }
            }
            echo json_encode(['success' => true, 'keys' => $results]);
        } catch (PDOException $e) {
            echo json_encode(['success' => true, 'keys' => [], 'error' => $e->getMessage()]);
        }
        exit;
    }
}
