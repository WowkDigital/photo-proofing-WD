<?php
// admin/settings.php
require_once 'auth.php';
require_once '../api/db.php';

$success = '';
$error = '';

// Obsługa pobierania kopii zapasowej bazy SQLite
if (isset($_GET['action']) && $_GET['action'] === 'download_db') {
    $dbFile = __DIR__ . '/../data/database.sqlite';
    if (file_exists($dbFile)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/x-sqlite3');
        header('Content-Disposition: attachment; filename="photo_proofing_backup_' . date('Y-m-d_H-i-s') . '.sqlite"');
        header('Content-Length: ' . filesize($dbFile));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        if (ob_get_level()) { ob_clean(); }
        flush();
        readfile($dbFile);
        exit;
    } else {
        $error = 'Plik bazy danych nie istnieje.';
    }
}

// Pobierz obecne ustawienia
$stmt = $pdo->query("SELECT key, value FROM settings");
$settings = [];
while ($row = $stmt->fetch()) {
    $settings[$row['key']] = $row['value'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token(true);
    try {
        $pdo->beginTransaction();
        
        $toUpdate = [
            'ALBUM_TITLE' => $_POST['album_title'],
            'CONTACT_TELEGRAM' => $_POST['contact_telegram'],
            'CONTACT_FACEBOOK' => $_POST['contact_facebook'],
            'CONTACT_SIGNAL' => $_POST['contact_signal'],
            'CONTACT_WHATSAPP' => $_POST['contact_whatsapp'],
            'CONTACT_INSTAGRAM' => $_POST['contact_instagram'],
            'TELEGRAM_BOT_ENABLED' => isset($_POST['telegram_bot_enabled']) ? '1' : '0',
            'TELEGRAM_BOT_TOKEN' => $_POST['telegram_bot_token'],
            'TELEGRAM_CHAT_ID' => $_POST['telegram_chat_id'],
        ];

        // Aktualizacja hasła jeśli podano
        if (!empty($_POST['new_password'])) {
            if (strlen($_POST['new_password']) < 6) {
                throw new Exception("Nowe hasło musi mieć co najmniej 6 znaków.");
            }
            $toUpdate['ADMIN_PASSWORD_HASH'] = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
            
            // Re-enkrypcja sejfu nowym hasłem
            require_once '../api/crypto_helper.php';
            $stmtSalt = $pdo->query("SELECT value FROM settings WHERE key = 'VAULT_SALT'");
            $vaultSalt = $stmtSalt->fetchColumn();
            
            if ($vaultSalt && isset($_SESSION['vault_key'])) {
                $oldVaultKey = hex2bin($_SESSION['vault_key']);
                $newVaultKey = VaultCrypto::deriveKey($_POST['new_password'], $vaultSalt);
                
                // Pobierz wszystkie klucze
                $stmtVault = $pdo->query("SELECT id, encrypted_data, iv, tag FROM admin_vault");
                $vaultEntries = $stmtVault->fetchAll(PDO::FETCH_ASSOC);
                
                $updateStmt = $pdo->prepare("UPDATE admin_vault SET encrypted_data = ?, iv = ?, tag = ? WHERE id = ?");
                
                foreach ($vaultEntries as $entry) {
                    $decrypted = VaultCrypto::decrypt($entry['encrypted_data'], $entry['iv'], $entry['tag'], $oldVaultKey);
                    if ($decrypted) {
                        $reEncrypted = VaultCrypto::encrypt($decrypted, $newVaultKey);
                        $updateStmt->execute([
                            $reEncrypted['encrypted'], 
                            $reEncrypted['iv'], 
                            $reEncrypted['tag'], 
                            $entry['id']
                        ]);
                    }
                }
                
                // Aktualizuj klucz w sesji
                $_SESSION['vault_key'] = bin2hex($newVaultKey);
            }
        }

        $stmt = $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)");
        foreach ($toUpdate as $k => $v) {
            $stmt->execute([$k, $v]);
            $settings[$k] = $v; // Aktualizacja lokalnej tablicy dla widoku
        }

        $pdo->commit();
        $success = 'Ustawienia zostały zapisane pomyślnie.';
        
        require_once '../api/logger.php';
        Logger::action('Zaktualizowano ustawienia systemu', array_keys($toUpdate));
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'Błąd: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ustawienia Systemu - Photo Proofing</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #131326; color: #e0e0e0; overflow-x: hidden; }
        .dashboard-header { background-color: rgba(26, 26, 46, 0.92); backdrop-filter: blur(16px); border-bottom: 1px solid #2c2c54; }
        .card { background: linear-gradient(145deg, rgba(38, 38, 72, 0.7) 0%, rgba(26, 26, 50, 0.85) 100%); backdrop-filter: blur(16px); border: 1px solid rgba(63, 63, 110, 0.7); border-radius: 1.5rem; padding: 2rem; box-shadow: 0 20px 40px -15px rgba(0, 0, 0, 0.5); }
        .input-field { background-color: #151525; border: 1px solid #3f3f6e; border-radius: 0.75rem; padding: 0.75rem 1rem; width: 100%; color: white; outline: none; transition: all 0.2s; }
        .input-field:focus { border-color: #06b6d4; box-shadow: 0 0 0 2px rgba(6, 182, 212, 0.2); }
        .btn-save { background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%); transition: all 0.2s; box-shadow: 0 4px 14px rgba(6, 182, 212, 0.25); }
        .btn-save:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(6, 182, 212, 0.45); }
        .nav-btn { background-color: #2c2c54; transition: all 0.2s; border: 1px solid #3f3f6e; }
        .nav-btn:hover { background-color: #3f3f6e; border-color: #4f4f8a; transform: translateY(-1px); }
    </style>
</head>
<body class="min-h-screen flex flex-col relative selection:bg-cyan-500 selection:text-white">
    <!-- Ambient Glow Orbs -->
    <div class="fixed -top-40 -left-40 w-96 h-96 bg-cyan-500/10 rounded-full blur-[140px] pointer-events-none"></div>
    <div class="fixed top-1/3 -right-40 w-96 h-96 bg-blue-600/10 rounded-full blur-[140px] pointer-events-none"></div>

    <!-- Unified Navbar -->
    <header class="dashboard-header sticky top-0 z-40 w-full mb-6 shadow-xl">
        <div class="container mx-auto px-4 py-3.5 flex flex-col md:flex-row justify-between items-center gap-4">
            <div class="flex items-center space-x-3.5">
                <div class="bg-gradient-to-br from-cyan-500 to-blue-600 p-2.5 rounded-xl shadow-lg shadow-cyan-500/20">
                    <i data-lucide="settings" class="w-5 h-5 text-white"></i>
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-lg font-bold text-white tracking-tight">Panel Administratora</h1>
                        <span class="bg-cyan-500/10 text-cyan-400 border border-cyan-500/20 text-[10px] font-semibold px-2 py-0.5 rounded-full">Konfiguracja</span>
                    </div>
                    <p class="text-xs text-gray-400 font-medium">Ustawienia Systemu i Galeria</p>
                </div>
            </div>
            
            <div class="flex items-center gap-2.5 flex-wrap justify-center">
                <a href="index.php" class="nav-btn text-gray-200 px-4 py-2 rounded-xl flex items-center text-xs font-semibold">
                    <i data-lucide="layout-dashboard" class="w-3.5 h-3.5 mr-1.5 text-cyan-400"></i> Albumy
                </a>

                <a href="upload.php" class="nav-btn text-gray-200 px-4 py-2 rounded-xl flex items-center text-xs font-semibold">
                    <i data-lucide="upload-cloud" class="w-3.5 h-3.5 mr-1.5 text-cyan-400"></i> Prześlij
                </a>

                <a href="diagnostics.php" class="nav-btn text-gray-200 px-4 py-2 rounded-xl flex items-center text-xs font-semibold">
                    <i data-lucide="activity" class="w-3.5 h-3.5 mr-1.5 text-green-400"></i> Diagnostyka
                </a>

                <a href="settings.php" class="btn-save text-white px-4 py-2 rounded-xl flex items-center text-xs font-semibold border border-cyan-400/30">
                    <i data-lucide="settings" class="w-3.5 h-3.5 mr-1.5"></i> Ustawienia
                </a>
                
                <a href="logout.php" class="ml-1 text-gray-400 hover:text-red-400 p-2 rounded-xl hover:bg-red-500/10 transition-colors border border-transparent hover:border-red-500/20" title="Wyloguj">
                    <i data-lucide="log-out" class="w-4 h-4"></i>
                </a>
            </div>
        </div>
    </header>

    <div class="max-w-4xl mx-auto px-4 w-full flex-grow pb-12 relative z-10">
        <!-- Sub-header -->
        <div class="flex items-center justify-between mb-6">
            <div>
                <h2 class="text-2xl font-black text-white tracking-tight">Ustawienia Systemu</h2>
                <p class="text-xs text-gray-400">Zarządzaj powiadomieniami Telegram, bezpieczeństwem Sejfu i parametrami galerii</p>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="bg-green-500/10 border border-green-500/20 text-green-400 p-4 rounded-xl mb-8 flex items-center gap-3">
                <i data-lucide="check-circle" class="w-5 h-5"></i>
                <?php echo $success; ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="bg-red-500/10 border border-red-500/20 text-red-400 p-4 rounded-xl mb-8 flex items-center gap-3">
                <i data-lucide="alert-circle" class="w-5 h-5"></i>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-6">
            <?php echo csrf_field(); ?>
            <!-- Ogólne -->
            <div class="card">
                <h2 class="text-lg font-bold text-white mb-6 flex items-center gap-2">
                    <i data-lucide="settings" class="w-5 h-5 text-cyan-400"></i> Konfiguracja Ogólna
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-2">Tytuł Galerii</label>
                        <input type="text" name="album_title" value="<?php echo htmlspecialchars($settings['ALBUM_TITLE'] ?? ''); ?>" class="input-field">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-2">Zmień Hasło Admina (zostaw puste by nie zmieniać)</label>
                        <input type="password" name="new_password" placeholder="••••••••" class="input-field">
                    </div>
                </div>
            </div>

            <!-- Linki Kontaktowe -->
            <div class="card">
                <h2 class="text-lg font-bold text-white mb-6 flex items-center gap-2">
                    <i data-lucide="share-2" class="w-5 h-5 text-cyan-400"></i> Linki Kontaktowe
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-2">Telegram URL / Nazwa</label>
                        <input type="text" name="contact_telegram" value="<?php echo htmlspecialchars($settings['CONTACT_TELEGRAM'] ?? ''); ?>" class="input-field text-sm" placeholder="https://t.me/... lub @username">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-2">WhatsApp URL / Numer</label>
                        <input type="text" name="contact_whatsapp" value="<?php echo htmlspecialchars($settings['CONTACT_WHATSAPP'] ?? ''); ?>" class="input-field text-sm" placeholder="https://wa.me/... lub +48...">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-2">Facebook URL / Profil</label>
                        <input type="text" name="contact_facebook" value="<?php echo htmlspecialchars($settings['CONTACT_FACEBOOK'] ?? ''); ?>" class="input-field text-sm" placeholder="https://facebook.com/...">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-2">Instagram URL / Profil</label>
                        <input type="text" name="contact_instagram" value="<?php echo htmlspecialchars($settings['CONTACT_INSTAGRAM'] ?? ''); ?>" class="input-field text-sm" placeholder="https://instagram.com/... lub @username">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-2">Signal URL / Numer</label>
                        <input type="text" name="contact_signal" value="<?php echo htmlspecialchars($settings['CONTACT_SIGNAL'] ?? ''); ?>" class="input-field text-sm" placeholder="https://signal.me/... lub numer">
                    </div>
                </div>
            </div>

            <!-- Bot Telegram -->
            <div class="card">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-lg font-bold text-white flex items-center gap-2">
                        <i data-lucide="bot" class="w-5 h-5 text-blue-400"></i> Powiadomienia Telegram
                    </h2>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="telegram_bot_enabled" <?php echo ($settings['TELEGRAM_BOT_ENABLED'] ?? '0') === '1' ? 'checked' : ''; ?> class="sr-only peer">
                        <div class="w-11 h-6 bg-gray-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-cyan-600"></div>
                    </label>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-2">Bot Token</label>
                        <input type="text" name="telegram_bot_token" value="<?php echo htmlspecialchars($settings['TELEGRAM_BOT_TOKEN'] ?? ''); ?>" class="input-field text-sm font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-2">Chat ID</label>
                        <input type="text" name="telegram_chat_id" value="<?php echo htmlspecialchars($settings['TELEGRAM_CHAT_ID'] ?? ''); ?>" class="input-field text-sm font-mono">
                    </div>
                </div>
            </div>

            <!-- Kopia Zapasowa Bazy Danych -->
            <div class="card bg-gradient-to-r from-[#1c1c38] to-[#25254d] border border-cyan-500/20">
                <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-bold text-white flex items-center gap-2 mb-1">
                            <i data-lucide="database" class="w-5 h-5 text-cyan-400"></i> Kopia Zapasowa Bazy Danych
                        </h2>
                        <p class="text-xs text-gray-400">Pobierz pełną kopię zapasową pliku bazy danych SQLite (albumy, zdjęcia, wybory klientów i sejf).</p>
                    </div>
                    <a href="settings.php?action=download_db" class="bg-[#3f3f6e] hover:bg-cyan-600 text-white px-5 py-3 rounded-xl transition-all shadow-md flex items-center text-sm font-semibold whitespace-nowrap border border-cyan-400/20 hover:border-cyan-400">
                        <i data-lucide="download" class="w-4 h-4 mr-2"></i> Pobierz Kopię (.sqlite)
                    </a>
                </div>
            </div>

            <button type="submit" class="btn-save w-full py-4 rounded-xl font-bold text-white shadow-lg flex items-center justify-center gap-2">
                <i data-lucide="save" class="w-5 h-5"></i>
                Zapisz Wszystkie Ustawienia
            </button>
        </form>

        <footer class="mt-12 pt-8 border-t border-[#3f3f6e] text-center text-gray-500 text-xs">
            System Zarządzania &copy; <?php echo date('Y'); ?> | Wowk Digital Premium
        </footer>
    </div>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>
