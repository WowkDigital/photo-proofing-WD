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
    
    if ($action === 'test_upload') {
        try {
            $testFile = "../photos/diag_test_" . time() . ".jpg";
            $testThumb = "../photos/thumbnails/diag_test_" . time() . ".jpg";
            
            // 1. Sprawdzenie uprawnień folderów
            if (!is_dir("../photos")) mkdir("../photos", 0755, true);
            if (!is_dir("../photos/thumbnails")) mkdir("../photos/thumbnails", 0755, true);
            
            if (!is_writable("../photos")) throw new Exception("Folder photos/ nie jest zapisywalny.");
            if (!is_writable("../photos/thumbnails")) throw new Exception("Folder photos/thumbnails/ nie jest zapisywalny.");
            
            // 2. Próba wygenerowania testowego obrazu (test GD)
            if (extension_loaded('gd')) {
                $im = imagecreatetruecolor(200, 200);
                $bg = imagecolorallocate($im, 26, 26, 46);
                imagefill($im, 0, 0, $bg);
                $textColor = imagecolorallocate($im, 74, 222, 128);
                imagestring($im, 5, 50, 90, "UPLOAD TEST OK", $textColor);
                
                if (!imagejpeg($im, $testFile, 80)) throw new Exception("Nie udało się zapisać pliku JPEG w photos/.");
                if (!imagejpeg($im, $testThumb, 50)) {
                    @unlink($testFile);
                    throw new Exception("Nie udało się zapisać miniatury w photos/thumbnails/.");
                }
                imagedestroy($im);
            } else {
                // Fallback jeśli brak GD
                if (file_put_contents($testFile, "test_data") === false) throw new Exception("Nie udało się zapisać pliku w photos/.");
                if (file_put_contents($testThumb, "test_data") === false) {
                    @unlink($testFile);
                    throw new Exception("Nie udało się zapisać pliku w photos/thumbnails/.");
                }
            }
            
            // 3. Weryfikacja istnienia
            if (!file_exists($testFile) || !file_exists($testThumb)) throw new Exception("Pliki testowe nie zostały odnalezione po zapisie.");
            
            // 4. Sprzątanie
            @unlink($testFile);
            @unlink($testThumb);
            
            echo json_encode(['status' => 'success', 'message' => 'System plików: Tworzenie plików, folderów oraz przetwarzanie obrazów działa poprawnie.']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'Błąd testu przesyłania: ' . $e->getMessage()]);
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
            'notes' => 'To jest wiadomość testowa wygenerowana z panelu diagnostyki aplikacji. Jeśli ją widzisz, bot działa poprawnie!'
        ];
        $selectedFiles = ['test_zdjecie_01.jpg', 'test_zdjecie_02.jpg'];
        
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

$maxUpload = ini_get('upload_max_filesize');
$maxPost = ini_get('post_max_size');
$memoryLimit = ini_get('memory_limit');

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
        .card { background-color: #2c2c54; border: 1px solid #3f3f6e; border-radius: 1.5rem; padding: 1.5rem; transition: all 0.3s ease; }
        .card:hover { border-color: #4f4f8a; }
        .status-ok { color: #4ade80; }
        .status-error { color: #f87171; }
        .status-warning { color: #fbbf24; }
        .btn-action { background-color: #3f3f6e; transition: all 0.2s; border: 1px solid transparent; }
        .btn-action:hover:not(:disabled) { background-color: #4f4f8a; transform: translateY(-2px); border-color: rgba(255,255,255,0.1); }
        .btn-action:active:not(:disabled) { transform: translateY(0); }
        .glass-stat { background: rgba(255, 255, 255, 0.03); border-radius: 1rem; padding: 1rem; border: 1px solid rgba(255, 255, 255, 0.05); }
    </style>
</head>
<body class="min-h-screen p-4 md:p-8 bg-gradient-to-br from-[#1a1a2e] to-[#16213e]">
    <div class="max-w-5xl mx-auto">
        <!-- Header -->
        <div class="flex flex-col md:flex-row items-center justify-between mb-10 gap-6">
            <div class="flex items-center space-x-5">
                <a href="index.php" class="p-3 bg-[#2c2c54] rounded-2xl hover:bg-[#3f3f6e] transition-all shadow-lg group">
                    <i data-lucide="arrow-left" class="w-6 h-6 text-gray-400 group-hover:text-white transition-colors"></i>
                </a>
                <div>
                    <h1 class="text-3xl font-bold text-white tracking-tight">Centrum Diagnostyczne</h1>
                    <p class="text-gray-400 font-medium">Monitoring sprawności i integralności systemu</p>
                </div>
            </div>
            <div class="flex gap-3">
                <div class="bg-cyan-500/10 px-5 py-2.5 rounded-2xl border border-cyan-500/20 flex items-center">
                    <div class="w-2 h-2 rounded-full bg-cyan-400 animate-pulse mr-3"></div>
                    <span class="text-cyan-400 text-xs font-bold uppercase tracking-widest">System Online</span>
                </div>
            </div>
        </div>

        <!-- Quick Stats -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
            <div class="glass-stat">
                <p class="text-[10px] text-gray-500 uppercase font-bold mb-1">Wersja PHP</p>
                <p class="text-lg font-bold text-white"><?php echo $phpVersion; ?></p>
            </div>
            <div class="glass-stat">
                <p class="text-[10px] text-gray-500 uppercase font-bold mb-1">Max Upload</p>
                <p class="text-lg font-bold text-cyan-400"><?php echo $maxUpload; ?></p>
            </div>
            <div class="glass-stat">
                <p class="text-[10px] text-gray-500 uppercase font-bold mb-1">Post Max</p>
                <p class="text-lg font-bold text-purple-400"><?php echo $maxPost; ?></p>
            </div>
            <div class="glass-stat">
                <p class="text-[10px] text-gray-500 uppercase font-bold mb-1">Limit Pamięci</p>
                <p class="text-lg font-bold text-orange-400"><?php echo $memoryLimit; ?></p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Environment -->
            <div class="card lg:col-span-1">
                <div class="flex items-center space-x-3 mb-6">
                    <div class="p-2 bg-cyan-500/20 rounded-lg">
                        <i data-lucide="server" class="w-5 h-5 text-cyan-400"></i>
                    </div>
                    <h2 class="text-lg font-semibold text-white">Środowisko</h2>
                </div>
                <ul class="space-y-4 text-sm">
                    <li class="flex justify-between items-center p-2 rounded-lg hover:bg-white/5 transition-colors">
                        <span class="text-gray-400">PDO SQLite</span>
                        <?php echo $sqliteAvailable ? '<i data-lucide="check-circle-2" class="w-5 h-5 status-ok"></i>' : '<i data-lucide="x-circle" class="w-5 h-5 status-error"></i>'; ?>
                    </li>
                    <li class="flex justify-between items-center p-2 rounded-lg hover:bg-white/5 transition-colors">
                        <span class="text-gray-400">cURL (Telegram)</span>
                        <?php echo $curlAvailable ? '<i data-lucide="check-circle-2" class="w-5 h-5 status-ok"></i>' : '<i data-lucide="x-circle" class="w-5 h-5 status-error"></i>'; ?>
                    </li>
                    <li class="flex justify-between items-center p-2 rounded-lg hover:bg-white/5 transition-colors">
                        <span class="text-gray-400">GD (Obrazy)</span>
                        <?php echo $gdAvailable ? '<i data-lucide="check-circle-2" class="w-5 h-5 status-ok"></i>' : '<i data-lucide="x-circle" class="w-5 h-5 status-error"></i>'; ?>
                    </li>
                    <li class="flex justify-between items-center p-2 rounded-lg hover:bg-white/5 transition-colors">
                        <span class="text-gray-400">Baza danych</span>
                        <?php echo $dbExists ? '<span class="status-ok font-semibold">Połączono</span>' : '<span class="status-error font-semibold">Brak pliku</span>'; ?>
                    </li>
                </ul>
            </div>

            <!-- Functional Tests -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Test Cards Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Database Test -->
                    <div class="card flex flex-col justify-between">
                        <div>
                            <div class="flex items-center space-x-3 mb-4">
                                <div class="p-2 bg-green-500/20 rounded-lg">
                                    <i data-lucide="database" class="w-5 h-5 text-green-400"></i>
                                </div>
                                <h2 class="text-lg font-semibold text-white">Baza Danych</h2>
                            </div>
                            <p class="text-xs text-gray-400 mb-6 leading-relaxed">Testuje poprawność zapisu danych klienta, odczyt oraz późniejsze usuwanie rekordów testowych.</p>
                        </div>
                        <div>
                            <button onclick="runTest('db_write', this)" class="btn-action w-full py-3.5 rounded-xl flex items-center justify-center space-x-2 text-sm font-bold text-white shadow-lg">
                                <i data-lucide="zap" class="w-4 h-4"></i>
                                <span>Testuj operacje DB</span>
                            </button>
                            <div id="res-db_write" class="mt-4 p-4 rounded-xl text-xs hidden"></div>
                        </div>
                    </div>

                    <!-- Upload Test -->
                    <div class="card flex flex-col justify-between">
                        <div>
                            <div class="flex items-center space-x-3 mb-4">
                                <div class="p-2 bg-orange-500/20 rounded-lg">
                                    <i data-lucide="upload-cloud" class="w-5 h-5 text-orange-400"></i>
                                </div>
                                <h2 class="text-lg font-semibold text-white">Przesyłanie Plików</h2>
                            </div>
                            <p class="text-xs text-gray-400 mb-6 leading-relaxed">Weryfikuje uprawnienia do folderów 'photos' i 'thumbnails' oraz testuje przetwarzanie obrazów (GD).</p>
                        </div>
                        <div>
                            <button onclick="runTest('upload', this)" class="btn-action w-full py-3.5 rounded-xl flex items-center justify-center space-x-2 text-sm font-bold text-white shadow-lg">
                                <i data-lucide="image" class="w-4 h-4"></i>
                                <span>Testuj przesyłanie</span>
                            </button>
                            <div id="res-upload" class="mt-4 p-4 rounded-xl text-xs hidden"></div>
                        </div>
                    </div>

                    <!-- Telegram Test -->
                    <div class="card md:col-span-2 flex flex-col md:flex-row items-center gap-6">
                        <div class="flex-grow">
                            <div class="flex items-center space-x-3 mb-4">
                                <div class="p-2 bg-blue-500/20 rounded-lg">
                                    <i data-lucide="bot" class="w-5 h-5 text-blue-400"></i>
                                </div>
                                <h2 class="text-lg font-semibold text-white">Powiadomienia Telegram</h2>
                            </div>
                            <div class="flex items-center gap-3 mb-4">
                                <span class="text-xs text-gray-400">Status bota:</span>
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold <?php echo defined('TELEGRAM_BOT_ENABLED') && TELEGRAM_BOT_ENABLED ? 'bg-green-500/20 text-green-400' : 'bg-gray-500/20 text-gray-400'; ?>">
                                    <?php echo defined('TELEGRAM_BOT_ENABLED') && TELEGRAM_BOT_ENABLED ? 'AKTYWNY' : 'WYŁĄCZONY'; ?>
                                </span>
                            </div>
                            <p class="text-xs text-gray-400 leading-relaxed">Wysyła wiadomość testową z informacją o nowym wyborze zdjęć na Twoje ID czatu.</p>
                        </div>
                        <div class="w-full md:w-64 flex-shrink-0">
                            <button onclick="runTest('telegram', this)" <?php echo !defined('TELEGRAM_BOT_ENABLED') || !TELEGRAM_BOT_ENABLED ? 'disabled' : ''; ?> class="btn-action w-full py-3.5 rounded-xl flex items-center justify-center space-x-2 text-sm font-bold text-white shadow-lg disabled:opacity-30 disabled:cursor-not-allowed">
                                <i data-lucide="send" class="w-4 h-4"></i>
                                <span>Wyślij wiadomość</span>
                            </button>
                            <div id="res-telegram" class="mt-4 p-4 rounded-xl text-xs hidden"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <footer class="mt-16 pt-8 border-t border-[#3f3f6e] text-center">
            <p class="text-gray-500 text-xs font-medium">System Diagnostyki Aplikacji &copy; <?php echo date('Y'); ?> | <span class="text-cyan-400/70">Wowk Digital Premium Suite</span></p>
        </footer>
    </div>

    <script>
        lucide.createIcons();

        async function runTest(type, btn) {
            const resultDiv = document.getElementById(`res-${type}`);
            const originalContent = btn.innerHTML;
            
            // UI Update
            btn.disabled = true;
            btn.innerHTML = '<i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i> <span>Uruchamianie...</span>';
            lucide.createIcons();
            
            resultDiv.classList.add('hidden');
            resultDiv.className = 'mt-4 p-4 rounded-xl text-xs font-medium border animate-in fade-in slide-in-from-top-2 duration-300';

            try {
                const response = await fetch(`diagnostics.php?action=test_${type}`);
                if (!response.ok) throw new Error("Błąd serwera (HTTP " + response.status + ")");
                
                const data = await response.json();
                
                resultDiv.classList.remove('hidden');
                resultDiv.innerHTML = `
                    <div class="flex items-start space-x-3">
                        <i data-lucide="${data.status === 'success' ? 'check-circle' : 'alert-circle'}" class="w-5 h-5 flex-shrink-0 mt-0.5"></i>
                        <span>${data.message}</span>
                    </div>
                `;
                
                if (data.status === 'success') {
                    resultDiv.classList.add('bg-green-500/10', 'text-green-400', 'border-green-500/20');
                } else {
                    resultDiv.classList.add('bg-red-500/10', 'text-red-400', 'border-red-500/20');
                }
            } catch (error) {
                resultDiv.classList.remove('hidden');
                resultDiv.innerHTML = `
                    <div class="flex items-start space-x-3 text-red-400">
                        <i data-lucide="x-octagon" class="w-5 h-5 flex-shrink-0 mt-0.5"></i>
                        <span>Błąd krytyczny: ${error.message}</span>
                    </div>
                `;
                resultDiv.classList.add('bg-red-500/10', 'border-red-500/20');
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalContent;
                lucide.createIcons();
            }
        }
    </script>
</body>
</html>

