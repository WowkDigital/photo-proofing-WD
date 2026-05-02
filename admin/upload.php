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

        // Generujemy losową nazwę pliku z cyfr dla anonimowości
        do {
            $randomNumbers = '';
            for ($i = 0; $i < 15; $i++) {
                $randomNumbers .= mt_rand(0, 9);
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
        send_json_error('Błąd serwera: ' . $e->getMessage(), 500);
    }
    exit;
}

// --- Widok HTML ---
// Pobierz albumy do listy
$stmt = $pdo->query("SELECT id, internal_name, slug FROM albums ORDER BY created_at DESC");
$albums = $stmt->fetchAll(PDO::FETCH_ASSOC);
$preselectedAlbumId = $_GET['album_id'] ?? 0;
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Uploader - Admin Panel</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/exifreader@4.21.1/dist/exif-reader.min.js"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap');

        body {
            font-family: 'Inter', sans-serif;
            background-color: #1a1a2e;
            color: #e0e0e0;
        }

        .dashboard-header {
            background-color: rgba(26, 26, 46, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid #2c2c54;
        }

        .card {
            background-color: #2c2c54;
            border: 1px solid #3f3f6e;
            border-radius: 1.5rem;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3);
        }

        .btn-primary {
            background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
            box-shadow: 0 4px 6px -1px rgba(6, 182, 212, 0.2);
            transition: all 0.2s;
        }
        
        .btn-primary:hover:not(:disabled) {
            box-shadow: 0 6px 8px -1px rgba(6, 182, 212, 0.3);
            transform: translateY(-1px);
        }

        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .fade-in { animation: fadeIn 0.5s ease-out forwards; }
        #imageInput { display: none; }
        #drop-zone { border: 2px dashed #3f3f6e; transition: all 0.3s ease; background: rgba(255,255,255,0.02); }
        #drop-zone.drag-over { border-color: #06b6d4; background-color: rgba(6, 182, 212, 0.1); }
        .thumbnail-item { position: relative; animation: fadeIn 0.3s ease-out; }
        .thumbnail-remove-btn { position: absolute; top: -0.5rem; right: -0.5rem; background-color: #ef4444; color: white; width: 24px; height: 24px; border-radius: 9999px; display: flex; align-items: center; justify-content: center; font-weight: bold; cursor: pointer; border: 2px solid #1f2937; opacity: 0; transition: opacity 0.2s ease; transform: scale(0.9); z-index: 10; }
        .thumbnail-item:hover .thumbnail-remove-btn { opacity: 1; transform: scale(1); }
        
        input[type="text"], input[type="number"], select {
            background-color: #151525;
            border: 1px solid #3f3f6e;
            color: white;
            transition: all 0.2s;
        }
        input[type="text"]:focus, input[type="number"]:focus, select:focus {
            border-color: #06b6d4;
            ring: 2px;
            ring-color: rgba(6, 182, 212, 0.2);
            outline: none;
        }
    </style>
</head>
<body class="min-h-screen flex flex-col">
    <!-- Navbar -->
    <header class="dashboard-header sticky top-0 z-40 w-full mb-8">
        <div class="container mx-auto px-4 py-4 flex flex-col md:flex-row justify-between items-center gap-4">
            <div class="flex items-center space-x-3">
                <a href="index.php" class="bg-gradient-to-br from-cyan-500 to-blue-600 p-2.5 rounded-lg shadow-lg hover:scale-105 transition-transform">
                    <i data-lucide="arrow-left" class="w-6 h-6 text-white"></i>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-white tracking-tight">Prześlij Zdjęcia</h1>
                    <p class="text-xs text-gray-400 font-medium">Panel Administratora</p>
                </div>
            </div>
            <div id="statusMessage" class="text-cyan-400 text-sm font-medium"></div>
        </div>
    </header>

    <div class="container mx-auto px-4 max-w-5xl flex-grow pb-12">
        <div class="card p-6 md:p-10">
            <div class="text-center mb-10">
                <h2 class="text-3xl font-bold text-white">Dodaj i Zaszyfruj Galerię</h2>
                <p class="text-gray-400 mt-2">Wszystkie zdjęcia zostaną zoptymalizowane i zaszyfrowane przed wysłaniem.</p>
            </div>

        <div id="formContainer">
            <form id="uploadForm" novalidate class="space-y-8">
                
                <!-- SEKCJA: WYBÓR ALBUMU -->
                <div class="bg-[#151525] p-6 rounded-2xl border border-[#3f3f6e] relative overflow-hidden group">
                    <div class="absolute top-0 right-0 p-4 opacity-5 group-hover:opacity-10 transition-opacity">
                        <i data-lucide="folder" class="w-24 h-24 text-white"></i>
                    </div>
                    <h3 class="text-lg font-bold text-white mb-6 flex items-center">
                        <span class="w-8 h-8 bg-cyan-500/20 rounded-lg flex items-center justify-center mr-3">
                            <i data-lucide="folder" class="w-4 h-4 text-cyan-400"></i>
                        </span>
                        Miejsce Docelowe
                    </h3>
                    
                    <div class="flex flex-col md:flex-row gap-8 relative z-10">
                        <!-- Opcja 1: Istniejący -->
                        <div class="flex-1 space-y-4">
                            <label class="flex items-center space-x-3 cursor-pointer group p-3 rounded-xl hover:bg-white/5 transition-colors border border-transparent hover:border-white/5">
                                <input type="radio" name="album_mode" value="existing" <?php echo empty($albums) ? '' : 'checked'; ?> class="w-5 h-5 text-cyan-600 bg-[#1a1a2e] border-[#3f3f6e] focus:ring-cyan-600">
                                <span class="text-gray-300 font-semibold group-hover:text-white transition-colors">Istniejący Album</span>
                            </label>
                            <select id="existingAlbumSelect" class="w-full rounded-xl p-3.5 text-sm font-medium outline-none disabled:opacity-30 disabled:cursor-not-allowed">
                                <?php if(empty($albums)): ?><option value="">Brak dostępnych albumów</option><?php endif; ?>
                                <?php foreach ($albums as $a): ?>
                                    <option value="<?php echo $a['id']; ?>" data-slug="<?php echo $a['slug']; ?>" <?php echo $a['id'] == $preselectedAlbumId ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($a['internal_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="hidden md:block w-px bg-[#3f3f6e]"></div>

                        <!-- Opcja 2: Nowy -->
                        <div class="flex-1 space-y-4">
                            <label class="flex items-center space-x-3 cursor-pointer group p-3 rounded-xl hover:bg-white/5 transition-colors border border-transparent hover:border-white/5">
                                <input type="radio" name="album_mode" value="new" <?php echo empty($albums) ? 'checked' : ''; ?> class="w-5 h-5 text-cyan-600 bg-[#1a1a2e] border-[#3f3f6e] focus:ring-cyan-600">
                                <span class="text-gray-300 font-semibold group-hover:text-white transition-colors">Zupełnie Nowy Album</span>
                            </label>
                            <div id="newAlbumInputs" class="space-y-3 <?php echo empty($albums) ? '' : 'opacity-30 pointer-events-none'; ?> transition-all">
                                <input type="text" id="newInternalName" name="new_internal_name" placeholder="Nazwa w panelu (np. Sesja Anny)" class="w-full rounded-xl p-3.5 text-sm font-medium">
                                <input type="text" id="newPublicTitle" name="new_public_title" placeholder="Tytuł dla klienta (np. Twoja Galeria)" class="w-full rounded-xl p-3.5 text-sm font-medium">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SEKCJA: KLUCZ SZYFROWANIA -->
                <div class="bg-[#151525] p-6 rounded-2xl border border-[#3f3f6e] relative overflow-hidden group">
                     <div class="absolute top-0 right-0 p-4 opacity-5 group-hover:opacity-10 transition-opacity">
                        <i data-lucide="key" class="w-24 h-24 text-white"></i>
                    </div>
                    <h3 class="text-lg font-bold text-white mb-6 flex items-center">
                        <span class="w-8 h-8 bg-purple-500/20 rounded-lg flex items-center justify-center mr-3">
                            <i data-lucide="key" class="w-4 h-4 text-purple-400"></i>
                        </span>
                        Bezpieczeństwo (Klucz)
                    </h3>
                     <div class="space-y-4 relative z-10">
                        <label class="flex items-center space-x-3 cursor-pointer group p-3 rounded-xl hover:bg-white/5 transition-colors border border-transparent hover:border-white/5">
                            <input type="radio" name="key_mode" value="new" checked class="w-5 h-5 text-cyan-600 bg-[#1a1a2e] border-[#3f3f6e] focus:ring-cyan-600">
                            <span class="text-gray-300 group-hover:text-white transition-colors font-medium">Wygeneruj nowy, bezpieczny klucz <span class="text-[10px] bg-cyan-500/20 text-cyan-400 px-2 py-0.5 rounded-full ml-2">ZALECANE</span></span>
                        </label>
                        <div class="flex flex-col sm:flex-row gap-4 items-start sm:items-center p-3 rounded-xl hover:bg-white/5 transition-colors border border-transparent hover:border-white/5">
                            <label class="flex items-center space-x-3 cursor-pointer group flex-shrink-0">
                                <input type="radio" name="key_mode" value="existing" class="w-5 h-5 text-cyan-600 bg-[#1a1a2e] border-[#3f3f6e] focus:ring-cyan-600">
                                <span class="text-gray-300 group-hover:text-white transition-colors font-medium">Użyj własnego klucza</span>
                            </label>
                            <input type="text" id="existingKeyInput" name="existing_key_hex" placeholder="64 znaki hex..." class="flex-grow w-full rounded-xl p-2.5 text-xs text-cyan-400 font-mono outline-none opacity-30 pointer-events-none transition-all">
                        </div>
                     </div>
                </div>

                <div id="drop-zone" class="w-full text-center py-12 px-8 rounded-3xl mb-4 cursor-pointer hover:border-cyan-500/50 hover:bg-cyan-500/5 transition-all group">
                    <div class="bg-[#1a1a2e] w-20 h-20 rounded-2xl flex items-center justify-center mx-auto mb-6 shadow-xl border border-[#3f3f6e] group-hover:scale-110 transition-transform">
                        <i data-lucide="image-plus" class="w-10 h-10 text-cyan-400"></i>
                    </div>
                    <p class="text-xl font-bold text-white mb-2">Dodaj zdjęcia do kolejki</p>
                    <p class="text-sm text-gray-400 mb-8 max-w-xs mx-auto">Przeciągnij pliki tutaj lub kliknij przycisk poniżej, aby wybrać je z dysku.</p>
                    
                    <label for="imageInput" class="inline-flex items-center bg-[#2c2c54] hover:bg-[#3f3f6e] text-white font-bold py-3 px-8 rounded-2xl transition-all cursor-pointer shadow-lg border border-[#3f3f6e]">
                        <i data-lucide="plus" class="w-5 h-5 mr-2"></i> Wybierz z folderu
                    </label>
                    <input type="file" id="imageInput" name="images[]" accept="image/png, image/jpeg, image/gif, .arw" multiple>
                    <div class="mt-6 flex justify-center gap-4 text-[10px] font-bold text-gray-500 uppercase tracking-widest">
                        <span>JPG / PNG</span>
                        <span class="w-1 h-1 bg-gray-700 rounded-full my-auto"></span>
                        <span>ARW (RAW)</span>
                    </div>
                </div>

                <div id="file-previews" class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 lg:grid-cols-8 gap-4 mb-4 bg-[#151525] p-6 rounded-2xl border border-[#3f3f6e] min-h-[120px] hidden"></div>
                
                <div class="bg-[#151525] p-6 rounded-2xl border border-[#3f3f6e]">
                    <h3 class="text-xs font-bold text-gray-500 uppercase tracking-widest mb-6 flex items-center">
                        <i data-lucide="settings-2" class="w-4 h-4 mr-2"></i> Parametry Przetwarzania
                    </h3>
                    <div class="grid md:grid-cols-2 gap-x-10 gap-y-6">
                        <div class="flex flex-col space-y-2">
                            <div class="flex justify-between items-center">
                                <label for="maxEdge" class="text-sm font-semibold text-gray-300">Max. Rozmiar (px)</label>
                                <span class="text-[10px] text-gray-500">Dłuższa krawędź</span>
                            </div>
                            <input type="number" id="maxEdge" name="max_edge" min="100" value="2000" class="rounded-xl p-3 text-sm font-bold">
                        </div>
                        <div class="flex flex-col space-y-2">
                            <div class="flex justify-between items-center">
                                <label for="compressionLevel" class="text-sm font-semibold text-gray-300">Jakość Pliku</label>
                                <span id="compressionLevelValue" class="text-sm font-bold text-cyan-400">85%</span>
                            </div>
                            <div class="pt-2">
                                <input type="range" id="compressionLevel" min="1" max="100" value="85" class="w-full h-1.5 bg-[#1a1a2e] rounded-lg appearance-none cursor-pointer accent-cyan-500">
                            </div>
                        </div>
                        <div class="md:col-span-2 pt-4 border-t border-[#3f3f6e]">
                             <label class="flex items-center space-x-3 cursor-pointer group">
                                <div class="relative flex items-center">
                                    <input type="checkbox" id="showPreviews" class="peer h-5 w-5 cursor-pointer appearance-none rounded-md border border-[#3f3f6e] bg-[#1a1a2e] transition-all checked:bg-cyan-500 checked:border-cyan-500">
                                    <i data-lucide="check" class="absolute h-3.5 w-3.5 text-white opacity-0 peer-checked:opacity-100 top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 transition-opacity pointer-events-none"></i>
                                </div>
                                <span class="text-xs text-gray-400 group-hover:text-white transition-colors">Pokaż miniatury w oknie uploader (może obciążyć procesor)</span>
                            </label>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <div id="mainButtonContainer" class="mt-10">
            <button type="submit" form="uploadForm" id="submitBtn" class="btn-primary w-full text-white font-black py-4 px-6 rounded-2xl transition-all disabled:opacity-30 disabled:cursor-not-allowed shadow-2xl uppercase tracking-widest text-sm flex items-center justify-center gap-3" disabled>
                <i data-lucide="lock" class="w-5 h-5"></i>
                Wybierz pliki, aby rozpocząć
            </button>
        </div>
        
        <div id="progressContainer" class="mt-10 hidden bg-[#151525] p-6 rounded-2xl border border-[#3f3f6e] animate-in fade-in slide-in-from-bottom-4">
            <div class="flex justify-between items-end mb-4">
                <div>
                    <span class="text-[10px] font-bold text-gray-500 uppercase tracking-widest block mb-1">Status Przetwarzania</span>
                    <span id="progressText" class="text-xl font-black text-white">0 / 0</span>
                </div>
                <div class="text-right">
                    <span class="text-[10px] font-bold text-cyan-400 uppercase tracking-widest block mb-1">Postęp</span>
                    <span id="percentageText" class="text-xl font-black text-cyan-400">0%</span>
                </div>
            </div>
            <div class="w-full bg-[#1a1a2e] rounded-full h-3 p-0.5 border border-[#3f3f6e] overflow-hidden">
                <div id="progressBar" class="bg-gradient-to-r from-cyan-600 to-blue-500 h-full rounded-full transition-all duration-300 ease-out shadow-[0_0_15px_rgba(6,182,212,0.5)]" style="width: 0%"></div>
            </div>
        </div>
        
        <div id="finalSummary" class="mt-8 hidden animate-in fade-in zoom-in duration-500"></div>
        <div id="statusListContainer" class="mt-6 max-h-60 overflow-y-auto custom-scrollbar space-y-2 pr-2"></div>
        <div id="dynamicButtonContainer" class="mt-10 flex flex-col sm:flex-row gap-4 justify-center"></div>
    </div>
    
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        lucide.createIcons();
        const CONFIG = { MAX_FILES_PER_BATCH: 2000, UPLOAD_CONCURRENCY: 3, THUMBNAIL_WIDTH: 400, STATUS_MESSAGES: { processing: ["Szyfruję pliki...", "Kompresuję obrazy...", "Przetwarzam lokalnie...", "Wysyłam na serwer..."], done: ["Sukces! Wszystko gotowe."], stopped: ["Proces zatrzymany."] } };
        const STATE = { filesToUpload: [], isProcessing: false, isProcessingCancelled: false, statusMessageInterval: null, encryptionKey: null, targetAlbumId: null, targetAlbumSlug: null };
        const UI = { 
            uploadForm: document.getElementById('uploadForm'), 
            imageInput: document.getElementById('imageInput'), 
            dropZone: document.getElementById('drop-zone'), 
            filePreviews: document.getElementById('file-previews'), 
            mainButtonContainer: document.getElementById('mainButtonContainer'), 
            dynamicButtonContainer: document.getElementById('dynamicButtonContainer'), 
            submitBtn: document.getElementById('submitBtn'), 
            statusMessage: document.getElementById('statusMessage'), 
            progress: { container: document.getElementById('progressContainer'), bar: document.getElementById('progressBar'), text: document.getElementById('progressText'), percentage: document.getElementById('percentageText') }, 
            finalSummary: document.getElementById('finalSummary'), 
            statusListContainer: document.getElementById('statusListContainer'), 
            compression: { maxEdge: document.getElementById('maxEdge'), levelSlider: document.getElementById('compressionLevel'), levelValue: document.getElementById('compressionLevelValue') },
            showPreviews: document.getElementById('showPreviews'),
            
            // New UI
            albumModeRadios: document.getElementsByName('album_mode'),
            existingAlbumSelect: document.getElementById('existingAlbumSelect'),
            newAlbumInputs: document.getElementById('newAlbumInputs'),
            keyModeRadios: document.getElementsByName('key_mode'),
            existingKeyInput: document.getElementById('existingKeyInput')
        };

        // --- UI Logic for Album/Key Selection ---
        function updateUIState() {
            const albumMode = document.querySelector('input[name="album_mode"]:checked').value;
            const isNewAlbum = albumMode === 'new';
            
            UI.existingAlbumSelect.disabled = isNewAlbum;
            if(isNewAlbum) {
                UI.existingAlbumSelect.classList.add('opacity-50');
                UI.newAlbumInputs.classList.remove('opacity-50', 'pointer-events-none');
            } else {
                UI.existingAlbumSelect.classList.remove('opacity-50');
                UI.newAlbumInputs.classList.add('opacity-50', 'pointer-events-none');
            }

            const keyMode = document.querySelector('input[name="key_mode"]:checked').value;
            const isExistingKey = keyMode === 'existing';
            if(isExistingKey) {
                UI.existingKeyInput.classList.remove('opacity-50', 'pointer-events-none');
                UI.existingKeyInput.focus();
            } else {
                UI.existingKeyInput.classList.add('opacity-50', 'pointer-events-none');
            }
        }
        UI.albumModeRadios.forEach(r => r.addEventListener('change', updateUIState));
        UI.keyModeRadios.forEach(r => r.addEventListener('change', updateUIState));
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
            async importKeyFromHex(hex) { 
                if(!hex || hex.length !== 64) throw new Error("Nieprawidłowy format klucza (wymagane 64 znaki hex).");
                const buf = new Uint8Array(hex.match(/.{1,2}/g).map(byte => parseInt(byte, 16)));
                return await window.crypto.subtle.importKey("raw", buf, { name: "AES-GCM" }, true, ["encrypt"]);
            },
            async exportKeyToHex(key) { const buf = await window.crypto.subtle.exportKey("raw", key); return Array.from(new Uint8Array(buf)).map(b => b.toString(16).padStart(2, '0')).join(''); }, 
            async sha256(hex) {
                const msgBuffer = new TextEncoder().encode(hex);
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
        
        const updateSubmitButton = () => { const hasFiles = STATE.filesToUpload.length > 0; UI.submitBtn.disabled = !hasFiles; UI.submitBtn.textContent = hasFiles ? `Przetwórz i wyślij (${STATE.filesToUpload.length} plików)` : 'Wybierz pliki, aby rozpocząć'; };
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
            UI.filePreviews.classList.toggle('hidden', STATE.filesToUpload.length === 0);
            
            const filesToShow = STATE.filesToUpload;
            const usePreviews = UI.showPreviews.checked;
            
            for (let index = 0; index < filesToShow.length; index++) {
                const fileData = filesToShow[index];
                const isArw = fileData.file.name.toLowerCase().endsWith('.arw');
                const item = document.createElement('div');
                item.className = 'thumbnail-item aspect-square bg-gray-700/50 rounded-md flex items-center justify-center p-1 border border-gray-600 overflow-hidden';
                
                if (!usePreviews) {
                    item.innerHTML = `
                        <div class="flex flex-col items-center text-[10px] text-gray-500 text-center space-y-1">
                            <i data-lucide="${isArw ? 'file-digit' : 'image'}" class="w-6 h-6 text-gray-600"></i>
                            <span class="truncate w-16 px-1">${fileData.file.name}</span>
                        </div>
                        <div class="thumbnail-remove-btn" data-index="${index}"><span>&times;</span></div>
                    `;
                    UI.filePreviews.appendChild(item);
                    continue;
                }

                item.innerHTML = `<img src="" class="max-w-full max-h-full object-contain opacity-0 transition-opacity duration-300"><div class="thumbnail-remove-btn" data-index="${index}"><span>&times;</span></div>`;
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
                    const fd = new FormData(); fd.append('action', 'create_album_ajax');
                    fd.append('internal_name', iName); fd.append('public_title', pTitle);
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
                    const hex = await CryptoHelper.exportKeyToHex(k);
                    STATE.encryptionKey = { key: k, hex: hex, hash: await CryptoHelper.sha256(hex) };
                } else {
                    const hex = UI.existingKeyInput.value.trim();
                    STATE.encryptionKey = { key: await CryptoHelper.importKeyFromHex(hex), hex: hex, hash: await CryptoHelper.sha256(hex) };
                }
                
                // Automatycznie zapisz klucz do sejfu administratora
                try {
                    await fetch('vault_api.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
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
                const albumUrl = `${host}${projectRoot}/album.html?s=${STATE.targetAlbumSlug}#${STATE.encryptionKey.hex}`;

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
                                    <i data-lucide="key" class="w-3 h-3 mr-2"></i> Sam Klucz Szyfrowania (HEX)
                                </p>
                                <div class="flex items-center gap-3">
                                    <input readonly value="${STATE.encryptionKey.hex}" class="flex-grow bg-transparent text-purple-400 text-xs font-mono border-none focus:ring-0 p-0 overflow-hidden text-ellipsis">
                                    <button onclick="navigator.clipboard.writeText('${STATE.encryptionKey.hex}'); this.classList.add('bg-green-500'); this.innerHTML='<i data-lucide=\'check\'></i>'" class="bg-[#2c2c54] hover:bg-[#3f3f6e] text-white p-2.5 rounded-xl transition-all shadow-lg flex items-center justify-center min-w-[44px]">
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
