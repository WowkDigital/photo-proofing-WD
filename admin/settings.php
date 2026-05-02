<?php
// admin/settings.php
require_once 'auth.php';
require_once '../api/db.php';

$success = '';
$error = '';

// Pobierz obecne ustawienia
$stmt = $pdo->query("SELECT key, value FROM settings");
$settings = [];
while ($row = $stmt->fetch()) {
    $settings[$row['key']] = $row['value'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
        }

        $stmt = $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)");
        foreach ($toUpdate as $k => $v) {
            $stmt->execute([$k, $v]);
            $settings[$k] = $v; // Aktualizacja lokalnej tablicy dla widoku
        }

        $pdo->commit();
        $success = 'Ustawienia zostały zapisane pomyślnie.';
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
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #1a1a2e; color: #e0e0e0; }
        .card { background-color: #2c2c54; border: 1px solid #3f3f6e; border-radius: 1.5rem; padding: 2rem; }
        .input-field { background-color: #1a1a2e; border: 1px solid #3f3f6e; border-radius: 0.75rem; padding: 0.75rem 1rem; width: 100%; color: white; outline: none; transition: all 0.2s; }
        .input-field:focus { border-color: #6366f1; box-shadow: 0 0 0 2px rgba(99, 102, 241, 0.2); }
        .btn-save { background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); transition: all 0.2s; }
        .btn-save:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(99, 102, 241, 0.4); }
    </style>
</head>
<body class="min-h-screen p-4 md:p-8">
    <div class="max-w-4xl mx-auto">
        <!-- Header -->
        <div class="flex items-center justify-between mb-8">
            <div class="flex items-center space-x-4">
                <a href="index.php" class="p-2 bg-[#2c2c54] rounded-lg hover:bg-[#3f3f6e] transition-colors">
                    <i data-lucide="arrow-left" class="w-6 h-6 text-gray-400"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-white">Ustawienia Systemu</h1>
                    <p class="text-sm text-gray-400">Konfiguracja galerii, kontaktów i powiadomień</p>
                </div>
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
            <!-- Ogólne -->
            <div class="card">
                <h2 class="text-lg font-bold text-white mb-6 flex items-center gap-2">
                    <i data-lucide="settings" class="w-5 h-5 text-indigo-400"></i> Konfiguracja Ogólna
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
                        <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-2">Telegram URL</label>
                        <input type="url" name="contact_telegram" value="<?php echo htmlspecialchars($settings['CONTACT_TELEGRAM'] ?? ''); ?>" class="input-field text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-2">WhatsApp URL</label>
                        <input type="url" name="contact_whatsapp" value="<?php echo htmlspecialchars($settings['CONTACT_WHATSAPP'] ?? ''); ?>" class="input-field text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-2">Facebook URL</label>
                        <input type="url" name="contact_facebook" value="<?php echo htmlspecialchars($settings['CONTACT_FACEBOOK'] ?? ''); ?>" class="input-field text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-2">Instagram URL</label>
                        <input type="url" name="contact_instagram" value="<?php echo htmlspecialchars($settings['CONTACT_INSTAGRAM'] ?? ''); ?>" class="input-field text-sm">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-2">Signal URL</label>
                        <input type="url" name="contact_signal" value="<?php echo htmlspecialchars($settings['CONTACT_SIGNAL'] ?? ''); ?>" class="input-field text-sm">
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
                        <div class="w-11 h-6 bg-gray-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
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
