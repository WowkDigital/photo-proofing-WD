<?php
// Ten skrypt jest w pełni samowystarczalny. Wystarczy umieścić go w docelowym folderze albumu.

// Ustawienia ścieżek WZGLĘDEM LOKALIZACJI TEGO PLIKU
define('UPLOADS_DIR', __DIR__ . '/photos/');
define('THUMBS_DIR', __DIR__ . '/photos/thumbnails/');

// Upewnij się, że katalogi na pliki istnieją w bieżącej lokalizacji
if (!is_dir(UPLOADS_DIR)) mkdir(UPLOADS_DIR, 0755, true);
if (!is_dir(THUMBS_DIR)) mkdir(THUMBS_DIR, 0755, true);

// --- Funkcje pomocnicze ---
function send_json_error(string $message, int $http_code = 400): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json');
        http_response_code($http_code);
    }
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

function sanitize_filename(string $filename): string
{
    // Zamień rozszerzenie .arw na .jpg, bo taki będzie wynik
    $filename_without_ext = pathinfo($filename, PATHINFO_FILENAME);
    $sanitized = preg_replace('/[^a-zA-Z0-9-_\.]/', '-', $filename_without_ext);
    $sanitized = trim($sanitized, '-');
    $sanitized = preg_replace('/-+/', '-', $sanitized);
    // Zwraca tylko oczyszczoną nazwę, bez rozszerzenia, które dodamy później
    return strtolower($sanitized);
}

// --- Główna logika serwera ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!isset($_FILES['main_encrypted_file'], $_FILES['thumb_encrypted_file'])) {
            throw new Exception("Nie otrzymano kompletu zaszyfrowanych plików.");
        }
        if (empty($_POST['original_filename']) || !isset($_POST['sequence_prefix'])) {
            throw new Exception("Brak wymaganych danych: nazwy pliku lub numeru porządkowego.");
        }

        $originalName = $_POST['original_filename'];
        $prefix = $_POST['sequence_prefix'];

        // Walidacja prefiksu
        if (!preg_match('/^\d{4}$/', $prefix)) {
            throw new Exception('Nieprawidłowy format prefiksu.');
        }

        $sanitizedBaseName = sanitize_filename($originalName);
        
        // Złożenie finalnej nazwy pliku
        $finalFilename = $prefix . '_' . $sanitizedBaseName . '.enc';

        $mainEncryptedPath = UPLOADS_DIR . $finalFilename;
        $thumbEncryptedPath = THUMBS_DIR . $finalFilename;
        
        // Sprawdzenie unikalności - na wypadek ponownego wysłania tego samego pliku
        if (file_exists($mainEncryptedPath)) {
            throw new Exception("Plik o nazwie $finalFilename już istnieje na serwerze.");
        }

        if (!move_uploaded_file($_FILES['main_encrypted_file']['tmp_name'], $mainEncryptedPath)) {
            throw new Exception("Zapis zaszyfrowanego pliku głównego nie powiódł się.");
        }
        if (!move_uploaded_file($_FILES['thumb_encrypted_file']['tmp_name'], $thumbEncryptedPath)) {
            unlink($mainEncryptedPath); // Posprzątaj
            throw new Exception("Zapis zaszyfrowanej miniatury nie powiódł się.");
        }
        
        header('Content-Type: application/json');
        echo json_encode([
            'success'        => true,
            'final_size'     => filesize($mainEncryptedPath),
        ]);

    } catch (Throwable $e) {
        send_json_error('Błąd serwera: ' . $e->getMessage(), 500);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Uploader Zdjęć E2EE</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/exifreader@4.21.1/dist/exif-reader.min.js"></script>
    <style>
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
<body class="bg-gray-900 text-white flex flex-col items-center justify-center min-h-screen font-sans p-4">
    <div class="bg-gray-800 p-8 rounded-2xl shadow-2xl w-full max-w-2xl border border-gray-700">
        <div class="text-center mb-6">
            <h1 class="text-3xl font-bold text-cyan-400">Secure Photo Uploader</h1>
            <p id="statusMessage" class="text-gray-500 text-sm h-5 transition-all mt-1"></p>
        </div>

        <div id="infoSection" class="mb-8 p-4 bg-gray-900/50 rounded-lg border border-gray-700">
            <ul class="space-y-3 text-sm text-gray-400">
                <li class="flex items-start"><svg class="w-5 h-5 mr-3 text-cyan-400 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" /></svg><span><strong>Szyfrowanie End-to-End:</strong> Twoje pliki są szyfrowane w przeglądarce unikalnym kluczem, zanim trafią na serwer. Nikt oprócz posiadacza linku z kluczem nie może ich odczytać.</span></li>
                <li class="flex items-start"><svg class="w-5 h-5 mr-3 text-cyan-400 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-1.621-.87a3 3 0 01-.879-2.122v-1.007M15 15.75a3 3 0 00-3-3m0 0a3 3 0 00-3 3m3-3v-7.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg><span><strong>Prywatność i Sortowanie:</strong> Przetwarzanie, odczyt metadanych i sortowanie chronologiczne odbywa się na Twoim komputerze. Serwer tylko przechowuje zaszyfrowane pliki.</span></li>
                <li class="flex items-start"><svg class="w-5 h-5 mr-3 text-cyan-400 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.158 0a.079.079 0 11-1.079-1.079.079.079 0 01-1.079 1.079z" /></svg><span><strong>Obsługa Plików RAW:</strong> Aplikacja automatycznie wyodrębni podgląd JPG z plików Sony <strong>.ARW</strong> i użyje ich metadanych do sortowania.</span></li>
            </ul>
        </div>

        <div id="formContainer">
            <form id="uploadForm" novalidate>
                <div id="drop-zone" class="w-full text-center p-8 rounded-lg mb-4 cursor-pointer">
                    <p class="font-bold text-gray-300">Przeciągnij i upuść pliki tutaj</p>
                    <p class="text-sm text-gray-500 my-2">lub</p>
                    <label for="imageInput" class="inline-block bg-cyan-600 hover:bg-cyan-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-300 cursor-pointer">Wybierz pliki</label>
                    <input type="file" id="imageInput" name="images[]" accept="image/png, image/jpeg, image/gif, .arw" multiple>
                </div>
                <div id="file-previews" class="grid grid-cols-4 gap-4 mb-4 bg-gray-900/50 p-4 rounded-lg min-h-[110px] hidden"></div>
                <fieldset class="border border-gray-600 rounded-lg p-4 mb-4">
                    <legend class="px-2 text-cyan-400 font-semibold">Opcje obrazu</legend>
                    <div class="grid md:grid-cols-2 gap-x-6 gap-y-4">
                        <div class="grid grid-cols-[auto_1fr] gap-x-4 items-center"><label for="maxEdge" class="text-sm font-medium text-gray-300 justify-self-end">Max. krawędź:</label><input type="number" id="maxEdge" min="100" value="2000" class="bg-gray-700 border border-gray-600 text-white text-sm rounded-lg focus:ring-cyan-500 focus:border-cyan-500 block w-full p-2.5"></div>
                        <div class="grid grid-cols-[auto_1fr] gap-x-4 items-center"><label for="compressionLevel" class="text-sm font-medium text-gray-300 justify-self-end">Jakość:</label><div class="flex items-center"><input type="range" id="compressionLevel" min="1" max="100" value="85" class="w-full h-2 bg-gray-700 rounded-lg appearance-none cursor-pointer"><span id="compressionLevelValue" class="ml-4 text-sm w-12 text-right">85%</span></div></div>
                        <div class="md:col-span-2 pt-2 border-t border-gray-700">
                             <label class="flex items-center space-x-3 cursor-pointer group">
                                <input type="checkbox" id="showPreviews" class="w-4 h-4 text-cyan-600 bg-gray-700 border-gray-600 rounded focus:ring-cyan-600">
                                <span class="text-sm text-gray-400 group-hover:text-white transition-colors">Generuj miniatury podglądu (może spowolnić przy dużej ilości zdjęć)</span>
                            </label>
                        </div>
                    </div>
                </fieldset>
            </form>
        </div>

        <div id="mainButtonContainer"><button type="submit" form="uploadForm" id="submitBtn" class="w-full bg-cyan-600 hover:bg-cyan-700 text-white font-bold py-3 px-4 rounded-lg transition duration-300 disabled:bg-gray-600 disabled:cursor-not-allowed" disabled>Wybierz pliki, aby rozpocząć</button></div>
        <div id="progressContainer" class="mt-6 hidden">
            <div class="flex justify-between mb-1"><span class="text-base font-medium text-cyan-400">Całkowity postęp</span><span id="progressText" class="text-sm font-medium text-cyan-400">0 / 0</span></div>
            <div class="w-full bg-gray-700 rounded-full h-2.5"><div id="progressBar" class="bg-cyan-600 h-2.5 rounded-full transition-all duration-300 ease-out" style="width: 0%"></div></div>
        </div>
        <div id="finalSummary" class="mt-4 hidden"></div>
        <div id="statusListContainer" class="mt-4 max-h-40 overflow-y-auto"></div>
        <div id="dynamicButtonContainer" class="text-center mt-4"></div>
    </div>
    
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const CONFIG = { MAX_FILES_PER_BATCH: 2000, UPLOAD_CONCURRENCY: 4, THUMBNAIL_WIDTH: 400, STATUS_MESSAGES: { processing: ["Szyfruję pliki...", "Kompresuję obrazy...", "Przetwarzam lokalnie...", "Przygotowuję bezpieczną paczkę..."], done: ["Sukces! Wszystko gotowe.", "Operacja zakończona pomyślnie."], stopped: ["Proces zatrzymany przez użytkownika."] } };
        const STATE = { filesToUpload: [], isProcessing: false, isProcessingCancelled: false, statusMessageInterval: null, encryptionKey: null };
        const UI = { uploadForm: document.getElementById('uploadForm'), imageInput: document.getElementById('imageInput'), dropZone: document.getElementById('drop-zone'), filePreviews: document.getElementById('file-previews'), mainButtonContainer: document.getElementById('mainButtonContainer'), dynamicButtonContainer: document.getElementById('dynamicButtonContainer'), submitBtn: document.getElementById('submitBtn'), statusMessage: document.getElementById('statusMessage'), progress: { container: document.getElementById('progressContainer'), bar: document.getElementById('progressBar'), text: document.getElementById('progressText') }, finalSummary: document.getElementById('finalSummary'), statusListContainer: document.getElementById('statusListContainer'), compression: { maxEdge: document.getElementById('maxEdge'), levelSlider: document.getElementById('compressionLevel'), levelValue: document.getElementById('compressionLevelValue') }, showPreviews: document.getElementById('showPreviews') };
        const CryptoHelper = { async generateKey() { return await window.crypto.subtle.generateKey({ name: "AES-GCM", length: 256 }, true, ["encrypt"]); }, async exportKeyToHex(key) { const buf = await window.crypto.subtle.exportKey("raw", key); return Array.from(new Uint8Array(buf)).map(b => b.toString(16).padStart(2, '0')).join(''); }, async encrypt(dataBuffer, key) { const iv = window.crypto.getRandomValues(new Uint8Array(12)); const ct = await window.crypto.subtle.encrypt({ name: "AES-GCM", iv: iv }, key, dataBuffer); const res = new Uint8Array(iv.length + ct.byteLength); res.set(iv, 0); res.set(new Uint8Array(ct), iv.length); return res.buffer; } };
        const ImageProcessor = { async process(file, options) { let blob = file; if (file.name.toLowerCase().endsWith('.arw')) { try { blob = await this.convertArwToJpeg(file); } catch (e) { throw new Error(`Błąd konwersji .ARW: ${e.message}`); } } const img = await new Promise((resolve, reject) => { const i = new Image(), u = URL.createObjectURL(blob); i.onload = () => { URL.revokeObjectURL(u); resolve(i); }; i.onerror = err => reject(err); i.src = u; }); const canvas = document.createElement('canvas'); let { width, height } = img; const maxEdge = parseInt(options.max_edge); if (width > height) { if (width > maxEdge) { height *= maxEdge / width; width = maxEdge; } } else { if (height > maxEdge) { width *= maxEdge / height; height = maxEdge; } } canvas.width = Math.round(width); canvas.height = Math.round(height); canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height); const mainImageBlob = await new Promise(r => canvas.toBlob(r, 'image/jpeg', parseFloat(options.compression_level))); const thumbH = canvas.height * (CONFIG.THUMBNAIL_WIDTH / canvas.width), thumbC = document.createElement('canvas'); thumbC.width = CONFIG.THUMBNAIL_WIDTH; thumbC.height = thumbH; thumbC.getContext('2d').drawImage(canvas, 0, 0, CONFIG.THUMBNAIL_WIDTH, thumbH); const thumbImageBlob = await new Promise(r => thumbC.toBlob(r, 'image/jpeg', 0.75)); return { mainImageBlob, thumbImageBlob }; }, 
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
            findJpegInArw(arrayBuffer) { const dataView = new DataView(arrayBuffer); const isLittleEndian = dataView.getUint16(0, false) === 0x4949; if (dataView.getUint16(2, isLittleEndian) !== 42) throw new Error('Nieprawidłowy format pliku TIFF.'); let ifdOffset = dataView.getUint32(4, isLittleEndian); let result = { offset: 0, length: 0, orientation: 1 }; while (ifdOffset !== 0) { const ifdResult = this.findDataInIfd(dataView, ifdOffset, isLittleEndian); if (ifdResult.length > result.length) { result = { ...result, ...ifdResult }; } if (ifdResult.orientation !== 1 && result.orientation === 1) { result.orientation = ifdResult.orientation; } ifdOffset = ifdResult.nextIfdOffset; } if (result.offset === 0 || result.length === 0) throw new Error('Nie można zlokalizować danych JPEG.'); return result; }, findDataInIfd(dataView, ifdOffset, isLittleEndian) { let jpegOffset = 0, jpegLength = 0, orientation = 1; const numEntries = dataView.getUint16(ifdOffset, isLittleEndian); for (let i = 0; i < numEntries; i++) { const entryOffset = ifdOffset + 2 + (i * 12); const tag = dataView.getUint16(entryOffset, isLittleEndian); if (tag === 0x014A) { const subIfdOffset = dataView.getUint32(entryOffset + 8, isLittleEndian); const subResult = this.findDataInIfd(dataView, subIfdOffset, isLittleEndian); if (subResult.length > jpegLength) { jpegOffset = subResult.offset; jpegLength = subResult.length; if (subResult.orientation !== 1) orientation = subResult.orientation; } } if (tag === 0x0201) jpegOffset = dataView.getUint32(entryOffset + 8, isLittleEndian); if (tag === 0x0202) jpegLength = dataView.getUint32(entryOffset + 8, isLittleEndian); if (tag === 0x0112) orientation = dataView.getUint16(entryOffset + 8, isLittleEndian); } const nextIfdOffset = dataView.getUint32(ifdOffset + 2 + (numEntries * 12), isLittleEndian); return { offset: jpegOffset, length: jpegLength, orientation: orientation, nextIfdOffset: nextIfdOffset }; } };
        
        const updateSubmitButton = () => { const hasFiles = STATE.filesToUpload.length > 0; UI.submitBtn.disabled = !hasFiles; UI.submitBtn.textContent = hasFiles ? `Przetwórz i wyślij (${STATE.filesToUpload.length})` : 'Wybierz pliki, aby rozpocząć'; };
        
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
            UI.statusMessage.textContent = 'Odczytywanie metadanych...';
            try {
                const newFiles = Array.from(files).filter(f => !STATE.filesToUpload.some(item => item.file.name === f.name && item.file.size === f.size));
                if (STATE.filesToUpload.length + newFiles.length > CONFIG.MAX_FILES_PER_BATCH) {
                    alert(`Możesz dodać maksymalnie ${CONFIG.MAX_FILES_PER_BATCH} plików na raz.`);
                    return;
                }
                
                // Przetwarzanie sekwencyjne dla oszczędności RAMu
                const newFilesWithData = [];
                for (const f of newFiles) {
                    newFilesWithData.push(await readFileWithExif(f));
                }

                STATE.filesToUpload.push(...newFilesWithData);
                STATE.filesToUpload.sort((a, b) => a.creationDate - b.creationDate);
                renderThumbnails();
                updateSubmitButton();
            } catch (error) {
                console.error("Błąd podczas przetwarzania plików:", error);
                UI.statusMessage.textContent = 'Wystąpił błąd podczas dodawania plików.';
            } finally {
                if (STATE.isProcessing === false) { UI.statusMessage.textContent = ''; }
            }
        };
 
        const cleanupResources = () => {
            if (window._thumbnailUrls) {
                window._thumbnailUrls.forEach(url => URL.revokeObjectURL(url));
            }
            window._thumbnailUrls = [];
        };

        const renderThumbnails = () => {
            cleanupResources();
            UI.filePreviews.innerHTML = '';
            UI.filePreviews.classList.toggle('hidden', STATE.filesToUpload.length === 0);
            
            const usePreviews = UI.showPreviews.checked;

            STATE.filesToUpload.forEach((fileData, index) => {
                const isArw = fileData.file.name.toLowerCase().endsWith('.arw');
                const item = document.createElement('div');
                item.className = 'thumbnail-item aspect-square bg-gray-700 rounded-md flex items-center justify-center p-1 overflow-hidden';
                
                if (!usePreviews) {
                    item.innerHTML = `
                        <div class="flex flex-col items-center text-[10px] text-gray-500 text-center space-y-1">
                            <span class="truncate w-16 px-1">${fileData.file.name}</span>
                        </div>
                        <div class="thumbnail-remove-btn" data-index="${index}"><span>&times;</span></div>
                    `;
                    UI.filePreviews.appendChild(item);
                    return;
                }

                item.innerHTML = `<img src="" alt="${fileData.file.name}" class="max-w-full max-h-full object-contain"><div class="thumbnail-remove-btn" data-index="${index}"><span>&times;</span></div>`;
                const img = item.querySelector('img');
                
                const showImg = (src) => { 
                    img.src = src; 
                    if (src.startsWith('blob:')) window._thumbnailUrls.push(src);
                };

                if (isArw) {
                    ImageProcessor.convertArwToJpeg(fileData.file)
                        .then(jpegBlob => { 
                            const url = URL.createObjectURL(jpegBlob);
                            showImg(url);
                        })
                        .catch(err => { console.error(`Błąd podglądu ${fileData.file.name}:`, err); img.alt = 'Błąd podglądu'; });
                } else {
                    const url = URL.createObjectURL(fileData.file);
                    showImg(url);
                }
                UI.filePreviews.appendChild(item);
            });
        };
w);
                }
                UI.filePreviews.appendChild(item);
            });
        };
        
        const processSingleFile = async (fileData, options, index) => { const file = fileData.file; const sequencePrefix = String(index + 1).padStart(4, '0'); if (STATE.isProcessingCancelled) return { success: false, status: 'cancelled' }; const listItem = document.createElement('li'); listItem.className = 'status-item text-gray-400 bg-gray-700/50 p-3 rounded-md flex items-center justify-between fade-in'; const statusSpan = document.createElement('span'); listItem.innerHTML = `<span class="truncate pr-4 text-sm">${sequencePrefix}_${file.name}</span>`; listItem.appendChild(statusSpan); document.getElementById('status-list').prepend(listItem); const updateStatus = (message, type) => { statusSpan.textContent = message; statusSpan.className = `font-semibold text-sm ${{ success: 'text-green-400', error: 'text-red-400', processing: 'text-cyan-400' }[type] || ''}`; }; try { updateStatus('Przetwarzam...', 'processing'); const { mainImageBlob, thumbImageBlob } = await ImageProcessor.process(file, options); updateStatus('Szyfruję...', 'processing'); const encryptedMainBuffer = await CryptoHelper.encrypt(await mainImageBlob.arrayBuffer(), STATE.encryptionKey.key); const encryptedThumbBuffer = await CryptoHelper.encrypt(await thumbImageBlob.arrayBuffer(), STATE.encryptionKey.key); updateStatus('Wysyłam...', 'processing'); const formData = new FormData(); formData.append('main_encrypted_file', new Blob([encryptedMainBuffer])); formData.append('thumb_encrypted_file', new Blob([encryptedThumbBuffer])); formData.append('original_filename', file.name); formData.append('sequence_prefix', sequencePrefix); const response = await fetch('', { method: 'POST', body: formData }); const result = await response.json(); if (!response.ok || !result.success) throw new Error(result.error || 'Błąd serwera.'); updateStatus('Sukces', 'success'); return { success: true, originalSize: file.size, finalSize: result.final_size }; } catch (error) { updateStatus(`Błąd: ${error.message}`, 'error'); console.error(`Błąd przetwarzania ${file.name}:`, error); return { success: false, originalSize: file.size, finalSize: 0, status: 'error' }; } };
        const handleFormSubmit = async (event) => { event.preventDefault(); if (STATE.isProcessing || STATE.filesToUpload.length === 0) return; const filesToProcess = [...STATE.filesToUpload]; const key = await CryptoHelper.generateKey(); STATE.encryptionKey = { key: key, hex: await CryptoHelper.exportKeyToHex(key) }; startProcessingUI(filesToProcess.length); const options = { max_edge: UI.compression.maxEdge.value, compression_level: UI.compression.levelSlider.value / 100 }; const stats = { successCount: 0, totalOriginalSize: 0, totalFinalSize: 0, completedCount: 0 }; const startTime = Date.now(); const queue = filesToProcess.map((fileData, index) => ({ fileData, index })); const worker = async () => { while (queue.length > 0) { if (STATE.isProcessingCancelled) break; const { fileData, index } = queue.shift(); const result = await processSingleFile(fileData, options, index); stats.completedCount++; stats.totalOriginalSize += result.originalSize || 0; if (result.success) { stats.successCount++; stats.totalFinalSize += result.finalSize || 0; } UI.progress.text.textContent = `${stats.completedCount} / ${filesToProcess.length}`; UI.progress.bar.style.width = `${(stats.completedCount / filesToProcess.length) * 100}%`; } }; await Promise.all(Array(CONFIG.UPLOAD_CONCURRENCY).fill(null).map(worker)); finishProcessingUI({ totalTime: ((Date.now() - startTime) / 1000).toFixed(2), reductionMB: ((stats.totalOriginalSize - stats.totalFinalSize) / 1024 / 1024).toFixed(2), reductionPercent: stats.totalOriginalSize > 0 ? ((1 - stats.totalFinalSize / stats.totalOriginalSize) * 100).toFixed(1) : 0, successCount: stats.successCount, fileCount: filesToProcess.length }); };
        const finishProcessingUI = (stats) => { STATE.isProcessing = false; clearInterval(STATE.statusMessageInterval); UI.dynamicButtonContainer.innerHTML = ''; const { totalTime, reductionMB, reductionPercent, successCount, fileCount } = stats; if (STATE.isProcessingCancelled) { UI.finalSummary.innerHTML = `<p class="text-center text-yellow-400 font-bold">Proces anulowany.</p>`; showStatusMessage('stopped'); } else { let summaryHTML = `<p class="text-center text-cyan-400 font-bold text-xl">Wszystko gotowe!</p><div class="text-sm text-gray-300 mt-2 bg-gray-700 p-4 rounded-lg"><p><strong>Przetworzono:</strong> ${successCount} z ${fileCount} plików w ${totalTime} s</p>${ reductionMB > 0 ? `<p class="font-semibold text-green-400"><strong>Zmniejszono rozmiar o:</strong> ${reductionMB} MB (${reductionPercent}%)</p>` : '' }</div>`; if (STATE.encryptionKey && successCount > 0) { const currentUrl = window.location.href; const albumBaseUrl = currentUrl.substring(0, currentUrl.lastIndexOf('/') + 1); const finalUrl = `${albumBaseUrl}album.html#${STATE.encryptionKey.hex}`; summaryHTML += `<div class="mt-4 bg-green-900/50 border border-green-700 text-green-300 px-4 py-3 rounded-lg text-sm" role="alert"><p class="font-bold">Gotowy link do albumu:</p><div class="flex items-center mt-2 bg-gray-900 p-2 rounded"><input type="text" readonly id="finalUrlInput" value="${finalUrl}" class="flex-grow bg-transparent border-none text-green-300 focus:ring-0 p-0 m-0"><button id="copyUrlBtn" class="ml-2 bg-gray-700 hover:bg-gray-600 px-3 py-1 rounded text-xs">Kopiuj</button></div><p class="mt-2">Udostępnij ten link swojemu klientowi. Zawiera on klucz do odszyfrowania zdjęć.</p></div>`; } UI.finalSummary.innerHTML = summaryHTML; const copyBtn = document.getElementById('copyUrlBtn'); if (copyBtn) { copyBtn.addEventListener('click', (e) => { navigator.clipboard.writeText(document.getElementById('finalUrlInput').value).then(() => { e.target.textContent = 'Skopiowano!'; setTimeout(() => { e.target.textContent = 'Kopiuj'; }, 2000); }); }); } showStatusMessage('done'); } UI.finalSummary.classList.remove('hidden'); if (successCount > 0 && successCount === fileCount && !STATE.isProcessingCancelled) { confetti({ particleCount: 150, spread: 90, origin: { y: 0.6 } }); } const sendMoreBtn = document.createElement('button'); sendMoreBtn.textContent = 'Prześlij kolejne'; sendMoreBtn.className = 'w-full bg-gray-600 hover:bg-gray-700 text-white font-bold py-3 px-4 rounded-lg'; sendMoreBtn.addEventListener('click', () => location.reload()); UI.dynamicButtonContainer.appendChild(sendMoreBtn); };
        const showStatusMessage = (category) => { UI.statusMessage.textContent = CONFIG.STATUS_MESSAGES[category][Math.floor(Math.random() * CONFIG.STATUS_MESSAGES[category].length)]; };
        const startProcessingUI = (fileCount) => { STATE.isProcessing = true; document.getElementById('formContainer').classList.add('hidden'); UI.mainButtonContainer.classList.add('hidden'); UI.progress.container.classList.remove('hidden'); UI.finalSummary.classList.add('hidden'); UI.statusListContainer.innerHTML = '<ul id="status-list" class="space-y-2"></ul>'; UI.progress.text.textContent = `0 / ${fileCount}`; UI.progress.bar.style.width = '0%'; const stopBtn = document.createElement('button'); stopBtn.textContent = 'Anuluj przetwarzanie'; stopBtn.className = 'w-full bg-red-600 hover:bg-red-700 text-white font-bold py-3 px-4 rounded-lg'; stopBtn.addEventListener('click', () => { STATE.isProcessingCancelled = true; }); UI.dynamicButtonContainer.innerHTML = ''; UI.dynamicButtonContainer.appendChild(stopBtn); showStatusMessage('processing'); STATE.statusMessageInterval = setInterval(() => showStatusMessage('processing'), 3000); };
        
        function initEventListeners() {
            UI.uploadForm.addEventListener('submit', handleFormSubmit);
            UI.imageInput.addEventListener('change', (e) => handleFiles(e.target.files));
            UI.dropZone.addEventListener('click', (e) => { if (e.target.tagName !== 'LABEL' && e.target.id !== 'imageInput') { UI.imageInput.click(); } });
            UI.dropZone.addEventListener('dragover', (e) => { e.preventDefault(); UI.dropZone.classList.add('drag-over'); });
            UI.dropZone.addEventListener('dragleave', () => UI.dropZone.classList.remove('drag-over'));
            UI.dropZone.addEventListener('drop', (e) => { e.preventDefault(); UI.dropZone.classList.remove('drag-over'); handleFiles(e.dataTransfer.files); });
            UI.compression.levelSlider.addEventListener('input', () => { UI.compression.levelValue.textContent = `${UI.compression.levelSlider.value}%`; });
            UI.showPreviews.addEventListener('change', () => renderThumbnails());
            
            UI.filePreviews.addEventListener('click', (e) => {
                const removeBtn = e.target.closest('.thumbnail-remove-btn');
                if (removeBtn) {
                    const indexToRemove = parseInt(removeBtn.dataset.index);
                    if (!isNaN(indexToRemove)) {
                        // Usunięcie pliku z głównego stanu aplikacji
                        STATE.filesToUpload.splice(indexToRemove, 1);
                        // Zamiast usuwać jeden element, co jest skomplikowane,
                        // po prostu odświeżamy cały kontener. Jest to prostsze i bardziej niezawodne.
                        renderThumbnails();
                        updateSubmitButton();
                    }
                }
            });
        }
        initEventListeners();
    });
    </script>
</body>
</html>