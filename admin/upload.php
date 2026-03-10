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
        /* Style z base/upload_base.php */
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .fade-in { animation: fadeIn 0.5s ease-out forwards; }
        #imageInput { display: none; }
        #drop-zone { border: 3px dashed #4a5568; transition: all 0.3s ease; }
        #drop-zone.drag-over { border-color: #06b6d4; background-color: rgba(6, 182, 212, 0.1); }
        .thumbnail-item { position: relative; animation: fadeIn 0.3s ease-out; }
        .thumbnail-remove-btn { position: absolute; top: -0.5rem; right: -0.5rem; background-color: #ef4444; color: white; width: 24px; height: 24px; border-radius: 9999px; display: flex; align-items: center; justify-content: center; font-weight: bold; cursor: pointer; border: 2px solid #1f2937; opacity: 0; transition: opacity 0.2s ease; transform: scale(0.9); }
        .thumbnail-item:hover .thumbnail-remove-btn { opacity: 1; transform: scale(1); }
    </style>
</head>
<body class="bg-gray-900 text-white flex flex-col items-center min-h-screen font-sans p-4">

    <div class="w-full max-w-4xl mb-4 flex justify-between items-center">
        <a href="index.php" class="text-gray-400 hover:text-white flex items-center transition-colors"><i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i> Powrót do Panelu</a>
    </div>

    <div class="bg-gray-800 p-8 rounded-2xl shadow-2xl w-full max-w-4xl border border-gray-700">
        <div class="text-center mb-6">
            <h1 class="text-3xl font-bold text-cyan-400">Prześlij i Zaszyfruj Zdjęcia</h1>
            <p id="statusMessage" class="text-gray-500 text-sm h-5 transition-all mt-1"></p>
        </div>

        <div id="formContainer">
            <form id="uploadForm" novalidate>
                
                <!-- SEKCJA: WYBÓR ALBUMU -->
                <div class="mb-8 bg-gray-900/50 p-6 rounded-xl border border-gray-700">
                    <h3 class="text-lg font-semibold text-white mb-4 flex items-center"><i data-lucide="folder" class="w-5 h-5 mr-2 text-cyan-400"></i> Wybierz Album</h3>
                    
                    <div class="flex flex-col md:flex-row gap-6">
                        <!-- Opcja 1: Istniejący -->
                        <div class="flex-1">
                            <label class="flex items-center space-x-3 cursor-pointer group mb-3">
                                <input type="radio" name="album_mode" value="existing" <?php echo empty($albums) ? '' : 'checked'; ?> class="w-4 h-4 text-cyan-600 bg-gray-700 border-gray-600 focus:ring-cyan-600">
                                <span class="text-gray-300 font-medium group-hover:text-white transition-colors">Dodaj do istniejącego albumu</span>
                            </label>
                            <select id="existingAlbumSelect" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-2.5 text-white focus:border-cyan-500 outline-none disabled:opacity-50 disabled:cursor-not-allowed">
                                <?php if(empty($albums)): ?><option value="">Brak albumów</option><?php endif; ?>
                                <?php foreach ($albums as $a): ?>
                                    <option value="<?php echo $a['id']; ?>" data-slug="<?php echo $a['slug']; ?>" <?php echo $a['id'] == $preselectedAlbumId ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($a['internal_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Opcja 2: Nowy -->
                        <div class="flex-1 block pl-6 border-l border-gray-700">
                            <label class="flex items-center space-x-3 cursor-pointer group mb-3">
                                <input type="radio" name="album_mode" value="new" <?php echo empty($albums) ? 'checked' : ''; ?> class="w-4 h-4 text-cyan-600 bg-gray-700 border-gray-600 focus:ring-cyan-600">
                                <span class="text-gray-300 font-medium group-hover:text-white transition-colors">Stwórz nowy album</span>
                            </label>
                            <div id="newAlbumInputs" class="space-y-3 <?php echo empty($albums) ? '' : 'opacity-50 pointer-events-none'; ?> transition-all">
                                <input type="text" id="newInternalName" placeholder="Nazwa wewnętrzna (dla Ciebie)" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-2.5 text-white focus:border-cyan-500 outline-none text-sm">
                                <input type="text" id="newPublicTitle" placeholder="Tytuł publiczny (dla Klienta)" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-2.5 text-white focus:border-cyan-500 outline-none text-sm">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SEKCJA: KLUCZ SZYFROWANIA -->
                <div class="mb-8 bg-gray-900/50 p-6 rounded-xl border border-gray-700">
                     <h3 class="text-lg font-semibold text-white mb-4 flex items-center"><i data-lucide="key" class="w-5 h-5 mr-2 text-cyan-400"></i> Klucz Szyfrowania</h3>
                     <div class="space-y-3">
                        <label class="flex items-center space-x-3 cursor-pointer group">
                            <input type="radio" name="key_mode" value="new" checked class="w-4 h-4 text-cyan-600 bg-gray-700 border-gray-600 focus:ring-cyan-600">
                            <span class="text-gray-300 group-hover:text-white transition-colors">Generuj <strong>nowy klucz</strong> (zalecane dla nowego albumu)</span>
                        </label>
                        <div class="flex flex-col sm:flex-row gap-3 items-start sm:items-center">
                            <label class="flex items-center space-x-3 cursor-pointer group flex-shrink-0">
                                <input type="radio" name="key_mode" value="existing" class="w-4 h-4 text-cyan-600 bg-gray-700 border-gray-600 focus:ring-cyan-600">
                                <span class="text-gray-300 group-hover:text-white transition-colors">Użyj <strong>istniejącego klucza</strong></span>
                            </label>
                            <input type="text" id="existingKeyInput" placeholder="Wklej tutaj klucz (64 znaki hex)..." class="flex-grow w-full sm:w-auto bg-gray-700 border border-gray-600 rounded-lg p-2 text-sm text-cyan-400 font-mono focus:border-cyan-500 outline-none opacity-50 pointer-events-none transition-all">
                        </div>
                     </div>
                </div>

                <div id="drop-zone" class="w-full text-center p-8 rounded-lg mb-4 cursor-pointer hover:bg-gray-700/50 transition-colors">
                    <i data-lucide="upload-cloud" class="w-12 h-12 mx-auto mb-4 text-gray-500"></i>
                    <p class="font-bold text-gray-300">Przeciągnij i upuść pliki tutaj</p>
                    <p class="text-sm text-gray-500 my-2">lub</p>
                    <label for="imageInput" class="inline-block bg-cyan-600 hover:bg-cyan-700 text-white font-semibold py-2 px-6 rounded-lg transition duration-300 cursor-pointer shadow-lg">Wybierz pliki</label>
                    <input type="file" id="imageInput" name="images[]" accept="image/png, image/jpeg, image/gif, .arw" multiple>
                    <p class="text-xs text-gray-600 mt-4">Obsługa: JPG, PNG, ARW (auto-konwersja)</p>
                </div>

                <div id="file-previews" class="grid grid-cols-4 md:grid-cols-6 gap-4 mb-4 bg-gray-900/50 p-4 rounded-lg min-h-[110px] hidden"></div>
                
                <fieldset class="border border-gray-600 rounded-lg p-4 mb-4">
                    <legend class="px-2 text-cyan-400 font-semibold text-sm">Opcje kompresji</legend>
                    <div class="grid md:grid-cols-2 gap-x-6 gap-y-4">
                        <div class="grid grid-cols-[auto_1fr] gap-x-4 items-center"><label for="maxEdge" class="text-sm font-medium text-gray-300 justify-self-end">Max. krawędź:</label><input type="number" id="maxEdge" min="100" value="2000" class="bg-gray-700 border border-gray-600 text-white text-sm rounded-lg focus:ring-cyan-500 focus:border-cyan-500 block w-full p-2.5"></div>
                        <div class="grid grid-cols-[auto_1fr] gap-x-4 items-center"><label for="compressionLevel" class="text-sm font-medium text-gray-300 justify-self-end">Jakość:</label><div class="flex items-center"><input type="range" id="compressionLevel" min="1" max="100" value="85" class="w-full h-2 bg-gray-700 rounded-lg appearance-none cursor-pointer"><span id="compressionLevelValue" class="ml-4 text-sm w-12 text-right">85%</span></div></div>
                    </div>
                </fieldset>
            </form>
        </div>

        <div id="mainButtonContainer"><button type="submit" form="uploadForm" id="submitBtn" class="w-full bg-cyan-600 hover:bg-cyan-700 text-white font-bold py-3 px-4 rounded-lg transition duration-300 disabled:bg-gray-600 disabled:cursor-not-allowed shadow-xl uppercase tracking-wide" disabled>Wybierz pliki, aby rozpocząć</button></div>
        
        <div id="progressContainer" class="mt-6 hidden">
            <div class="flex justify-between mb-1"><span class="text-base font-medium text-cyan-400">Całkowity postęp</span><span id="progressText" class="text-sm font-medium text-cyan-400">0 / 0</span></div>
            <div class="w-full bg-gray-700 rounded-full h-2.5"><div id="progressBar" class="bg-cyan-600 h-2.5 rounded-full transition-all duration-300 ease-out" style="width: 0%"></div></div>
        </div>
        
        <div id="finalSummary" class="mt-4 hidden animate-in fade-in zoom-in duration-300"></div>
        <div id="statusListContainer" class="mt-4 max-h-40 overflow-y-auto scrollbar-thin scrollbar-thumb-gray-600 scrollbar-track-gray-800"></div>
        <div id="dynamicButtonContainer" class="text-center mt-4"></div>
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
            progress: { container: document.getElementById('progressContainer'), bar: document.getElementById('progressBar'), text: document.getElementById('progressText') }, 
            finalSummary: document.getElementById('finalSummary'), 
            statusListContainer: document.getElementById('statusListContainer'), 
            compression: { maxEdge: document.getElementById('maxEdge'), levelSlider: document.getElementById('compressionLevel'), levelValue: document.getElementById('compressionLevelValue') },
            
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
                    i.onerror = err => reject(err); 
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
                    return await this.applyOrientation(blob, tiffData.orientation); 
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
        const readFileWithExif = async (file) => { try { const arrayBuffer = await file.arrayBuffer(); const tags = ExifReader.load(arrayBuffer); const dateStr = tags['DateTimeOriginal']?.description; if (dateStr) { const parsableDateStr = dateStr.replace(':', '-').replace(':', '-'); return { file, creationDate: new Date(parsableDateStr) }; } } catch (e) { console.warn(`Nie można odczytać EXIF z ${file.name}:`, e); } return { file, creationDate: new Date(file.lastModified) }; };
        
        const handleFiles = async (files) => {
            UI.statusMessage.textContent = 'Analizuję pliki...';
            try {
                const newFiles = Array.from(files).filter(f => !STATE.filesToUpload.some(item => item.file.name === f.name && item.file.size === f.size));
                if (STATE.filesToUpload.length + newFiles.length > CONFIG.MAX_FILES_PER_BATCH) { alert(`Limit to ${CONFIG.MAX_FILES_PER_BATCH} plików.`); return; }
                const newFilesWithData = await Promise.all(newFiles.map(readFileWithExif));
                STATE.filesToUpload.push(...newFilesWithData);
                STATE.filesToUpload.sort((a, b) => a.creationDate - b.creationDate);
                renderThumbnails();
                updateSubmitButton();
            } catch (error) { console.error("Błąd przetwarzania:", error); UI.statusMessage.textContent = 'Błąd dodawania plików.'; } finally { if (STATE.isProcessing === false) { UI.statusMessage.textContent = ''; } }
        };

        const renderThumbnails = () => { /* (Kod renderowania z base) */ 
            UI.filePreviews.innerHTML = '';
            UI.filePreviews.classList.toggle('hidden', STATE.filesToUpload.length === 0);
            STATE.filesToUpload.forEach((fileData, index) => {
                const item = document.createElement('div');
                item.className = 'thumbnail-item aspect-square bg-gray-700/50 rounded-md flex items-center justify-center p-1 border border-gray-600';
                item.innerHTML = `<img src="" class="max-w-full max-h-full object-contain opacity-0 transition-opacity duration-300"><div class="thumbnail-remove-btn" data-index="${index}"><span>&times;</span></div>`;
                const img = item.querySelector('img');
                
                const showImg = (src) => { img.src = src; img.classList.remove('opacity-0'); };
                
                if (fileData.file.name.toLowerCase().endsWith('.arw')) {
                    ImageProcessor.convertArwToJpeg(fileData.file).then(blob => { 
                        const url = URL.createObjectURL(blob); 
                        showImg(url); 
                        // Clean up URL later or let GC handle it
                    }).catch(() => img.alt = 'ARW');
                } else {
                    const reader = new FileReader(); 
                    reader.onload = (e) => showImg(e.target.result); 
                    reader.readAsDataURL(fileData.file);
                }
                UI.filePreviews.appendChild(item);
            });
        };

        const processSingleFile = async (fileData, options, index) => { 
            const file = fileData.file; 
            const sequencePrefix = String(index + 1).padStart(4, '0'); 
            if (STATE.isProcessingCancelled) return { success: false, status: 'cancelled' }; 
            
            const listItem = document.createElement('li'); 
            listItem.className = 'text-xs text-gray-400 bg-gray-800/80 p-2 rounded border border-gray-700 flex justify-between'; 
            listItem.innerHTML = `<span>${sequencePrefix}_${file.name}</span><span class="status">Czekam...</span>`;
            const statusSpan = listItem.querySelector('.status');
            document.getElementById('status-list').prepend(listItem); 
            
            const setStatus = (msg, color) => { statusSpan.textContent = msg; statusSpan.className = `status font-bold ${color}`; };

            try { 
                setStatus('Kompresja...', 'text-blue-400');
                const { mainImageBlob, thumbImageBlob } = await ImageProcessor.process(file, options); 
                
                setStatus('Szyfrowanie...', 'text-purple-400');
                const encMain = await CryptoHelper.encrypt(await mainImageBlob.arrayBuffer(), STATE.encryptionKey.key); 
                const encThumb = await CryptoHelper.encrypt(await thumbImageBlob.arrayBuffer(), STATE.encryptionKey.key); 
                const encOriginalName = await CryptoHelper.encryptString(file.name, STATE.encryptionKey.key);
                
                setStatus('Wysyłanie...', 'text-orange-400');
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
                
                setStatus('OK', 'text-green-400');
                return { success: true, originalSize: file.size, finalSize: json.final_size }; 
            } catch (e) { 
                setStatus('Błąd', 'text-red-500'); 
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
                const path = window.location.pathname; // Np. /projekt/admin/upload.php
                const projectRoot = path.substring(0, path.lastIndexOf('/admin/')); // /projekt
                const albumUrl = `${host}${projectRoot}/album.html?s=${STATE.targetAlbumSlug}#${STATE.encryptionKey.hex}`;

                UI.finalSummary.innerHTML = `
                    <div class="bg-gray-800 p-6 rounded-lg text-center border border-gray-700">
                        <div class="w-16 h-16 bg-green-500 rounded-full flex items-center justify-center mx-auto mb-4 shadow-lg shadow-green-500/50">
                            <i data-lucide="check" class="text-white w-8 h-8"></i>
                        </div>
                        <h2 class="text-2xl font-bold text-white mb-2">Sukces!</h2>
                        <p class="text-gray-400 mb-6">Prześlano ${stats.successCount} z ${stats.fileCount} zdjęć.</p>
                        
                        <div class="bg-black/30 p-4 rounded-lg border border-gray-600 text-left mb-4">
                            <p class="text-xs text-gray-500 uppercase font-bold mb-1">Twój Link z Kluczem:</p>
                            <div class="flex gap-2">
                                <input readonly value="${albumUrl}" class="flex-grow bg-transparent text-cyan-400 text-sm font-mono border-none focus:ring-0 p-0">
                                <button onclick="navigator.clipboard.writeText('${albumUrl}'); this.innerText='OK!'" class="text-white bg-gray-700 hover:bg-gray-600 px-3 py-1 rounded text-xs font-bold transition-colors">KOPIUJ</button>
                            </div>
                        </div>

                        <div class="bg-black/30 p-4 rounded-lg border border-gray-600 text-left">
                            <p class="text-xs text-gray-500 uppercase font-bold mb-1">Tylko Klucz (HEX) do panelu Admina:</p>
                             <div class="flex gap-2">
                                <input readonly value="${STATE.encryptionKey.hex}" class="flex-grow bg-transparent text-gray-400 text-xs font-mono border-none focus:ring-0 p-0">
                                <button onclick="navigator.clipboard.writeText('${STATE.encryptionKey.hex}'); this.innerText='OK!'" class="text-white bg-gray-700 hover:bg-gray-600 px-3 py-1 rounded text-xs font-bold transition-colors">KOPIUJ</button>
                            </div>
                        </div>
                    </div>
                    <button onclick="location.reload()" class="mt-6 w-full bg-gray-700 hover:bg-gray-600 text-white font-bold py-3 rounded-lg transition-colors">Wgraj kolejne</button>
                `;
                
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
            UI.statusListContainer.innerHTML = '<ul id="status-list" class="space-y-2"></ul>'; 
            UI.progress.text.textContent = `0 / ${fileCount}`; 
            UI.progress.bar.style.width = '0%'; 
            
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
        UI.filePreviews.addEventListener('click', (e) => {
            const removeBtn = e.target.closest('.thumbnail-remove-btn');
            if (removeBtn) {
                const indexToRemove = parseInt(removeBtn.dataset.index);
                if (!isNaN(indexToRemove)) { STATE.filesToUpload.splice(indexToRemove, 1); renderThumbnails(); updateSubmitButton(); }
            }
        });
    });
    </script>
</body>
</html>
