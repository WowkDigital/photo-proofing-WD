<?php
// admin/diagnostics.php
require_once 'auth.php';
require_once '../api/db.php';
require_once '../api/telegram_notify.php';
// api/config.php is already required in telegram_notify.php or we can require it explicitly
if (file_exists('../api/config.php')) {
    require_once '../api/config.php';
}

// Obsługa żądań AJAX dla testów
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];
    
    if ($action === 'test_db_write') {
        try {
            $testName = "DIAG_TEST_" . time();
            $stmt = $pdo->prepare("INSERT INTO selections (client_name, client_notes) VALUES (?, ?)");
            $stmt->execute([$testName, 'Automatyczny test diagnostyczny']);
            $id = $pdo->lastInsertId();
            
            // Sprawdź czy zapisało
            $check = $pdo->prepare("SELECT id FROM selections WHERE id = ?");
            $check->execute([$id]);
            $found = $check->fetch();
            
            if (!$found) throw new Exception("Nie udało się odnaleźć zapisanego rekordu.");

            // Usuń testowy rekord
            $pdo->prepare("DELETE FROM selections WHERE id = ?")->execute([$id]);
            
            echo json_encode(['status' => 'success', 'message' => 'Baza danych: Zapis, odczyt i usuwanie działają poprawnie.']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'Błąd bazy danych: ' . $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'test_telegram') {
        if (!defined('TELEGRAM_BOT_ENABLED') || !TELEGRAM_BOT_ENABLED) {
            echo json_encode(['status' => 'error', 'message' => 'Bot Telegram jest wyłączony w pliku config.php.']);
            exit;
        }
        
        $clientData = (object)[
            'name' => 'Tester Diagnostyczny',
            'email' => 'test@example.com',
            'phone' => '000-000-000',
            'notes' => 'To jest wiadomość testowa wygenerowana z panelu diagnostyki aplikacji.'
        ];
        $selectedFiles = ['diag_test_01.jpg', 'diag_test_02.jpg'];
        
        $result = sendTelegramNotification('Album Diagnostyczny', $clientData, $selectedFiles);
        
        if ($result) {
            echo json_encode(['status' => 'success', 'message' => 'Wiadomość testowa została wysłana pomyślnie. Sprawdź swój komunikator Telegram.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Błąd wysyłania. Sprawdź poprawność TELEGRAM_BOT_TOKEN oraz TELEGRAM_CHAT_ID w config.php.']);
        }
        exit;
    }
}

// Wstępne sprawdzenie środowiska
$phpVersion = PHP_VERSION;
$sqliteAvailable = extension_loaded('pdo_sqlite');
$curlAvailable = extension_loaded('curl');
$gdAvailable = extension_loaded('gd');
$dataDirWritable = is_writable('../data');
$photosDirWritable = is_writable('../photos');
$thumbsDirWritable = is_writable('../photos/thumbnails');
$dbExists = file_exists(__DIR__ . '/../data/database.sqlite');
$configExists = file_exists(__DIR__ . '/../api/config.php');

?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Diagnostyka Systemu - Photo Proofing</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #1a1a2e; color: #e0e0e0; }
        .card { background-color: #2c2c54; border: 1px solid #3f3f6e; border-radius: 1rem; padding: 1.5rem; }
        .status-ok { color: #4ade80; }
        .status-error { color: #f87171; }
        .status-warning { color: #fbbf24; }
        .btn-action { background-color: #3f3f6e; transition: all 0.2s; }
        .btn-action:hover { background-color: #4f4f8a; transform: translateY(-1px); }
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
                    <h1 class="text-2xl font-bold text-white">Centrum Diagnostyczne</h1>
                    <p class="text-sm text-gray-400">Status i testy sprawności aplikacji</p>
                </div>
            </div>
            <div class="bg-blue-500/10 px-4 py-2 rounded-xl border border-blue-500/20">
                <span class="text-blue-400 text-xs font-bold uppercase tracking-widest">System v2.0</span>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Environment -->
            <div class="card">
                <div class="flex items-center space-x-3 mb-6">
                    <i data-lucide="server" class="w-5 h-5 text-cyan-400"></i>
                    <h2 class="text-lg font-semibold text-white">Środowisko Serwera</h2>
                </div>
                <ul class="space-y-4 text-sm">
                    <li class="flex justify-between items-center">
                        <span class="text-gray-400">Wersja PHP</span>
                        <span class="font-mono text-white"><?php echo $phpVersion; ?></span>
                    </li>
                    <li class="flex justify-between items-center">
                        <span class="text-gray-400">Rozszerzenie PDO SQLite</span>
                        <?php echo $sqliteAvailable ? '<i data-lucide="check-circle-2" class="w-4 h-4 status-ok"></i>' : '<i data-lucide="x-circle" class="w-4 h-4 status-error"></i>'; ?>
                    </li>
                    <li class="flex justify-between items-center">
                        <span class="text-gray-400">Rozszerzenie cURL (Telegram)</span>
                        <?php echo $curlAvailable ? '<i data-lucide="check-circle-2" class="w-4 h-4 status-ok"></i>' : '<i data-lucide="x-circle" class="w-4 h-4 status-error"></i>'; ?>
                    </li>
                    <li class="flex justify-between items-center">
                        <span class="text-gray-400">Rozszerzenie GD (Miniaturki)</span>
                        <?php echo $gdAvailable ? '<i data-lucide="check-circle-2" class="w-4 h-4 status-ok"></i>' : '<i data-lucide="x-circle" class="w-4 h-4 status-error"></i>'; ?>
                    </li>
                </ul>
            </div>

            <!-- Configuration & Files -->
            <div class="card">
                <div class="flex items-center space-x-3 mb-6">
                    <i data-lucide="settings" class="w-5 h-5 text-purple-400"></i>
                    <h2 class="text-lg font-semibold text-white">Konfiguracja i Pliki</h2>
                </div>
                <ul class="space-y-4 text-sm">
                    <li class="flex justify-between items-center">
                        <span class="text-gray-400">Plik api/config.php</span>
                        <?php echo $configExists ? '<span class="status-ok">Istnieje</span>' : '<span class="status-error">Brak (użyj .example)</span>'; ?>
                    </li>
                    <li class="flex justify-between items-center">
                        <span class="text-gray-400">Baza danych (SQLite)</span>
                        <?php echo $dbExists ? '<span class="status-ok">Połączono</span>' : '<span class="status-error">Brak pliku bazy</span>'; ?>
                    </li>
                    <li class="flex justify-between items-center">
                        <span class="text-gray-400">Folder data/ (Zapis)</span>
                        <?php echo $dataDirWritable ? '<i data-lucide="check-circle-2" class="w-4 h-4 status-ok"></i>' : '<i data-lucide="x-circle" class="w-4 h-4 status-error"></i>'; ?>
                    </li>
                    <li class="flex justify-between items-center">
                        <span class="text-gray-400">Folder photos/ (Zapis)</span>
                        <?php echo $photosDirWritable ? '<i data-lucide="check-circle-2" class="w-4 h-4 status-ok"></i>' : '<i data-lucide="x-circle" class="w-4 h-4 status-error"></i>'; ?>
                    </li>
                </ul>
            </div>

            <!-- Database Tests -->
            <div class="card md:col-span-1">
                <div class="flex items-center space-x-3 mb-6">
                    <i data-lucide="database" class="w-5 h-5 text-green-400"></i>
                    <h2 class="text-lg font-semibold text-white">Test Bazy Danych</h2>
                </div>
                <p class="text-xs text-gray-400 mb-4">Weryfikacja możliwości zapisu, odczytu i usuwania rekordów w tabeli 'selections'.</p>
                <button onclick="runTest('db_write', this)" class="btn-action w-full py-3 rounded-xl flex items-center justify-center space-x-2 text-sm font-semibold">
                    <i data-lucide="play" class="w-4 h-4"></i>
                    <span>Uruchom test zapisu</span>
                </button>
                <div id="res-db_write" class="mt-4 p-3 rounded-lg text-xs hidden"></div>
            </div>

            <!-- Telegram Tests -->
            <div class="card md:col-span-1">
                <div class="flex items-center space-x-3 mb-6">
                    <i data-lucide="send" class="w-5 h-5 text-blue-400"></i>
                    <h2 class="text-lg font-semibold text-white">Test Powiadomień</h2>
                </div>
                <div class="mb-4">
                    <div class="flex justify-between text-xs mb-2">
                        <span class="text-gray-400">Status powiadomień:</span>
                        <span class="font-bold <?php echo defined('TELEGRAM_BOT_ENABLED') && TELEGRAM_BOT_ENABLED ? 'text-green-400' : 'text-gray-500'; ?>">
                            <?php echo defined('TELEGRAM_BOT_ENABLED') && TELEGRAM_BOT_ENABLED ? 'AKTYWNE' : 'WYŁĄCZONE'; ?>
                        </span>
                    </div>
                </div>
                <button onclick="runTest('telegram', this)" <?php echo !defined('TELEGRAM_BOT_ENABLED') || !TELEGRAM_BOT_ENABLED ? 'disabled opacity-50' : ''; ?> class="btn-action w-full py-3 rounded-xl flex items-center justify-center space-x-2 text-sm font-semibold disabled:cursor-not-allowed">
                    <i data-lucide="bot" class="w-4 h-4"></i>
                    <span>Wyślij testowy Telegram</span>
                </button>
                <div id="res-telegram" class="mt-4 p-3 rounded-lg text-xs hidden"></div>
            </div>
        </div>

        <footer class="mt-12 pt-8 border-t border-[#3f3f6e] text-center text-gray-500 text-xs">
            System Diagnostyki Aplikacji &copy; <?php echo date('Y'); ?> | Wowk Digital
        </footer>
    </div>

    <script>
        lucide.createIcons();

        async function runTest(type, btn) {
            const resultDiv = document.getElementById(`res-${type}`);
            const originalContent = btn.innerHTML;
            
            // UI Update
            btn.disabled = true;
            btn.innerHTML = '<i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i> <span>Testowanie...</span>';
            lucide.createIcons();
            
            resultDiv.classList.add('hidden');
            resultDiv.className = 'mt-4 p-3 rounded-lg text-xs';

            try {
                const response = await fetch(`diagnostics.php?action=test_${type}`);
                const data = await response.json();
                
                resultDiv.classList.remove('hidden');
                resultDiv.textContent = data.message;
                
                if (data.status === 'success') {
                    resultDiv.classList.add('bg-green-500/10', 'text-green-400', 'border', 'border-green-500/20');
                } else {
                    resultDiv.classList.add('bg-red-500/10', 'text-red-400', 'border', 'border-red-500/20');
                }
            } catch (error) {
                resultDiv.classList.remove('hidden');
                resultDiv.textContent = "Błąd krytyczny: " + error.message;
                resultDiv.classList.add('bg-red-500/10', 'text-red-400', 'border', 'border-red-500/20');
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalContent;
                lucide.createIcons();
            }
        }
    </script>
</body>
</html>
