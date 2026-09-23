<?php
// admin/upload.php
// Opartu na: base/upload_base.php z integracją z systemem albumów i bazą danych

require_once 'auth.php';
require_once '../api/db.php';

// Ustawienia ścieżek
define('UPLOADS_DIR', __DIR__ . '/../photos/');
define('THUMBS_DIR', __DIR__ . '/../photos/thumbnails/');

if (!is_dir(UPLOADS_DIR)) mkdir(UPLOADS_DIR, 0755, true);
if (!is_dir(THUMBS_DIR)) mkdir(THUMBS_DIR, 0755, true);

// --- Weryfikacja CSRF dla żądań POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token(true);
}

// --- API: Tworzenie albumu (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_album_ajax') {
    header('Content-Type: application/json');
    try {
        $internalName = trim($_POST['internal_name'] ?? '');
        $publicTitle = trim($_POST['public_title'] ?? '');
        if (empty($internalName) || empty($publicTitle)) throw new Exception('Uzupełnij nazwy albumu.');

        $slug = bin2hex(random_bytes(8));
        $stmt = $pdo->prepare("INSERT INTO albums (slug, internal_name, public_title) VALUES (?, ?, ?)");
        $stmt->execute([$slug, $internalName, $publicTitle]);
        
        require_once '../api/logger.php';
        Logger::action('Stworzono nowy album', $internalName);

        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'slug' => $slug, 'internal_name' => $internalName]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// --- API: Obsługa Uploadu ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    
    function send_json_error(string $message, int $http_code = 400): void {
        if (!headers_sent()) { header('Content-Type: application/json'); http_response_code($http_code); }
        echo json_encode(['success' => false, 'error' => $message]);
        exit;
    }

    try {
        if (!isset($_FILES['main_encrypted_file'], $_FILES['thumb_encrypted_file'])) throw new Exception("Brak plików.");
        
        $albumId = (int)($_POST['album_id'] ?? 0);
        if ($albumId <= 0) throw new Exception("Nieprawidłowy ID albumu.");

        // Sprawdź czy album istnieje
        $stmt = $pdo->prepare("SELECT id FROM albums WHERE id = ?");
        $stmt->execute([$albumId]);
        if (!$stmt->fetch()) throw new Exception("Album nie istnieje.");

        // Jeśli przesłano hash klucza, zaktualizuj album (tylko raz lub jeśli jest pusty)
        if (!empty($_POST['encryption_key_hash'])) {
            $stmtHash = $pdo->prepare("UPDATE albums SET encryption_key_hash = ? WHERE id = ? AND (encryption_key_hash IS NULL OR encryption_key_hash = '')");
            $stmtHash->execute([$_POST['encryption_key_hash'], $albumId]);
        }

        $originalName = $_POST['original_filename'];
        $prefix = $_POST['sequence_prefix'];

        // Generujemy losową nazwę pliku z cyfr dla anonimowości (bezpieczny generator CSPRNG)
        do {
            $randomNumbers = '';
            for ($i = 0; $i < 15; $i++) {
                $randomNumbers .= random_int(0, 9);
            }
            $finalFilename = $randomNumbers . '.enc';
            $mainPath = UPLOADS_DIR . $finalFilename;
            $thumbPath = THUMBS_DIR . $finalFilename;
        } while (file_exists($mainPath) || file_exists($thumbPath));

        if (file_exists($mainPath)) throw new Exception("Plik $finalFilename już istnieje.");

        if (!move_uploaded_file($_FILES['main_encrypted_file']['tmp_name'], $mainPath)) throw new Exception("Błąd zapisu pliku głównego.");
        if (!move_uploaded_file($_FILES['thumb_encrypted_file']['tmp_name'], $thumbPath)) {
            unlink($mainPath); throw new Exception("Błąd zapisu miniatury.");
        }

        // ZAPIS DO BAZY
        $stmt = $pdo->prepare("INSERT INTO photos (filename, original_filename, album_id) VALUES (?, ?, ?)");
        $stmt->execute([$finalFilename, $originalName, $albumId]);
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'final_size' => filesize($mainPath)]);

    } catch (Throwable $e) {
        error_log('Upload error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        send_json_error('Błąd serwera: ' . $e->getMessage(), 500);
    }
    exit;
}

// --- Widok HTML ---
// Pobierz albumy do listy
$stmt = $pdo->query("SELECT id, internal_name, slug, encryption_key_hash FROM albums ORDER BY created_at DESC");
$albums = $stmt->fetchAll(PDO::FETCH_ASSOC);
$preselectedAlbumId = $_GET['album_id'] ?? 0;
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bezpieczny Uploader ZKA - Panel Administratora</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/exifreader@4.21.1/dist/exif-reader.min.js"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

        body {
            font-family: 'Inter', sans-serif;
            background-color: #131326;
            color: #e0e0e0;
            overflow-x: hidden;
        }

        .dashboard-header {
            background-color: rgba(26, 26, 46, 0.92);
            backdrop-filter: blur(16px);
            border-bottom: 1px solid #2c2c54;
        }

        .card {
            background: linear-gradient(145deg, rgba(38, 38, 72, 0.7) 0%, rgba(26, 26, 50, 0.85) 100%);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(63, 63, 110, 0.7);
            border-radius: 1.5rem;
            box-shadow: 0 20px 40px -15px rgba(0, 0, 0, 0.5);
        }

        .btn-primary {
            background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
            box-shadow: 0 4px 14px rgba(6, 182, 212, 0.25);
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .btn-primary:hover:not(:disabled) {
            box-shadow: 0 6px 20px rgba(6, 182, 212, 0.45);
            transform: translateY(-1px);
        }

        .btn-primary:active:not(:disabled) {
            transform: translateY(0);
        }

        .nav-btn {
            background-color: #2c2c54;
            transition: all 0.2s;
            border: 1px solid #3f3f6e;
        }

        .nav-btn:hover {
            background-color: #3f3f6e;
            border-color: #4f4f8a;
            transform: translateY(-1px);
        }

        @keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
        .fade-in { animation: fadeIn 0.35s ease-out forwards; }
        
        #imageInput { display: none; }
        
        #drop-zone { 
            border: 2px dashed rgba(6, 182, 212, 0.35); 
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
            background: radial-gradient(circle at 50% 30%, rgba(6, 182, 212, 0.05) 0%, rgba(21, 21, 42, 0.6) 100%);
        }
        
        #drop-zone:hover, #drop-zone.drag-over { 
            border-color: #06b6d4; 
            background: radial-gradient(circle at 50% 30%, rgba(6, 182, 212, 0.12) 0%, rgba(21, 21, 42, 0.8) 100%);
            box-shadow: 0 0 35px rgba(6, 182, 212, 0.18);
        }
        
        .thumbnail-item { position: relative; animation: fadeIn 0.3s ease-out; }
        .thumbnail-remove-btn { 
            position: absolute; 
            top: -0.4rem; 
            right: -0.4rem; 
            background-color: #ef4444; 
            color: white; 
            width: 22px; 
            height: 22px; 
            border-radius: 9999px; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-size: 13px;
            font-weight: bold; 
            cursor: pointer; 
            border: 2px solid #1a1a2e; 
            opacity: 0; 
            transition: all 0.2s ease; 
            transform: scale(0.85); 
            z-index: 10; 
        }
        .thumbnail-item:hover .thumbnail-remove-btn { opacity: 1; transform: scale(1); }

        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: #151525; border-radius: 4px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #3f3f6e; border-radius: 4px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #06b6d4; }
    </style>
</head>
<body class="min-h-screen flex flex-col relative selection:bg-cyan-500 selection:text-white">
    <!-- Ambient Glow Orbs -->
    <div class="fixed -top-40 -left-40 w-96 h-96 bg-cyan-500/10 rounded-full blur-[140px] pointer-events-none"></div>
    <div class="fixed top-1/3 -right-40 w-96 h-96 bg-blue-600/10 rounded-full blur-[140px] pointer-events-none"></div>
    <div class="fixed -bottom-40 left-1/3 w-96 h-96 bg-purple-600/10 rounded-full blur-[140px] pointer-events-none"></div>

    <!-- Unified Navbar -->
    <header class="dashboard-header sticky top-0 z-40 w-full mb-6 shadow-xl">
        <div class="container mx-auto px-4 py-3.5 flex flex-col md:flex-row justify-between items-center gap-4">
            <div class="flex items-center space-x-3.5">
                <div class="bg-gradient-to-br from-cyan-500 to-blue-600 p-2.5 rounded-xl shadow-lg shadow-cyan-500/20">
                    <i data-lucide="upload-cloud" class="w-5 h-5 text-white"></i>
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-lg font-bold text-white tracking-tight">Panel Administratora</h1>
                        <span class="bg-cyan-500/10 text-cyan-400 border border-cyan-500/20 text-[10px] font-semibold px-2 py-0.5 rounded-full">ZKA v2.0</span>
                    </div>
                    <p class="text-xs text-gray-400 font-medium">Bezpieczny Uploader Zdjęć</p>
                </div>
            </div>
            
            <div class="flex items-center gap-2.5 flex-wrap justify-center">
                <a href="index.php" class="nav-btn text-gray-200 px-4 py-2 rounded-xl flex items-center text-xs font-semibold">
                    <i data-lucide="layout-dashboard" class="w-3.5 h-3.5 mr-1.5 text-cyan-400"></i> Albumy
                </a>

                <a href="upload.php" class="btn-primary text-white px-4 py-2 rounded-xl flex items-center text-xs font-semibold border border-cyan-400/30">
                    <i data-lucide="upload-cloud" class="w-3.5 h-3.5 mr-1.5"></i> Prześlij
                </a>

                <a href="diagnostics.php" class="nav-btn text-gray-200 px-4 py-2 rounded-xl flex items-center text-xs font-semibold">
                    <i data-lucide="activity" class="w-3.5 h-3.5 mr-1.5 text-green-400"></i> Diagnostyka
                </a>

                <a href="settings.php" class="nav-btn text-gray-200 px-4 py-2 rounded-xl flex items-center text-xs font-semibold">
                    <i data-lucide="settings" class="w-3.5 h-3.5 mr-1.5 text-gray-400"></i> Ustawienia
                </a>
                
                <a href="logout.php" class="ml-1 text-gray-400 hover:text-red-400 p-2 rounded-xl hover:bg-red-500/10 transition-colors border border-transparent hover:border-red-500/20" title="Wyloguj">
                    <i data-lucide="log-out" class="w-4 h-4"></i>
                </a>
            </div>
        </div>
    </header>

    <div class="container mx-auto px-4 max-w-5xl flex-grow pb-12 relative z-10">
        <!-- Sub-hero title bar -->
        <div class="flex flex-col sm:flex-row sm:items-end justify-between mb-6 gap-3">
            <div>
                <div class="flex items-center gap-2 mb-1.5">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-md text-[11px] font-semibold bg-cyan-500/10 text-cyan-400 border border-cyan-500/20">
                        <i data-lucide="shield-check" class="w-3 h-3"></i> Szyfrowanie po stronie klienta (AES-GCM 256-bit)
                    </span>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-md text-[11px] font-semibold bg-purple-500/10 text-purple-300 border border-purple-500/20">
                        <i data-lucide="cpu" class="w-3 h-3"></i> Sony ARW + JPG
                    </span>
                </div>
                <h2 class="text-2xl font-black text-white tracking-tight">Wgrywanie i Szyfrowanie Zdjęć</h2>
                <p class="text-xs text-gray-400">Wszystkie zdjęcia są lokalnie skalowane i szyfrowane w przeglądarce przed transmisją na serwer.</p>
            </div>
            <div id="statusMessage" class="text-cyan-400 text-xs font-semibold px-3 py-1.5 rounded-xl bg-cyan-500/10 border border-cyan-500/20 empty:hidden"></div>
        </div>

        <div class="card p-5 md:p-7 relative overflow-hidden">
            <div id="formContainer">
                <form id="uploadForm" novalidate class="space-y-6">
                    
                    <!-- KROK 1: MIEJSCE DOCELOWE & BEZPIECZEŃSTWO -->
                    <div class="grid md:grid-cols-2 gap-5">
                        
                        <!-- KARTA 1: MIEJSCE DOCELOWE -->
                        <div class="bg-[#15152a]/70 p-5 rounded-2xl border border-[#3f3f6e] flex flex-col justify-between relative group">
                            <div>
                                <div class="flex items-center justify-between mb-4">
                                    <h3 class="text-sm font-bold text-white flex items-center">
                                        <div class="p-1.5 rounded-lg bg-cyan-500/10 text-cyan-400 mr-2.5 border border-cyan-500/20">
                                            <i data-lucide="folder" class="w-4 h-4"></i>
                                        </div>
                                        Miejsce Docelowe
                                    </h3>
                                    <span class="text-[10px] text-gray-400 font-medium">Krok 1/2</span>
                                </div>
                                
                                <!-- Segmented Card Radios -->
                                <div class="grid grid-cols-2 gap-2 mb-3.5">
                                    <label id="label-album-existing" class="flex items-center justify-center p-2.5 rounded-xl border border-cyan-500 bg-cyan-950/20 text-white cursor-pointer transition-all text-xs font-semibold gap-2 shadow-sm">
                                        <input type="radio" name="album_mode" value="existing" <?php echo empty($albums) ? '' : 'checked'; ?> class="sr-only">
                                        <i data-lucide="folder-check" class="w-3.5 h-3.5 text-cyan-400"></i>
                                        <span>Istniejący</span>
                                    </label>
                                    <label id="label-album-new" class="flex items-center justify-center p-2.5 rounded-xl border border-[#3f3f6e] bg-[#1a1a32]/60 text-gray-400 hover:text-white cursor-pointer transition-all text-xs font-semibold gap-2">
                                        <input type="radio" name="album_mode" value="new" <?php echo empty($albums) ? 'checked' : ''; ?> class="sr-only">
                                        <i data-lucide="folder-plus" class="w-3.5 h-3.5 text-cyan-400"></i>
                                        <span>Nowy album</span>
                                    </label>
                                </div>

                                <!-- Istniejący Album Select -->
                                <div id="existingAlbumContainer" class="space-y-1.5">
                                    <label for="existingAlbumSelect" class="text-[11px] font-semibold text-gray-400">Wybierz album:</label>
                                    <div class="relative">
                                        <select id="existingAlbumSelect" class="w-full rounded-xl p-2.5 pr-8 text-xs font-medium bg-[#1a1a32] border border-[#3f3f6e] text-white focus:border-cyan-500 outline-none transition-all appearance-none cursor-pointer">
                                            <?php if(empty($albums)): ?><option value="">Brak albumów w bazie</option><?php endif; ?>
                                            <?php foreach ($albums as $a): ?>
                                                <option value="<?php echo $a['id']; ?>" data-slug="<?php echo $a['slug']; ?>" data-key-hash="<?php echo htmlspecialchars($a['encryption_key_hash'] ?? ''); ?>" <?php echo $a['id'] == $preselectedAlbumId ? 'selected' : ''; ?>>
                                                    📁 <?php echo htmlspecialchars($a['internal_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2.5 text-gray-400">
                                            <i data-lucide="chevron-down" class="w-4 h-4"></i>
                                        </div>
                                    </div>
                                </div>

                                <!-- Nowy Album Inputs -->
                                <div id="newAlbumInputs" class="space-y-2 mt-2 <?php echo empty($albums) ? '' : 'hidden opacity-0'; ?> transition-all">
                                    <div>
                                        <label for="newInternalName" class="text-[11px] font-semibold text-gray-400">Nazwa robocza (widoczna w panelu):</label>
                                        <input type="text" id="newInternalName" name="new_internal_name" placeholder="np. Sesja Ani i Piotra" class="w-full rounded-xl p-2.5 text-xs font-medium bg-[#1a1a32] border border-[#3f3f6e] text-white focus:border-cyan-500 outline-none mt-1">
                                    </div>
                                    <div>
                                        <label for="newPublicTitle" class="text-[11px] font-semibold text-gray-400">Tytuł publiczny (widoczny dla klienta):</label>
                                        <input type="text" id="newPublicTitle" name="new_public_title" placeholder="np. Ania & Piotr - Ślub 2026" class="w-full rounded-xl p-2.5 text-xs font-medium bg-[#1a1a32] border border-[#3f3f6e] text-white focus:border-cyan-500 outline-none mt-1">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- KARTA 2: BEZPIECZEŃSTWO -->
                        <div class="bg-[#15152a]/70 p-5 rounded-2xl border border-[#3f3f6e] flex flex-col justify-between relative group">
                            <div>
                                <div class="flex items-center justify-between mb-4">
                                    <h3 class="text-sm font-bold text-white flex items-center">
                                        <div class="p-1.5 rounded-lg bg-purple-500/10 text-purple-400 mr-2.5 border border-purple-500/20">
                                            <i data-lucide="key" class="w-4 h-4"></i>
                                        </div>
                                        Bezpieczeństwo (ZKA)
                                    </h3>
                                    <span class="text-[10px] text-gray-400 font-medium">Krok 2/2</span>
                                </div>
                                
                                <!-- Segmented Card Radios -->
                                <div class="grid grid-cols-2 gap-2 mb-3.5">
                                    <label id="label-key-new" class="flex items-center justify-center p-2.5 rounded-xl border border-cyan-500 bg-cyan-950/20 text-white cursor-pointer transition-all text-xs font-semibold gap-2 shadow-sm">
                                        <input type="radio" name="key_mode" value="new" checked class="sr-only">
                                        <i data-lucide="sparkles" class="w-3.5 h-3.5 text-cyan-400"></i>
                                        <span>Nowy klucz</span>
                                    </label>
                                    <label id="label-key-existing" class="flex items-center justify-center p-2.5 rounded-xl border border-[#3f3f6e] bg-[#1a1a32]/60 text-gray-400 hover:text-white cursor-pointer transition-all text-xs font-semibold gap-2">
                                        <input type="radio" name="key_mode" value="existing" class="sr-only">
                                        <i data-lucide="lock" class="w-3.5 h-3.5 text-purple-400"></i>
                                        <span>Własny HEX</span>
                                    </label>
                                </div>

                                <div id="keyModeExplanation" class="text-[11px] text-gray-400 leading-relaxed mb-3">
                                    <span id="keyModeNewText" class="flex items-center gap-1.5 text-gray-300">
                                        <i data-lucide="check-circle-2" class="w-3.5 h-3.5 text-cyan-400 shrink-0"></i>
                                        Automatycznie wygeneruje unikalny 256-bitowy klucz AES i zapisze go w Twoim Sejfie.
                                    </span>
                                </div>

                                <div id="existingKeyContainer" class="space-y-1.5 hidden opacity-0 transition-all">
                                    <label for="existingKeyInput" class="text-[11px] font-semibold text-gray-400">Klucz szyfrowania (Base64 lub Hex):</label>
                                    <div class="relative">
                                        <input type="text" id="existingKeyInput" name="existing_key_hex" placeholder="Wklej klucz (Base64 lub Hex)..." class="w-full rounded-xl p-2.5 text-xs text-cyan-400 font-mono bg-[#1a1a32] border border-[#3f3f6e] focus:border-cyan-500 outline-none">
                                    </div>
                                </div>

                                <div id="vaultKeyHint" class="hidden p-3 rounded-xl bg-emerald-500/10 border border-emerald-500/25 text-emerald-400 text-xs font-medium flex items-center gap-2.5 mt-2">
                                    <i data-lucide="shield-check" class="w-4 h-4 shrink-0 text-emerald-400"></i>
                                    <span>Klucz dopasowany i pobrany z Twojego Sejfu!</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- KROK 2: DROP ZONE -->
                    <div id="drop-zone" class="w-full text-center py-10 px-6 rounded-3xl cursor-pointer transition-all group relative overflow-hidden">
                        <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-cyan-500/20 to-blue-600/20 border border-cyan-500/30 flex items-center justify-center mx-auto mb-4 group-hover:scale-110 group-hover:shadow-[0_0_25px_rgba(6,182,212,0.35)] transition-all">
                            <i data-lucide="upload-cloud" class="w-8 h-8 text-cyan-400"></i>
                        </div>
                        <h4 class="text-base md:text-lg font-bold text-white mb-1.5 tracking-tight">Przeciągnij i upuść zdjęcia tutaj</h4>
                        <p class="text-xs text-gray-400 mb-4 max-w-md mx-auto">Obsługuje surowe pliki RAW z aparatów cyfrowych oraz standardowe obrazy rastrowe.</p>
                        
                        <div class="flex flex-wrap items-center justify-center gap-2 mb-6">
                            <span class="text-[11px] font-mono font-semibold px-2.5 py-0.5 rounded-full bg-[#252548] border border-[#3f3f6e] text-purple-300">RAW (.ARW)</span>
                            <span class="text-[11px] font-mono font-semibold px-2.5 py-0.5 rounded-full bg-[#252548] border border-[#3f3f6e] text-cyan-300">JPG / JPEG</span>
                            <span class="text-[11px] font-mono font-semibold px-2.5 py-0.5 rounded-full bg-[#252548] border border-[#3f3f6e] text-blue-300">PNG</span>
                        </div>
                        
                        <label for="imageInput" class="inline-flex items-center btn-primary text-white font-semibold py-3 px-8 rounded-xl cursor-pointer text-xs shadow-lg shadow-cyan-500/20 hover:shadow-cyan-500/40 transition-all border border-cyan-400/20">
                            <i data-lucide="folder-plus" class="w-4 h-4 mr-2"></i> Wybierz pliki z dysku
                        </label>
                        <input type="file" id="imageInput" name="images[]" accept="image/png, image/jpeg, image/gif, .arw" multiple>
                    </div>

                    <!-- PREVIEW GRID -->
                    <div id="filePreviewsWrapper" class="hidden space-y-2">
                        <div class="flex items-center justify-between px-1">
                            <span id="previewCountBadge" class="text-xs font-bold text-cyan-400 flex items-center gap-1.5">
                                <i data-lucide="images" class="w-3.5 h-3.5"></i> Wybrane pliki
                            </span>
                            <button type="button" id="clearAllFilesBtn" class="text-[11px] text-red-400 hover:text-red-300 flex items-center gap-1 hover:underline">
                                <i data-lucide="trash-2" class="w-3 h-3"></i> Wyczyść listę
                            </button>
                        </div>
                        <div id="file-previews" class="grid grid-cols-4 sm:grid-cols-6 md:grid-cols-8 lg:grid-cols-10 gap-2.5 bg-[#15152a]/70 p-3.5 rounded-2xl border border-[#3f3f6e] max-h-64 overflow-y-auto custom-scrollbar"></div>
                    </div>
                    
                    <!-- KROK 3: PARAMETRY KOMPRESJI I PRZETWARZANIA -->
                    <div class="bg-[#15152a]/70 p-5 rounded-2xl border border-[#3f3f6e]">
                        <div class="grid sm:grid-cols-3 gap-6 items-center">
                            <!-- Rozmiar -->
                            <div class="flex flex-col space-y-2">
                                <div class="flex justify-between items-center">
                                    <label for="maxEdge" class="text-xs font-semibold text-gray-300">Maks. Dłuższa Krawędź</label>
                                    <span class="text-[10px] text-gray-500 font-mono">piksele</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <input type="number" id="maxEdge" name="max_edge" min="500" max="8000" step="100" value="2000" class="rounded-xl p-2.5 text-xs font-bold bg-[#1a1a32] border border-[#3f3f6e] text-white focus:border-cyan-500 outline-none w-full">
                                </div>
                                <div class="flex gap-1.5 pt-1">
                                    <button type="button" onclick="setMaxEdge(1600)" class="text-[10px] font-mono px-2 py-0.5 rounded bg-[#252548] hover:bg-[#353565] text-gray-300 border border-[#3f3f6e] transition-colors">1600</button>
                                    <button type="button" onclick="setMaxEdge(2048)" class="text-[10px] font-mono px-2 py-0.5 rounded bg-[#252548] hover:bg-[#353565] text-gray-300 border border-[#3f3f6e] transition-colors">2048</button>
                                    <button type="button" onclick="setMaxEdge(3000)" class="text-[10px] font-mono px-2 py-0.5 rounded bg-[#252548] hover:bg-[#353565] text-gray-300 border border-[#3f3f6e] transition-colors">3000</button>
                                    <button type="button" onclick="setMaxEdge(4000)" class="text-[10px] font-mono px-2 py-0.5 rounded bg-[#252548] hover:bg-[#353565] text-gray-300 border border-[#3f3f6e] transition-colors">4000</button>
                                </div>
                            </div>

                            <!-- Jakość -->
                            <div class="flex flex-col space-y-2">
                                <div class="flex justify-between items-center">
                                    <label for="compressionLevel" class="text-xs font-semibold text-gray-300">Jakość Kompresji JPG</label>
                                    <span id="compressionLevelValue" class="text-xs font-bold text-cyan-400 bg-cyan-500/10 px-2 py-0.5 rounded-md border border-cyan-500/20">85%</span>
                                </div>
                                <input type="range" id="compressionLevel" min="40" max="100" value="85" class="w-full h-2 bg-[#1a1a32] rounded-lg appearance-none cursor-pointer accent-cyan-500">
                                <p class="text-[10px] text-gray-500">Zrównoważony kompromis ostrości i wagi pliku</p>
                            </div>

                            <!-- Przełącznik miniatur -->
                            <div class="flex items-center sm:justify-center pt-2 sm:pt-0">
                                <label class="flex items-center space-x-3 cursor-pointer group select-none">
                                    <div class="relative">
                                        <input type="checkbox" id="showPreviews" class="sr-only peer">
                                        <div class="w-11 h-6 bg-[#1a1a32] border border-[#3f3f6e] rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-0.5 after:left-[2px] after:bg-gray-400 after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-cyan-600 peer-checked:after:bg-white peer-checked:border-cyan-500"></div>
                                    </div>
                                    <div class="flex flex-col">
                                        <span class="text-xs font-semibold text-gray-300 group-hover:text-white">Podgląd miniatur</span>
                                        <span class="text-[10px] text-gray-500">Generuj obrazy na żywo</span>
                                    </div>
                                </label>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <!-- GŁÓWNY PRZYCISK WYSYŁANIA -->
            <div id="mainButtonContainer" class="mt-6">
                <button type="submit" form="uploadForm" id="submitBtn" class="btn-primary w-full text-white font-bold py-4 px-8 rounded-2xl transition-all disabled:opacity-30 disabled:cursor-not-allowed shadow-xl shadow-cyan-500/20 uppercase tracking-widest text-xs flex items-center justify-center gap-2.5 border border-cyan-400/20" disabled>
                    <i data-lucide="lock" class="w-4 h-4"></i>
                    <span>Wybierz pliki, aby rozpocząć</span>
                </button>
            </div>
            
            <!-- PROGRESS CONTAINER -->
            <div id="progressContainer" class="mt-6 hidden bg-[#15152a]/90 p-6 rounded-2xl border border-[#3f3f6e] shadow-xl animate-in fade-in">
                <div class="flex justify-between items-end mb-3">
                    <div>
                        <span class="text-xs text-gray-400 uppercase tracking-wider font-semibold">Postęp przetwarzania:</span>
                        <div id="progressText" class="text-xl font-black text-white mt-0.5">0 / 0</div>
                    </div>
                    <div class="text-right">
                        <span id="percentageText" class="text-2xl font-black text-cyan-400">0%</span>
                    </div>
                </div>
                <div class="w-full bg-[#1a1a32] rounded-full h-3 p-0.5 border border-[#3f3f6e] overflow-hidden">
                    <div id="progressBar" class="bg-gradient-to-r from-cyan-500 via-blue-500 to-indigo-500 h-full rounded-full transition-all duration-300 shadow-[0_0_12px_rgba(6,182,212,0.6)]" style="width: 0%"></div>
                </div>
            </div>
            
            <div id="finalSummary" class="mt-6 hidden"></div>
            <div id="statusListContainer" class="mt-4 max-h-48 overflow-y-auto custom-scrollbar space-y-1.5 pr-2"></div>
            <div id="dynamicButtonContainer" class="mt-6 flex flex-col sm:flex-row gap-2 justify-center"></div>
        </div>
    </div>
    
    <script>
    const CSRF_TOKEN = <?php echo json_encode(get_csrf_token()); ?>;
    
    function setMaxEdge(val) {
        const input = document.getElementById('maxEdge');
        if (input) {
            input.value = val;
            input.dispatchEvent(new Event('change'));
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        lucide.createIcons();
        const CONFIG = { MAX_FILES_PER_BATCH: 2000, UPLOAD_CONCURRENCY: 3, THUMBNAIL_WIDTH: 400, STATUS_MESSAGES: { processing: ["Szyfruję pliki...", "Kompresuję obrazy...", "Przetwarzam lokalnie...", "Wysyłam na serwer..."], done: ["Sukces! Wszystko gotowe."], stopped: ["Proces zatrzymany."] } };
        const STATE = { filesToUpload: [], isProcessing: false, isProcessingCancelled: false, statusMessageInterval: null, encryptionKey: null, targetAlbumId: null, targetAlbumSlug: null };
        const UI = { 
            uploadForm: document.getElementById('uploadForm'), 
            imageInput: document.getElementById('imageInput'), 
            dropZone: document.getElementById('drop-zone'), 
            filePreviews: document.getElementById('file-previews'), 
            filePreviewsWrapper: document.getElementById('filePreviewsWrapper'),
            previewCountBadge: document.getElementById('previewCountBadge'),
            clearAllFilesBtn: document.getElementById('clearAllFilesBtn'),
            mainButtonContainer: document.getElementById('mainButtonContainer'), 
            dynamicButtonContainer: document.getElementById('dynamicButtonContainer'), 
            submitBtn: document.getElementById('submitBtn'), 
            statusMessage: document.getElementById('statusMessage'), 
            progress: { container: document.getElementById('progressContainer'), bar: document.getElementById('progressBar'), text: document.getElementById('progressText'), percentage: document.getElementById('percentageText') }, 
            finalSummary: document.getElementById('finalSummary'), 
            statusListContainer: document.getElementById('statusListContainer'), 
            compression: { maxEdge: document.getElementById('maxEdge'), levelSlider: document.getElementById('compressionLevel'), levelValue: document.getElementById('compressionLevelValue') },
            showPreviews: document.getElementById('showPreviews'),
            
            // UI elements for album & key modes
            albumModeRadios: document.getElementsByName('album_mode'),
            existingAlbumContainer: document.getElementById('existingAlbumContainer'),
            existingAlbumSelect: document.getElementById('existingAlbumSelect'),
            newAlbumInputs: document.getElementById('newAlbumInputs'),
            keyModeRadios: document.getElementsByName('key_mode'),
            existingKeyContainer: document.getElementById('existingKeyContainer'),
            existingKeyInput: document.getElementById('existingKeyInput'),
            keyModeExplanation: document.getElementById('keyModeExplanation'),
            vaultKeyHint: document.getElementById('vaultKeyHint'),
            labelAlbumExisting: document.getElementById('label-album-existing'),
            labelAlbumNew: document.getElementById('label-album-new'),
            labelKeyNew: document.getElementById('label-key-new'),
            labelKeyExisting: document.getElementById('label-key-existing')
        };

        // --- Sejf Kluczy (pobieranie z bazy / sesji) ---
        const VAULT = {
            keys: JSON.parse(sessionStorage.getItem('admin_vault_keys') || '{}'),
            async init() {
                try {
                    const res = await fetch('vault_api.php?action=get_all');
                    const json = await res.json();
                    if (json.success && json.keys) {
                        Object.assign(this.keys, json.keys);
                        sessionStorage.setItem('admin_vault_keys', JSON.stringify(this.keys));
                    }
                } catch(e) { /* cicho w przypadku braku sesji */ }
                checkAlbumKeyMatch();
            },
            getKeyForHash(hash) {
                return this.keys[hash] || null;
            }
        };

        function checkAlbumKeyMatch() {
            if (!UI.existingAlbumSelect || UI.existingAlbumSelect.selectedIndex === -1) return;
            const albumMode = document.querySelector('input[name="album_mode"]:checked')?.value;
            if (albumMode !== 'existing') {
                if (UI.vaultKeyHint) UI.vaultKeyHint.classList.add('hidden');
                return;
            }

            const selectedOption = UI.existingAlbumSelect.options[UI.existingAlbumSelect.selectedIndex];
            const keyHash = selectedOption?.dataset?.keyHash;
            if (keyHash && VAULT.getKeyForHash(keyHash)) {
                const hex = VAULT.getKeyForHash(keyHash);
                UI.existingKeyInput.value = hex;
                const existingRadio = document.querySelector('input[name="key_mode"][value="existing"]');
                if (existingRadio) existingRadio.checked = true;
                if (UI.vaultKeyHint) UI.vaultKeyHint.classList.remove('hidden');
            } else {
                if (UI.vaultKeyHint) UI.vaultKeyHint.classList.add('hidden');
            }
            updateUIState();
        }

        // --- UI Logic for Album/Key Selection ---
        function updateUIState() {
            const albumMode = document.querySelector('input[name="album_mode"]:checked')?.value || 'existing';
            const isNewAlbum = albumMode === 'new';
            
            if (UI.labelAlbumExisting && UI.labelAlbumNew) {
                if (isNewAlbum) {
                    UI.labelAlbumNew.className = 'flex items-center justify-center p-2.5 rounded-xl border border-cyan-500 bg-cyan-950/40 text-white cursor-pointer transition-all text-xs font-semibold gap-2 shadow-sm ring-1 ring-cyan-500/40';
                    UI.labelAlbumExisting.className = 'flex items-center justify-center p-2.5 rounded-xl border border-[#3f3f6e] bg-[#1a1a32]/60 text-gray-400 hover:text-white cursor-pointer transition-all text-xs font-semibold gap-2';
                    UI.existingAlbumContainer.classList.add('hidden');
                    UI.newAlbumInputs.classList.remove('hidden', 'opacity-0');
                    if (UI.vaultKeyHint) UI.vaultKeyHint.classList.add('hidden');
                } else {
                    UI.labelAlbumExisting.className = 'flex items-center justify-center p-2.5 rounded-xl border border-cyan-500 bg-cyan-950/40 text-white cursor-pointer transition-all text-xs font-semibold gap-2 shadow-sm ring-1 ring-cyan-500/40';
                    UI.labelAlbumNew.className = 'flex items-center justify-center p-2.5 rounded-xl border border-[#3f3f6e] bg-[#1a1a32]/60 text-gray-400 hover:text-white cursor-pointer transition-all text-xs font-semibold gap-2';
                    UI.existingAlbumContainer.classList.remove('hidden');
                    UI.newAlbumInputs.classList.add('hidden', 'opacity-0');
                }
            }

            const keyMode = document.querySelector('input[name="key_mode"]:checked')?.value || 'new';
            const isExistingKey = keyMode === 'existing';
            if (UI.labelKeyNew && UI.labelKeyExisting) {
                if (isExistingKey) {
                    UI.labelKeyExisting.className = 'flex items-center justify-center p-2.5 rounded-xl border border-cyan-500 bg-cyan-950/40 text-white cursor-pointer transition-all text-xs font-semibold gap-2 shadow-sm ring-1 ring-cyan-500/40';
                    UI.labelKeyNew.className = 'flex items-center justify-center p-2.5 rounded-xl border border-[#3f3f6e] bg-[#1a1a32]/60 text-gray-400 hover:text-white cursor-pointer transition-all text-xs font-semibold gap-2';
                    UI.existingKeyContainer.classList.remove('hidden', 'opacity-0');
                    UI.keyModeExplanation.classList.add('hidden');
                    UI.existingKeyInput.focus();
                } else {
                    UI.labelKeyNew.className = 'flex items-center justify-center p-2.5 rounded-xl border border-cyan-500 bg-cyan-950/40 text-white cursor-pointer transition-all text-xs font-semibold gap-2 shadow-sm ring-1 ring-cyan-500/40';
                    UI.labelKeyExisting.className = 'flex items-center justify-center p-2.5 rounded-xl border border-[#3f3f6e] bg-[#1a1a32]/60 text-gray-400 hover:text-white cursor-pointer transition-all text-xs font-semibold gap-2';
                    UI.existingKeyContainer.classList.add('hidden', 'opacity-0');
                    UI.keyModeExplanation.classList.remove('hidden');
                }
            }
        }
        UI.albumModeRadios.forEach(r => r.addEventListener('change', () => { updateUIState(); checkAlbumKeyMatch(); }));
        UI.existingAlbumSelect.addEventListener('change', checkAlbumKeyMatch);
        UI.keyModeRadios.forEach(r => r.addEventListener('change', updateUIState));
        VAULT.init();
        updateUIState(); // init

        // Mirror Internal Name to Public Title
        const newInternalNameInput = document.getElementById('newInternalName');
        const newPublicTitleInput = document.getElementById('newPublicTitle');
        let publicTitleTouched = false;

        newPublicTitleInput.addEventListener('input', () => { publicTitleTouched = true; });
        newInternalNameInput.addEventListener('input', () => {
            if (!publicTitleTouched) {
                newPublicTitleInput.value = newInternalNameInput.value;
            }
        });

        const CryptoHelper = { 
            async generateKey() { return await window.crypto.subtle.generateKey({ name: "AES-GCM", length: 256 }, true, ["encrypt"]); }, 
            parseKeyToBuffer(keyStr) {
                if (!keyStr) throw new Error("Brak klucza szyfrowania.");
                keyStr = decodeURIComponent(keyStr.trim());
                if (keyStr.includes('#')) keyStr = keyStr.split('#').pop().trim();

                // 1. Sprawdź format HEX (64 znaki)
                if (/^[0-9a-fA-F]{64}$/.test(keyStr)) {
                    const bytes = new Uint8Array(32);
                    for (let i = 0; i < 64; i += 2) {
                        bytes[i / 2] = parseInt(keyStr.substring(i, i + 2), 16);
                    }
                    return bytes.buffer;
                }

                // 2. Format Base64 / Base64URL
                let b64 = keyStr.replace(/-/g, '+').replace(/_/g, '/').replace(/\s/g, '+');
                while (b64.length % 4 !== 0) b64 += '=';
                try {
                    const binary = atob(b64);
                    if (binary.length !== 32) throw new Error("Klucz musi mieć 32 bajty.");
                    const bytes = new Uint8Array(32);
                    for (let i = 0; i < 32; i++) bytes[i] = binary.charCodeAt(i);
                    return bytes.buffer;
                } catch (e) {
                    throw new Error("Nieprawidłowy format klucza (wymagany Base64 lub Hex).");
                }
            },
            async importKey(keyStr, usages = ["encrypt"]) {
                const buf = this.parseKeyToBuffer(keyStr);
                return await window.crypto.subtle.importKey("raw", buf, { name: "AES-GCM" }, true, usages);
            },
            async importKeyFromHex(hex) { 
                return await this.importKey(hex, ["encrypt"]);
            },
            async exportKeyToBase64(key) {
                const buf = await window.crypto.subtle.exportKey("raw", key);
                const bytes = new Uint8Array(buf);
                let binary = '';
                for (let i = 0; i < bytes.byteLength; i++) binary += String.fromCharCode(bytes[i]);
                return btoa(binary);
            },
            async exportKeyToHex(key) { const buf = await window.crypto.subtle.exportKey("raw", key); return Array.from(new Uint8Array(buf)).map(b => b.toString(16).padStart(2, '0')).join(''); }, 
            async sha256(str) {
                const msgBuffer = new TextEncoder().encode(str.trim());
                const hashBuffer = await window.crypto.subtle.digest('SHA-256', msgBuffer);
                const hashArray = Array.from(new Uint8Array(hashBuffer));
                return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
            },
            async encrypt(dataBuffer, key) { const iv = window.crypto.getRandomValues(new Uint8Array(12)); const ct = await window.crypto.subtle.encrypt({ name: "AES-GCM", iv: iv }, key, dataBuffer); const res = new Uint8Array(iv.length + ct.byteLength); res.set(iv, 0); res.set(new Uint8Array(ct), iv.length); return res.buffer; },
            async encryptString(text, key) {
                const enc = new TextEncoder();
                const dataBuffer = enc.encode(text);
                const encryptedBuffer = await this.encrypt(dataBuffer, key);
                const res = new Uint8Array(encryptedBuffer);
                let binary = '';
                for (let i = 0; i < res.byteLength; i++) binary += String.fromCharCode(res[i]);
                return btoa(binary);
            }
        };
        
        const ImageProcessor = { 
            async process(file, options) { 
                let blob = file; 
                
                // Obsługa plików RAW (.ARW)
                if (file.name.toLowerCase().endsWith('.arw')) { 
                    try { 
                        blob = await this.convertArwToJpeg(file); 
                    } catch (e) { 
                        throw new Error(`Błąd konwersji .ARW: ${e.message}`); 
                    } 
                } 
                
                // Wczytanie obrazu do obiektu Image
                const img = await new Promise((resolve, reject) => { 
                    const i = new Image();
                    const u = URL.createObjectURL(blob); 
                    i.onload = () => { URL.revokeObjectURL(u); resolve(i); }; 
                    i.onerror = err => { URL.revokeObjectURL(u); reject(err); }; 
                    i.src = u; 
                }); 
                
                // Obliczanie nowych wymiarów (skalowanie)
                const canvas = document.createElement('canvas'); 
                let { width, height } = img; 
                const maxEdge = parseInt(options.max_edge); 
                
                if (width > height) { 
                    if (width > maxEdge) { 
                        height *= maxEdge / width; 
                        width = maxEdge; 
                    } 
                } else { 
                    if (height > maxEdge) { 
                        width *= maxEdge / height; 
                        height = maxEdge; 
                    } 
                } 
                
                canvas.width = Math.round(width); 
                canvas.height = Math.round(height); 
                
                // Rysowanie na canvasie (to tutaj następuje faktyczna zmiana rozmiaru pikseli)
                const ctx = canvas.getContext('2d');
                ctx.drawImage(img, 0, 0, canvas.width, canvas.height); 
                
                // Eksport do Blob (Kompresja JPEG)
                const mainImageBlob = await new Promise(r => canvas.toBlob(r, 'image/jpeg', parseFloat(options.compression_level))); 
                
                // Generowanie miniatury (Thumbnail)
                const thumbH = canvas.height * (CONFIG.THUMBNAIL_WIDTH / canvas.width);
                const thumbC = document.createElement('canvas'); 
                thumbC.width = CONFIG.THUMBNAIL_WIDTH; 
                thumbC.height = thumbH; 
                thumbC.getContext('2d').drawImage(canvas, 0, 0, CONFIG.THUMBNAIL_WIDTH, thumbH); 
                
                const thumbImageBlob = await new Promise(r => thumbC.toBlob(r, 'image/jpeg', 0.75)); 
                
                return { mainImageBlob, thumbImageBlob }; 
            }, 
            
            async convertArwToJpeg(file) { 
                const arrayBuffer = await file.arrayBuffer(); 
                const tiffData = this.findJpegInArw(arrayBuffer); 
                
                if (tiffData.offset > 0 && tiffData.length > 0) { 
                    const jpegBuffer = arrayBuffer.slice(tiffData.offset, tiffData.offset + tiffData.length); 
                    const blob = new Blob([jpegBuffer], { type: 'image/jpeg' }); 
                    const result = await this.applyOrientation(blob, tiffData.orientation); 
                    return result;
                } else { 
                    throw new Error('Nie znaleziono podglądu JPG.'); 
                } 
            }, 
            
            applyOrientation(imageBlob, orientation) { 
                return new Promise((resolve, reject) => { 
                    const img = new Image(); 
                    const url = URL.createObjectURL(imageBlob); 
                    
                    img.onload = () => { 
                        URL.revokeObjectURL(url); 
                        const canvas = document.createElement('canvas'); 
                        const ctx = canvas.getContext('2d'); 
                        let { width, height } = img; 
                        
                        if (orientation >= 5 && orientation <= 8) { 
                            canvas.width = height; 
                            canvas.height = width; 
                        } else { 
                            canvas.width = width; 
                            canvas.height = height; 
                        } 
                        
                        switch (orientation) { 
                            case 2: ctx.transform(-1, 0, 0, 1, width, 0); break; 
                            case 3: ctx.transform(-1, 0, 0, -1, width, height); break; 
                            case 4: ctx.transform(1, 0, 0, -1, 0, height); break; 
                            case 5: ctx.transform(0, 1, 1, 0, 0, 0); break; 
                            case 6: ctx.transform(0, 1, -1, 0, height, 0); break; 
                            case 7: ctx.transform(0, -1, -1, 0, height, width); break; 
                            case 8: ctx.transform(0, -1, 1, 0, 0, width); break; 
                        } 
                        
                        ctx.drawImage(img, 0, 0); 
                        canvas.toBlob(blob => resolve(blob), 'image/jpeg'); 
                    }; 
                    
                    img.onerror = (err) => { 
                        URL.revokeObjectURL(url); 
                        reject(err); 
                    }; 
                    
                    img.src = url; 
                }); 
            }, 
            
            findJpegInArw(arrayBuffer) { 
                const dataView = new DataView(arrayBuffer); 
                const isLittleEndian = dataView.getUint16(0, false) === 0x4949; 
                
                if (dataView.getUint16(2, isLittleEndian) !== 42) throw new Error('Nieprawidłowy format pliku TIFF.'); 
                
                let ifdOffset = dataView.getUint32(4, isLittleEndian); 
                let result = { offset: 0, length: 0, orientation: 1 }; 
                
                while (ifdOffset !== 0) { 
                    const ifdResult = this.findDataInIfd(dataView, ifdOffset, isLittleEndian); 
                    if (ifdResult.length > result.length) { 
                        result = { ...result, ...ifdResult }; 
                    } 
                    if (ifdResult.orientation !== 1 && result.orientation === 1) { 
                        result.orientation = ifdResult.orientation; 
                    } 
                    ifdOffset = ifdResult.nextIfdOffset; 
                } 
                
                if (result.offset === 0 || result.length === 0) throw new Error('Nie można zlokalizować danych JPEG.'); 
                
                return result; 
            }, 
            
            findDataInIfd(dataView, ifdOffset, isLittleEndian) { 
                let jpegOffset = 0, jpegLength = 0, orientation = 1; 
                const numEntries = dataView.getUint16(ifdOffset, isLittleEndian); 
                
                for (let i = 0; i < numEntries; i++) { 
                    const entryOffset = ifdOffset + 2 + (i * 12); 
                    const tag = dataView.getUint16(entryOffset, isLittleEndian); 
                    
                    if (tag === 0x014A) { 
                        const subIfdOffset = dataView.getUint32(entryOffset + 8, isLittleEndian); 
                        const subResult = this.findDataInIfd(dataView, subIfdOffset, isLittleEndian); 
                        if (subResult.length > jpegLength) { 
                            jpegOffset = subResult.offset; 
                            jpegLength = subResult.length; 
                            if (subResult.orientation !== 1) orientation = subResult.orientation; 
                        } 
                    } 
                    
                    if (tag === 0x0201) jpegOffset = dataView.getUint32(entryOffset + 8, isLittleEndian); 
                    if (tag === 0x0202) jpegLength = dataView.getUint32(entryOffset + 8, isLittleEndian); 
                    if (tag === 0x0112) orientation = dataView.getUint16(entryOffset + 8, isLittleEndian); 
                } 
                
                const nextIfdOffset = dataView.getUint32(ifdOffset + 2 + (numEntries * 12), isLittleEndian); 
                return { offset: jpegOffset, length: jpegLength, orientation: orientation, nextIfdOffset: nextIfdOffset }; 
            } 
        };
        
        const updateSubmitButton = () => { 
            const count = STATE.filesToUpload.length;
            const hasFiles = count > 0; 
            UI.submitBtn.disabled = !hasFiles; 
            if (hasFiles) {
                const noun = count === 1 ? 'plik' : (count < 5 ? 'pliki' : 'plików');
                UI.submitBtn.innerHTML = `<i data-lucide="lock" class="w-4 h-4 mr-2"></i><span>Zaszyfruj i wyślij (${count} ${noun})</span>`;
            } else {
                UI.submitBtn.innerHTML = `<i data-lucide="lock" class="w-4 h-4 mr-2"></i><span>Wybierz pliki, aby rozpocząć</span>`;
            }
            lucide.createIcons();
        };

        const readFileWithExif = async (file) => { 
            try { 
                const tags = await ExifReader.load(file); 
                const dateStr = tags['DateTimeOriginal']?.description; 
                if (dateStr) { 
                    const parsableDateStr = dateStr.replace(':', '-').replace(':', '-'); 
                    return { file, creationDate: new Date(parsableDateStr) }; 
                } 
            } catch (e) { 
                console.warn(`Nie można odczytać EXIF z ${file.name}:`, e); 
            } 
            return { file, creationDate: new Date(file.lastModified) }; 
        };
        
        const handleFiles = async (files) => {
            UI.statusMessage.textContent = 'Analizuję pliki...';
            try {
                const newFiles = Array.from(files).filter(f => !STATE.filesToUpload.some(item => item.file.name === f.name && item.file.size === f.size));
                if (STATE.filesToUpload.length + newFiles.length > CONFIG.MAX_FILES_PER_BATCH) { alert(`Limit to ${CONFIG.MAX_FILES_PER_BATCH} plików.`); return; }
                
                // Przetwarzanie sekwencyjne zamiast Promise.all, aby nie pożreć całego RAMu
                const newFilesWithData = [];
                for (const f of newFiles) {
                    newFilesWithData.push(await readFileWithExif(f));
                }
                
                STATE.filesToUpload.push(...newFilesWithData);
                STATE.filesToUpload.sort((a, b) => a.creationDate - b.creationDate);
                renderThumbnails();
                updateSubmitButton();
            } catch (error) { console.error("Błąd przetwarzania:", error); UI.statusMessage.textContent = 'Błąd dodawania plików.'; } finally { if (STATE.isProcessing === false) { UI.statusMessage.textContent = ''; } }
        };

        const cleanupResources = () => {
            if (window._thumbnailUrls) {
                window._thumbnailUrls.forEach(url => URL.revokeObjectURL(url));
            }
            window._thumbnailUrls = [];
        };

        const renderThumbnails = async () => { 
            cleanupResources();
            UI.filePreviews.innerHTML = '';
            const count = STATE.filesToUpload.length;
            const hasFiles = count > 0;
            if (UI.filePreviewsWrapper) UI.filePreviewsWrapper.classList.toggle('hidden', !hasFiles);
            if (UI.previewCountBadge) {
                const noun = count === 1 ? 'zdjęcie' : (count < 5 ? 'zdjęcia' : 'zdjęć');
                UI.previewCountBadge.innerHTML = `<i data-lucide="images" class="w-3.5 h-3.5 mr-1.5"></i> Wybrane zdjęcia (${count} ${noun})`;
            }
            
            const filesToShow = STATE.filesToUpload;
            const usePreviews = UI.showPreviews.checked;
            
            for (let index = 0; index < filesToShow.length; index++) {
                const fileData = filesToShow[index];
                const isArw = fileData.file.name.toLowerCase().endsWith('.arw');
                const item = document.createElement('div');
                item.className = 'thumbnail-item aspect-square bg-[#1a1a32] rounded-xl flex items-center justify-center p-1 border border-[#3f3f6e] overflow-hidden relative shadow-sm group';
                
                if (!usePreviews) {
                    item.innerHTML = `
                        <div class="flex flex-col items-center text-[10px] text-gray-400 text-center space-y-1 p-1">
                            <i data-lucide="${isArw ? 'file-digit' : 'image'}" class="w-6 h-6 text-cyan-400"></i>
                            <span class="truncate w-14 font-mono">${fileData.file.name}</span>
                        </div>
                        <div class="thumbnail-remove-btn" data-index="${index}" title="Usuń plik">&times;</div>
                    `;
                    UI.filePreviews.appendChild(item);
                    continue;
                }

                item.innerHTML = `<img src="" class="max-w-full max-h-full object-contain opacity-0 transition-opacity duration-300 rounded-lg"><div class="thumbnail-remove-btn" data-index="${index}" title="Usuń plik">&times;</div>`;
                const img = item.querySelector('img');
                UI.filePreviews.appendChild(item);

                const showImg = (src) => { 
                    img.src = src; 
                    img.classList.remove('opacity-0'); 
                    if (src.startsWith('blob:')) window._thumbnailUrls.push(src);
                };
                
                if (isArw) {
                    ImageProcessor.convertArwToJpeg(fileData.file).then(blob => { 
                        const url = URL.createObjectURL(blob); 
                        showImg(url); 
                    }).catch(() => img.alt = 'ARW');
                } else {
                    const url = URL.createObjectURL(fileData.file);
                    showImg(url);
                }
            }
            lucide.createIcons();
        };

        const processSingleFile = async (fileData, options, index) => { 
            const file = fileData.file; 
            const sequencePrefix = String(index + 1).padStart(4, '0'); 
            if (STATE.isProcessingCancelled) return { success: false, status: 'cancelled' }; 
            
            const listItem = document.createElement('div'); 
            listItem.className = 'bg-[#1a1a2e] p-3 rounded-xl border border-[#3f3f6e] flex justify-between items-center animate-in fade-in slide-in-from-left-4 duration-300'; 
            listItem.innerHTML = `<span class="text-[10px] font-mono text-gray-400">${sequencePrefix}_${file.name}</span><span class="status text-[10px] font-bold px-2 py-1 rounded bg-[#151525]">Czekam...</span>`;
            const statusSpan = listItem.querySelector('.status');
            UI.statusListContainer.prepend(listItem); 
            
            const setStatus = (msg, color, bgColor = 'bg-[#151525]') => { 
                statusSpan.textContent = msg; 
                statusSpan.className = `status text-[10px] font-bold px-2 py-1 rounded ${color} ${bgColor}`; 
            };

            try { 
                setStatus('KOMPRESJA', 'text-blue-400', 'bg-blue-500/10');
                const { mainImageBlob, thumbImageBlob } = await ImageProcessor.process(file, options); 
                
                setStatus('SZYFROWANIE', 'text-purple-400', 'bg-purple-500/10');
                const encMain = await CryptoHelper.encrypt(await mainImageBlob.arrayBuffer(), STATE.encryptionKey.key); 
                const encThumb = await CryptoHelper.encrypt(await thumbImageBlob.arrayBuffer(), STATE.encryptionKey.key); 
                const encOriginalName = await CryptoHelper.encryptString(file.name, STATE.encryptionKey.key);
                
                setStatus('WYSYŁANIE', 'text-orange-400', 'bg-orange-500/10');
                const fd = new FormData(); 
                fd.append('csrf_token', CSRF_TOKEN);
                fd.append('main_encrypted_file', new Blob([encMain])); 
                fd.append('thumb_encrypted_file', new Blob([encThumb])); 
                fd.append('original_filename', encOriginalName); 
                fd.append('sequence_prefix', sequencePrefix); 
                fd.append('album_id', STATE.targetAlbumId); 
                fd.append('encryption_key_hash', STATE.encryptionKey.hash); // Wysyłamy hash
                
                const res = await fetch('', { method: 'POST', body: fd }); 
                const json = await res.json(); 
                if (!res.ok || !json.success) throw new Error(json.error || 'Błąd serwera'); 
                
                setStatus('GOTOWE', 'text-green-400', 'bg-green-500/10');
                return { success: true, originalSize: file.size, finalSize: json.final_size }; 
            } catch (e) { 
                setStatus('BŁĄD', 'text-red-500', 'bg-red-500/10'); 
                console.error(e); 
                return { success: false, finalSize: 0 }; 
            } 
        };

        const handleFormSubmit = async (event) => { 
            event.preventDefault(); 
            if (STATE.isProcessing || STATE.filesToUpload.length === 0) return; 

            // 1. Resolve Album
            const albumMode = document.querySelector('input[name="album_mode"]:checked').value;
            if (albumMode === 'new') {
                const iName = document.getElementById('newInternalName').value.trim();
                const pTitle = document.getElementById('newPublicTitle').value.trim();
                if(!iName || !pTitle) return alert('Podaj nazwę wewnętrzną i tytuł albumu.');
                
                // Create Album via AJAX
                try {
                    const fd = new FormData(); 
                    fd.append('csrf_token', CSRF_TOKEN);
                    fd.append('action', 'create_album_ajax');
                    fd.append('internal_name', iName); 
                    fd.append('public_title', pTitle);
                    const res = await fetch('', {method: 'POST', body: fd});
                    const json = await res.json();
                    if(!json.success) throw new Error(json.error);
                    STATE.targetAlbumId = json.id;
                    STATE.targetAlbumSlug = json.slug;
                } catch(e) { return alert('Nie udało się stworzyć albumu: ' + e.message); }
            } else {
                STATE.targetAlbumId = UI.existingAlbumSelect.value;
                if(!STATE.targetAlbumId) return alert('Wybierz album.');
                STATE.targetAlbumSlug = UI.existingAlbumSelect.options[UI.existingAlbumSelect.selectedIndex].dataset.slug;
            }

            // 2. Resolve Key
            const keyMode = document.querySelector('input[name="key_mode"]:checked').value;
            try {
                if(keyMode === 'new') {
                    const k = await CryptoHelper.generateKey();
                    const b64 = await CryptoHelper.exportKeyToBase64(k);
                    STATE.encryptionKey = { key: k, raw: b64, hex: b64, b64: b64, hash: await CryptoHelper.sha256(b64) };
                } else {
                    const rawKey = UI.existingKeyInput.value.trim();
                    const importedKey = await CryptoHelper.importKey(rawKey, ["encrypt"]);
                    STATE.encryptionKey = { key: importedKey, raw: rawKey, hex: rawKey, b64: rawKey, hash: await CryptoHelper.sha256(rawKey) };
                }
                
                // Automatycznie zapisz klucz do sejfu administratora
                try {
                    await fetch('vault_api.php', {
                        method: 'POST',
                        headers: { 
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': CSRF_TOKEN
                        },
                        body: JSON.stringify({ 
                            action: 'add_key', 
                            key_hex: STATE.encryptionKey.hex, 
                            key_hash: STATE.encryptionKey.hash 
                        })
                    });
                } catch(e) { console.error("Nie udało się zapisać klucza do sejfu:", e); }
            } catch(e) { return alert('Błąd klucza: ' + e.message); }

            startProcessingUI(STATE.filesToUpload.length); 
            
            const options = { max_edge: UI.compression.maxEdge.value, compression_level: UI.compression.levelSlider.value / 100 }; 
            const stats = { successCount: 0, totalFinalSize: 0, completedCount: 0 }; 
            
            const startTime = Date.now(); 
            const queue = STATE.filesToUpload.map((fileData, index) => ({ fileData, index })); 
            
            const worker = async () => { 
                while (queue.length > 0) { 
                    if (STATE.isProcessingCancelled) break; 
                    const { fileData, index } = queue.shift(); 
                    const result = await processSingleFile(fileData, options, index); 
                    stats.completedCount++; 
                    if (result.success) { stats.successCount++; stats.totalFinalSize += result.finalSize; } 
                    
                    const percent = Math.round((stats.completedCount / STATE.filesToUpload.length) * 100);
                    UI.progress.text.textContent = `${stats.completedCount} / ${STATE.filesToUpload.length}`; 
                    UI.progress.bar.style.width = `${percent}%`; 
                    UI.progress.percentage.textContent = `${percent}%`;
                } 
            }; 
            await Promise.all(Array(CONFIG.UPLOAD_CONCURRENCY).fill(null).map(worker)); 
            finishProcessingUI({ totalTime: ((Date.now() - startTime) / 1000).toFixed(2), successCount: stats.successCount, fileCount: STATE.filesToUpload.length }); 
        };

        const finishProcessingUI = (stats) => { 
            STATE.isProcessing = false; 
            clearInterval(STATE.statusMessageInterval); 
            UI.dynamicButtonContainer.innerHTML = ''; 
            
            if (STATE.isProcessingCancelled) { 
                UI.finalSummary.innerHTML = `<p class="text-center text-yellow-400 font-bold mb-4">Proces anulowany.</p>`; 
            } else { 
                const host = window.location.protocol + "//" + window.location.host;
                // Zakładamy, że album.html jest w katalogu nadrzędnym względem admin/czyli root
                // admin/upload.php -> host/admin/upload.php. Root: host/
                // Jeśli jesteśmy w podkatalogu, trzeba to wykryć.
                // Prościej: użyć relatywnej ścieżki ../album.html
                
                // Konstrukcja linku:
                const path = window.location.pathname; 
                const projectRoot = path.substring(0, path.lastIndexOf('/admin/')); 
                const albumKey = STATE.encryptionKey.b64 || STATE.encryptionKey.hex || STATE.encryptionKey.raw;
                const albumUrl = `${host}${projectRoot}/album.html?s=${STATE.targetAlbumSlug}#${albumKey}`;

                UI.finalSummary.innerHTML = `
                    <div class="bg-[#151525] p-8 rounded-3xl text-center border border-[#3f3f6e] shadow-2xl relative overflow-hidden group">
                        <div class="absolute top-0 right-0 p-4 opacity-5 pointer-events-none">
                            <i data-lucide="party-popper" class="w-32 h-32 text-white"></i>
                        </div>

                        <div class="w-20 h-20 bg-green-500 rounded-2xl flex items-center justify-center mx-auto mb-6 shadow-xl shadow-green-500/20">
                            <i data-lucide="check" class="text-white w-10 h-10"></i>
                        </div>
                        <h2 class="text-3xl font-black text-white mb-2">Pomyślnie Wysłano!</h2>
                        <p class="text-gray-400 mb-10">Prześlano ${stats.successCount} z ${stats.fileCount} zdjęć do Twojej galerii.</p>
                        
                        <div class="grid gap-4 max-w-lg mx-auto">
                            <div class="bg-[#1a1a2e] p-5 rounded-2xl border border-[#3f3f6e] text-left group/link relative">
                                <p class="text-[10px] text-gray-500 uppercase font-black mb-3 flex items-center">
                                    <i data-lucide="link" class="w-3 h-3 mr-2"></i> Pełny Link do Galerii (z kluczem)
                                </p>
                                <div class="flex items-center gap-3">
                                    <input readonly value="${albumUrl}" class="flex-grow bg-transparent text-cyan-400 text-sm font-mono border-none focus:ring-0 p-0 overflow-hidden text-ellipsis">
                                    <button onclick="navigator.clipboard.writeText('${albumUrl}'); this.classList.add('bg-green-500'); this.innerHTML='<i data-lucide=\'check\'></i>'" class="bg-[#2c2c54] hover:bg-[#3f3f6e] text-white p-2.5 rounded-xl transition-all shadow-lg flex items-center justify-center min-w-[44px]">
                                        <i data-lucide="copy" class="w-4 h-4"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="bg-[#1a1a2e] p-5 rounded-2xl border border-[#3f3f6e] text-left group/key relative">
                                <p class="text-[10px] text-gray-500 uppercase font-black mb-3 flex items-center">
                                    <i data-lucide="key" class="w-3 h-3 mr-2"></i> Sam Klucz Szyfrowania (Base64)
                                </p>
                                <div class="flex items-center gap-3">
                                    <input readonly value="${albumKey}" class="flex-grow bg-transparent text-purple-400 text-xs font-mono border-none focus:ring-0 p-0 overflow-hidden text-ellipsis">
                                    <button onclick="navigator.clipboard.writeText('${albumKey}'); this.classList.add('bg-green-500'); this.innerHTML='<i data-lucide=\'check\'></i>'" class="bg-[#2c2c54] hover:bg-[#3f3f6e] text-white p-2.5 rounded-xl transition-all shadow-lg flex items-center justify-center min-w-[44px]">
                                        <i data-lucide="copy" class="w-4 h-4"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <button onclick="location.reload()" class="mt-8 btn-primary text-white font-black py-4 px-8 rounded-2xl transition-all shadow-2xl uppercase tracking-widest text-sm flex items-center justify-center gap-3 w-full max-w-sm mx-auto">
                        <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
                        Wgraj kolejne
                    </button>
                `;
                lucide.createIcons();
                
                if (stats.successCount > 0) { 
                    const script = document.createElement('script');
                    script.src = "https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js";
                    script.onload = () => confetti({ particleCount: 150, spread: 70, origin: { y: 0.6 } });
                    document.body.appendChild(script);
                }
            } 
            UI.finalSummary.classList.remove('hidden'); 
        };

        const startProcessingUI = (fileCount) => { 
            STATE.isProcessing = true; 
            document.getElementById('formContainer').classList.add('hidden'); 
            UI.mainButtonContainer.classList.add('hidden'); 
            UI.progress.container.classList.remove('hidden'); 
            UI.finalSummary.classList.add('hidden'); 
            UI.statusListContainer.innerHTML = ''; 
            UI.progress.text.textContent = `0 / ${fileCount}`; 
            UI.progress.bar.style.width = '0%'; 
            UI.progress.percentage.textContent = '0%'; 
            
            UI.dynamicButtonContainer.innerHTML = '';
            // Button Cancel not implemented here to keep simple, just reload
        };
        
        // --- Init Listeners ---
        UI.uploadForm.addEventListener('submit', handleFormSubmit);
        UI.imageInput.addEventListener('change', (e) => handleFiles(e.target.files));
        UI.dropZone.addEventListener('click', (e) => { if (e.target.tagName !== 'LABEL' && e.target.id !== 'imageInput') UI.imageInput.click(); });
        UI.dropZone.addEventListener('dragover', (e) => { e.preventDefault(); UI.dropZone.classList.add('drag-over'); });
        UI.dropZone.addEventListener('dragleave', () => UI.dropZone.classList.remove('drag-over'));
        UI.dropZone.addEventListener('drop', (e) => { e.preventDefault(); UI.dropZone.classList.remove('drag-over'); handleFiles(e.dataTransfer.files); });
        UI.compression.levelSlider.addEventListener('input', () => { UI.compression.levelValue.textContent = `${UI.compression.levelSlider.value}%`; });
        UI.showPreviews.addEventListener('change', () => renderThumbnails());
        UI.filePreviews.addEventListener('click', (e) => {
            const removeBtn = e.target.closest('.thumbnail-remove-btn');
            if (removeBtn) {
                const indexToRemove = parseInt(removeBtn.dataset.index);
                if (!isNaN(indexToRemove)) { STATE.filesToUpload.splice(indexToRemove, 1); renderThumbnails(); updateSubmitButton(); }
            }
        });
        if (UI.clearAllFilesBtn) {
            UI.clearAllFilesBtn.addEventListener('click', () => {
                STATE.filesToUpload = [];
                renderThumbnails();
                updateSubmitButton();
            });
        }

        // Segmented card click helpers
        if (UI.labelAlbumExisting) {
            UI.labelAlbumExisting.addEventListener('click', () => {
                const r = document.querySelector('input[name="album_mode"][value="existing"]');
                if (r) { r.checked = true; r.dispatchEvent(new Event('change')); }
            });
        }
        if (UI.labelAlbumNew) {
            UI.labelAlbumNew.addEventListener('click', () => {
                const r = document.querySelector('input[name="album_mode"][value="new"]');
                if (r) { r.checked = true; r.dispatchEvent(new Event('change')); }
            });
        }
        if (UI.labelKeyNew) {
            UI.labelKeyNew.addEventListener('click', () => {
                const r = document.querySelector('input[name="key_mode"][value="new"]');
                if (r) { r.checked = true; r.dispatchEvent(new Event('change')); }
            });
        }
        if (UI.labelKeyExisting) {
            UI.labelKeyExisting.addEventListener('click', () => {
                const r = document.querySelector('input[name="key_mode"][value="existing"]');
                if (r) { r.checked = true; r.dispatchEvent(new Event('change')); }
            });
        }
    });
    </script>
    <script src="https://cdn.jsdelivr.net/gh/WowkDigital/WowkDigitalFooter@latest/wowk-digital-footer.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            WowkDigitalFooter.init({
                siteName: 'Photo Proofing - Uploader',
                container: 'body',
                brandName: 'Wowk Digital',
                brandUrl: 'https://github.com/WowkDigital'
            });
        });
    </script>
</body>
</html>
