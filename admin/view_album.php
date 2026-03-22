<?php
// admin/view_album.php
require_once 'auth.php';
require_once '../api/db.php';

if (!isset($_GET['id'])) {
    header('Location: index.php');
    exit;
}

$id = (int)$_GET['id'];

try {
    $stmt = $pdo->prepare("SELECT * FROM albums WHERE id = ?");
    $stmt->execute([$id]);
    $album = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$album) {
        die("Album nie znaleziony.");
    }

    $stmtSelections = $pdo->prepare("SELECT * FROM selections WHERE album_id = ? ORDER BY selection_date DESC");
    $stmtSelections->execute([$id]);
    $selections = $stmtSelections->fetchAll(PDO::FETCH_ASSOC);

    $selectionsData = [];
    foreach ($selections as $sel) {
        $stmtPhotos = $pdo->prepare("SELECT p.filename, p.original_filename FROM selected_photos sp JOIN photos p ON sp.photo_filename = p.filename WHERE sp.selection_id = ?");
        $stmtPhotos->execute([$sel['id']]);
        $sel['photos_data'] = $stmtPhotos->fetchAll(PDO::FETCH_ASSOC);
        $selectionsData[] = $sel;
    }
} catch (PDOException $e) {
    die("Błąd bazy danych: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Album: <?php echo htmlspecialchars($album['internal_name']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="bg-[#1a1a2e] text-gray-200 font-sans min-h-screen">
    <div class="container mx-auto p-4 max-w-6xl">
        <header class="flex flex-col md:flex-row justify-between items-center mb-8 border-b border-[#3f3f6e] pb-4 gap-4">
            <div class="flex items-center">
                <a href="index.php" class="text-gray-500 hover:text-white mr-4 transition-colors"><i data-lucide="arrow-left" class="w-6 h-6"></i></a>
                <div>
                    <h1 class="text-2xl font-bold text-white"><?php echo htmlspecialchars($album['internal_name']); ?></h1>
                    <p class="text-xs text-cyan-400 font-mono">ID / Slug: <?php echo htmlspecialchars($album['slug']); ?></p>
                </div>
            </div>
            
            <div class="flex items-center gap-4">
                <a href="upload.php?album_id=<?php echo $album['id']; ?>" class="bg-gradient-to-r from-cyan-600 to-blue-600 hover:from-cyan-500 hover:to-blue-500 text-white px-5 py-2.5 rounded-xl transition-all flex items-center text-sm font-semibold shadow-lg hover:shadow-xl hover:-translate-y-0.5 border border-cyan-400/20">
                    <i data-lucide="upload-cloud" class="w-4 h-4 mr-2"></i> Prześlij zdjęcia
                </a>
            </div>
        </header>

        <main>
            <!-- Panel klucza i statusu -->
            <div class="mb-8 bg-[#2c2c54] rounded-2xl p-6 border border-[#3f3f6e] shadow-xl relative overflow-hidden">
                <div class="absolute right-0 top-0 w-64 h-64 bg-cyan-500/5 rounded-full blur-3xl -translate-y-1/2 translate-x-1/2 pointer-events-none"></div>
                
                <div class="flex flex-col md:flex-row gap-6 relative z-10">
                    <div class="flex-grow">
                        <div class="flex items-center gap-3 mb-2">
                            <div class="bg-cyan-500/20 p-2 rounded-lg text-cyan-400">
                                <i data-lucide="shield-check" class="w-5 h-5"></i>
                            </div>
                            <h2 class="text-lg font-bold text-white">Integracja z Sejfem Kluczy (ZKA)</h2>
                        </div>
                        <p class="text-sm text-gray-400 mb-4">Jeśli klucz tego albumu znajduje się w "Sejfie Kluczy" na panelu głównym aplikacji, wszystkie zaszyfrowane nazwy plików poniżej zostaną błyskawicznie i w locie zdekodowane.</p>
                        
                        <div class="flex flex-col sm:flex-row gap-3">
                            <input type="text" id="manual-key-input" placeholder="Wklej klucz ręcznie lub pełny link, jeśli brakuje go w sejfie..." class="flex-grow bg-[#151525] border border-[#3f3f6e] rounded-xl p-3 text-sm text-cyan-400 font-mono focus:border-cyan-500 outline-none transition-all shadow-inner focus:ring-1 focus:ring-cyan-500">
                            <button id="apply-key-btn" class="bg-[#3f3f6e] hover:bg-cyan-600 text-white px-6 py-3 rounded-xl transition-all shadow-lg text-sm font-semibold flex items-center justify-center">
                                <i data-lucide="unlock" class="w-4 h-4 mr-2"></i> Zastosuj do Sejfu
                            </button>
                        </div>
                        <p id="key-status-msg" class="text-xs mt-3 flex items-center gap-1.5 hidden"></p>
                    </div>
                </div>
            </div>

            <!-- Lista Wyborów -->
            <div class="flex items-center justify-between mb-6 border-b border-[#3f3f6e] pb-3">
                <h2 class="text-xl font-bold text-white flex items-center gap-2">
                    <i data-lucide="check-circle" class="w-6 h-6 text-green-400"></i> Wyniki i Wybory Klientów
                    <span class="bg-[#3f3f6e] text-white text-xs px-3 py-1 rounded-full ml-3"><?php echo count($selections); ?> sumarycznie</span>
                </h2>
            </div>

            <div id="selections-container" class="space-y-6">
                <!-- Generowane dynamicznie przez JS -->
            </div>
            
            <?php if (empty($selections)): ?>
                <div class="text-center py-16 bg-[#2c2c54]/50 rounded-2xl border border-dashed border-[#3f3f6e]">
                    <div class="bg-[#1f1f38] w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4 border border-[#3f3f6e] shadow-inner">
                        <i data-lucide="inbox" class="w-8 h-8 text-gray-500"></i>
                    </div>
                    <h3 class="text-lg font-bold text-white mb-1">Cisza w eterze</h3>
                    <p class="text-gray-400 text-sm">Klienci jeszcze nie dokonali swoiego wyboru dla tego albumu.</p>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Modal dla pełnej rozdzielczości -->
    <div id="full-image-modal" class="fixed inset-0 z-[100] hidden flex items-center justify-center p-4 md:p-12 bg-black/95 backdrop-blur-md">
        <button id="close-modal-btn" class="absolute top-6 right-6 text-white/50 hover:text-white transition-all p-2 hover:rotate-90 z-50">
            <i data-lucide="x" class="w-10 h-10"></i>
        </button>
        
        <!-- Przyciski nawigacji -->
        <button id="prev-modal-btn" class="absolute left-6 top-1/2 -translate-y-1/2 text-white/30 hover:text-white hover:bg-white/10 p-4 rounded-full transition-all z-50">
            <i data-lucide="chevron-left" class="w-12 h-12"></i>
        </button>
        <button id="next-modal-btn" class="absolute right-6 top-1/2 -translate-y-1/2 text-white/30 hover:text-white hover:bg-white/10 p-4 rounded-full transition-all z-50">
            <i data-lucide="chevron-right" class="w-12 h-12"></i>
        </button>

        <div id="modal-loader" class="absolute inset-0 flex items-center justify-center pointer-events-none z-40">
            <div class="flex flex-col items-center gap-4">
                <i data-lucide="loader-2" class="w-12 h-12 animate-spin text-cyan-500"></i>
                <p class="text-cyan-500 font-mono text-xs uppercase tracking-widest">Deszyfrowanie oryginału...</p>
            </div>
        </div>
        <img id="full-resolution-img" src="" alt="" class="max-w-full max-h-full object-contain shadow-[0_0_50px_rgba(0,0,0,0.5)] rounded-sm opacity-0 transition-all duration-500 scale-95 z-30">
        
        <!-- Licznik / Nazwa -->
        <div id="modal-info" class="absolute bottom-8 left-1/2 -translate-x-1/2 text-white/60 font-mono text-xs bg-black/40 px-4 py-2 rounded-full backdrop-blur z-50"></div>
    </div>

    <script>
        lucide.createIcons();
        
        const selectionsData = <?php echo json_encode($selectionsData); ?>;
        const albumHash = "<?php echo $album['encryption_key_hash']; ?>";
        let activeKeyHex = null;

        // KRYPTOGRAFIA (Zero-Knowledge Decoder)
        const CryptoHelper = {
            hexStringToArrayBuffer(hexString) {
                const bytes = new Uint8Array(hexString.length / 2);
                for (let i = 0; i < hexString.length; i += 2) {
                    bytes[i / 2] = parseInt(hexString.substring(i, i + 2), 16);
                }
                return bytes.buffer;
            },
            async importKeyFromHex(hex) {
                if(!hex || hex.length !== 64) throw new Error("Nieprawidłowa długość klucza.");
                const buf = this.hexStringToArrayBuffer(hex);
                return await window.crypto.subtle.importKey("raw", buf, { name: "AES-GCM" }, true, ["decrypt"]);
            },
            async decryptString(base64str, key) {
                if (!base64str) return base64str;
                try {
                    const binary = atob(base64str);
                    const bytes = new Uint8Array(binary.length);
                    for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
                    if (bytes.byteLength < 12) return base64str;
                    const iv = bytes.slice(0, 12);
                    const ciphertext = bytes.slice(12);
                    const decryptedBuffer = await crypto.subtle.decrypt({ name: "AES-GCM", iv: iv }, key, ciphertext);
                    return new TextDecoder().decode(decryptedBuffer);
                } catch(e) {
                    return base64str; // Fallback do bazy (np. do zaszyfrowanej nazwy nie base64)
                }
            },
            async decryptImage(url, key) {
                try {
                    const response = await fetch(url);
                    if (!response.ok) throw new Error(`HTTP ${response.status}`);
                    const encryptedData = await response.arrayBuffer();
                    if (encryptedData.byteLength < 28) throw new Error("Plik jest uszkodzony.");
                    const iv = encryptedData.slice(0, 12);
                    const ciphertext = encryptedData.slice(12);
                    const decryptedBuffer = await crypto.subtle.decrypt({ name: "AES-GCM", iv: iv }, key, ciphertext);
                    const blob = new Blob([decryptedBuffer], { type: "image/jpeg" });
                    return URL.createObjectURL(blob);
                } catch (e) {
                    console.error("Błąd dekodowania obrazu:", e);
                    return null;
                }
            },
            async sha256(hex) {
                const msgBuffer = new TextEncoder().encode(hex.trim());
                const hashBuffer = await window.crypto.subtle.digest('SHA-256', msgBuffer);
                const hashArray = Array.from(new Uint8Array(hashBuffer));
                return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
            }
        };

        const UI = {
            modal: document.getElementById('full-image-modal'),
            modalImg: document.getElementById('full-resolution-img'),
            modalLoader: document.getElementById('modal-loader'),
            modalInfo: document.getElementById('modal-info'),
            closeModalBtn: document.getElementById('close-modal-btn'),
            prevBtn: document.getElementById('prev-modal-btn'),
            nextBtn: document.getElementById('next-modal-btn'),
            
            currentPhotos: [],
            currentIndex: -1,
            activeKeyHex: null,

            async showFullImage(index, photos, keyHex) {
                this.currentPhotos = photos;
                this.currentIndex = index;
                this.activeKeyHex = keyHex;
                
                if(!this.activeKeyHex || this.currentIndex === -1) return;
                
                this.modal.classList.remove('hidden');
                document.body.style.overflow = 'hidden';
                this.updateImage();
            },

            async updateImage() {
                const photo = this.currentPhotos[this.currentIndex];
                if(!photo) return;

                this.modalImg.classList.add('opacity-0', 'scale-95');
                this.modalLoader.classList.remove('hidden');
                this.modalInfo.textContent = `${this.currentIndex + 1} / ${this.currentPhotos.length} — ${photo.original_filename_dec}`;
                
                try {
                    const key = await CryptoHelper.importKeyFromHex(this.activeKeyHex);
                    const url = `../photos/${photo.filename}`;
                    const blobUrl = await CryptoHelper.decryptImage(url, key);
                    
                    if (blobUrl) {
                        if(this.modalImg.src.startsWith('blob:')) URL.revokeObjectURL(this.modalImg.src);
                        this.modalImg.src = blobUrl;
                        this.modalImg.onload = () => {
                            this.modalImg.classList.remove('opacity-0', 'scale-95');
                            this.modalImg.classList.add('scale-100');
                            this.modalLoader.classList.add('hidden');
                        };
                    }
                } catch(e) {
                    console.error("Błąd ładowania obrazu:", e);
                }
            },

            next() {
                if (this.currentIndex < this.currentPhotos.length - 1) {
                    this.currentIndex++;
                    this.updateImage();
                } else {
                    this.currentIndex = 0; // Loop or stay
                    this.updateImage();
                }
            },

            prev() {
                if (this.currentIndex > 0) {
                    this.currentIndex--;
                    this.updateImage();
                } else {
                    this.currentIndex = this.currentPhotos.length - 1; // Loop
                    this.updateImage();
                }
            },

            closeModal() {
                this.modal.classList.add('hidden');
                document.body.style.overflow = '';
                if(this.modalImg.src.startsWith('blob:')) {
                    URL.revokeObjectURL(this.modalImg.src);
                }
                this.modalImg.src = '';
                this.currentPhotos = [];
                this.currentIndex = -1;
            }
        };

        UI.closeModalBtn.addEventListener('click', () => UI.closeModal());
        UI.prevBtn.addEventListener('click', (e) => { e.stopPropagation(); UI.prev(); });
        UI.nextBtn.addEventListener('click', (e) => { e.stopPropagation(); UI.next(); });
        UI.modal.addEventListener('click', (e) => { if(e.target === UI.modal) UI.closeModal(); });
        
        window.addEventListener('keydown', (e) => { 
            if(UI.modal.classList.contains('hidden')) return;
            if(e.key === 'Escape') UI.closeModal(); 
            if(e.key === 'ArrowRight') UI.next();
            if(e.key === 'ArrowLeft') UI.prev();
        });

        const VAULT = {
            keys: JSON.parse(sessionStorage.getItem('admin_vault_keys') || '{}'),
            getKey() {
                if(albumHash && this.keys[albumHash]) return this.keys[albumHash];
                return null;
            },
            async saveKey(hex) {
                const hash = await CryptoHelper.sha256(hex.toLowerCase());
                this.keys[hash] = hex.toLowerCase();
                sessionStorage.setItem('admin_vault_keys', JSON.stringify(this.keys));
                init(); // Re-render z nowym kluczem
            }
        };

        function setStatus(msg, type) {
            const el = document.getElementById('key-status-msg');
            el.className = `text-sm mt-4 flex items-center font-medium gap-2 ${type === 'success' ? 'text-green-400' : 'text-orange-400'}`;
            el.innerHTML = type === 'success' ? `<i data-lucide="check-circle" class="w-4 h-4"></i> ${msg}` : `<i data-lucide="alert-triangle" class="w-4 h-4"></i> ${msg}`;
            lucide.createIcons();
        }

        async function init() {
            activeKeyHex = VAULT.getKey();
            if (activeKeyHex) {
                setStatus('Aktywowano deszyfrowanie. Klucz z Twojego Sejfu odblokowuje prawdziwe nazwy plików.', 'success');
                const inp = document.getElementById('manual-key-input');
                inp.value = '**************** (Klucz działa, pochodzi z Sejfu i jest w pamięci RAM)';
                inp.disabled = true;
                inp.classList.replace('text-cyan-400', 'text-green-500');
                document.getElementById('apply-key-btn').style.display = 'none';
                await renderSelections(activeKeyHex);
            } else {
                setStatus('Status zaszyfrowany (ZKA). Nazwy zdjęć pozostają anonimowe ze względów bezpieczeństwa.', 'warning');
                await renderSelections(null);
            }
        }

        async function renderSelections(keyHex) {
            const container = document.getElementById('selections-container');
            container.innerHTML = '';
            let key = null;

            if (keyHex) {
                try { key = await CryptoHelper.importKeyFromHex(keyHex); }
                catch(e) { console.error('Błędny klucz krypto', e); }
            }

            for (const sel of selectionsData) {
                let decryptedNames = [];
                let isDecrypted = false;
                
                if (key) {
                    try {
                        decryptedNames = await Promise.all(sel.photos_data.map(async p => {
                            const name = await CryptoHelper.decryptString(p.original_filename, key);
                            return { ...p, original_filename_dec: name };
                        }));
                        decryptedNames.sort((a, b) => a.original_filename_dec.localeCompare(b.original_filename_dec, undefined, { numeric: true, sensitivity: 'base' }));
                        isDecrypted = true;
                    } catch(e) {
                        console.error('Błąd dekrypcji nazw:', e);
                        decryptedNames = sel.photos_data.map(p => ({ ...p, original_filename_dec: p.original_filename }));
                    }
                } else {
                    decryptedNames = sel.photos_data.map(p => ({ ...p, original_filename_dec: p.original_filename }));
                }

                const fileNamesOnly = decryptedNames.map(p => p.original_filename_dec).join('\n');

                let clientDetails = '';
                if(sel.client_email) clientDetails += `<a href="mailto:${escapeHtml(sel.client_email)}" class="hover:text-cyan-400 transition-colors"><i data-lucide="mail" class="w-3.5 h-3.5 inline mr-1.5 text-gray-500"></i>${escapeHtml(sel.client_email)}</a>`;
                if(sel.client_phone) clientDetails += `<a href="tel:${escapeHtml(sel.client_phone)}" class="hover:text-cyan-400 transition-colors"><i data-lucide="phone" class="w-3.5 h-3.5 inline mr-1.5 text-gray-500"></i>${escapeHtml(sel.client_phone)}</a>`;
                if(sel.client_telegram) clientDetails += `<span class="break-all"><i data-lucide="send" class="w-3.5 h-3.5 inline mr-1.5 text-blue-400"></i>${escapeHtml(sel.client_telegram)}</span>`;
                if(sel.client_instagram) clientDetails += `<span class="break-all"><i data-lucide="instagram" class="w-3.5 h-3.5 inline mr-1.5 text-pink-500"></i>${escapeHtml(sel.client_instagram)}</span>`;
                if(sel.client_facebook) clientDetails += `<span class="break-all"><i data-lucide="facebook" class="w-3.5 h-3.5 inline mr-1.5 text-blue-600"></i>${escapeHtml(sel.client_facebook)}</span>`;
                
                let notesHtml = '';
                if (sel.client_notes) {
                    notesHtml = `
                    <div class="mt-4 pt-4 border-t border-[#3f3f6e]">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wider mb-2 font-bold">Wiadomość / Notatki</p>
                        <div class="p-3 bg-[#151525] rounded-xl border border-[#3f3f6e] text-sm text-gray-300 italic"><i data-lucide="message-square" class="w-4 h-4 inline mr-2 text-cyan-500/50"></i>"${escapeHtml(sel.client_notes)}"</div>
                    </div>`;
                }

                const dateObj = new Date(sel.selection_date);
                const dateStr = dateObj.toLocaleDateString('pl-PL') + ' o ' + dateObj.toLocaleTimeString('pl-PL', {hour: '2-digit', minute:'2-digit'});

                const cardId = `sel-card-${sel.id}`;
                const cardHtml = `
                    <div id="${cardId}" class="bg-[#2c2c54] rounded-2xl border border-[#3f3f6e] shadow-xl overflow-hidden flex flex-col lg:flex-row group transition-all hover:border-[#4f4f8a]">
                        <!-- Lewy panel - Wizytówka -->
                        <div class="p-6 lg:w-[35%] border-b lg:border-b-0 lg:border-r border-[#3f3f6e] bg-[#232342]/70 flex flex-col justify-between relative">
                            <div>
                                <h3 class="text-xl font-bold text-white mb-1 tracking-tight flex items-center">
                                    <span class="bg-cyan-500/20 text-cyan-400 p-1.5 rounded-lg mr-3 shadow-inner"><i data-lucide="user" class="w-5 h-5"></i></span>
                                    ${escapeHtml(sel.client_name)}
                                </h3>
                                <p class="text-[11px] font-semibold text-gray-500 mb-6 flex items-center ml-11"><i data-lucide="calendar" class="w-3.5 h-3.5 mr-1.5"></i> Dokonano wyboru: ${dateStr}</p>
                                
                                <div class="flex flex-col gap-2.5 text-xs text-gray-400 ml-2 font-medium">
                                    ${clientDetails}
                                </div>
                            </div>
                            
                            ${notesHtml}
                        </div>

                        <!-- Prawy panel - Raport i Miniaturki -->
                        <div class="p-6 lg:w-[65%] flex flex-col gap-6">
                            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">
                                <h4 class="text-sm font-bold text-gray-300 uppercase tracking-widest flex items-center">
                                    <i data-lucide="images" class="w-4 h-4 mr-2 text-blue-400"></i> ${sel.photos_data.length} wybranych kadrów
                                </h4>
                                <button onclick="copyToClipboard(this)" data-clipboard="${escapeHtml(fileNamesOnly)}" class="bg-[#3f3f6e] hover:bg-cyan-600 text-white px-4 py-2 rounded-xl text-xs font-bold transition-all flex items-center shadow shadow-black/20 hover:shadow-cyan-500/20 active:scale-95 border border-[#4f4f8a] hover:border-cyan-400">
                                    <i data-lucide="copy" class="w-3 h-3 mr-2"></i> Skopiuj Listę dla Lightrooma
                                </button>
                            </div>

                            <!-- Grid miniatur -->
                            <div class="thumbnails-grid grid grid-cols-4 sm:grid-cols-6 md:grid-cols-8 gap-2 mb-4 p-3 bg-[#151525] rounded-xl border border-[#3f3f6e]">
                                ${decryptedNames.map((p, i) => `
                                    <div class="aspect-square bg-[#1a1a2e] rounded-lg border border-[#3f3f6e] overflow-hidden relative group-thumb" title="${escapeHtml(p.original_filename_dec)}">
                                        ${isDecrypted ? `
                                            <div class="absolute inset-0 flex items-center justify-center opacity-30 thumb-loader">
                                                <i data-lucide="loader-2" class="w-4 h-4 animate-spin text-cyan-500"></i>
                                            </div>
                                            <button onclick="UI.showFullImage(${i}, ${JSON.stringify(decryptedNames).replace(/"/g, '&quot;')}, activeKeyHex)" class="w-full h-full block group/item">
                                                <img data-filename="${p.filename}" class="w-full h-full object-cover opacity-0 transition-all duration-300 group-hover/item:scale-110" alt="">
                                                <div class="absolute inset-0 bg-cyan-500/0 group-hover/item:bg-cyan-500/20 transition-all flex items-center justify-center">
                                                    <i data-lucide="maximize-2" class="w-5 h-5 text-white opacity-0 group-hover/item:opacity-100 scale-50 group-hover/item:scale-100 transition-all"></i>
                                                </div>
                                            </button>
                                        ` : `
                                            <div class="absolute inset-0 bg-[#000]/40 flex items-center justify-center">
                                                <i data-lucide="lock" class="w-4 h-4 text-gray-600"></i>
                                            </div>
                                        `}
                                    </div>
                                `).join('')}
                            </div>
                            
                            <div class="relative flex-grow h-48 sm:h-auto group/textarea">
                                <textarea readonly class="w-full h-full min-h-[10rem] bg-[#151525] border border-[#3f3f6e] rounded-xl p-4 text-sm font-mono focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 outline-none transition-all resize-y shadow-inner ${isDecrypted ? 'text-emerald-400 selection:bg-emerald-900/50' : 'text-gray-600 selection:bg-gray-800'} scrollbar-thin scrollbar-thumb-gray-700 scrollbar-track-transparent">
${!isDecrypted ? '==================================================\n⚠️ UWAGA: ZERO-KNOWLEDGE ARCHITECTURE AKTYWNA\n==================================================\n\nAby odszyfrować nazwy wybranych plików wklej swój\nKlucz Dostępu (64 znaki) w panelu powyżej.\n\n==================================================\nZaszyfrowane ciągi:\n' : ''}${fileNamesOnly}</textarea>
                                ${!isDecrypted ? '<div class="absolute inset-0 flex items-center justify-center pointer-events-none"><i data-lucide="lock" class="w-16 h-16 text-gray-800 opacity-20"></i></div>' : ''}
                            </div>
                        </div>
                    </div>
                `;
                container.insertAdjacentHTML('beforeend', cardHtml);
                
                // Load thumbnails if key exists
                if (key) {
                    const cardEl = document.getElementById(cardId);
                    const images = cardEl.querySelectorAll('img[data-filename]');
                    
                    images.forEach(async img => {
                        const filename = img.dataset.filename;
                        const url = `../photos/thumbnails/${filename}`;
                        const blobUrl = await CryptoHelper.decryptImage(url, key);
                        if (blobUrl) {
                            img.src = blobUrl;
                            img.onload = () => {
                                img.classList.remove('opacity-0');
                                img.previousElementSibling.remove(); // Remove loader
                            };
                        } else {
                            // error loading thumb
                            img.previousElementSibling.innerHTML = '<i data-lucide="alert-triangle" class="w-4 h-4 text-red-500"></i>';
                            lucide.createIcons();
                        }
                    });
                }
            }
            lucide.createIcons();
        }

        document.getElementById('apply-key-btn').addEventListener('click', async () => {
            const val = document.getElementById('manual-key-input').value.trim();
            let hex = val;
            if(val.includes('#')) hex = val.split('#').pop().trim();
            
            if(hex.length === 64 && /^[0-9a-fA-F]+$/.test(hex)) {
                await VAULT.saveKey(hex);
            } else {
                alert("Nieprawidłowy klucz zapisu (wymagane 64 znaki hex gotowe z linku klienta).");
            }
        });

        function copyToClipboard(btn) {
            const text = btn.getAttribute('data-clipboard');
            const ta = document.createElement('textarea');
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            
            const originalHtml = btn.innerHTML;
            btn.innerHTML = '<i data-lucide="check" class="w-4 h-4 mr-2"></i> Skopiowano pomyślnie!';
            btn.classList.replace('bg-[#3f3f6e]', 'bg-green-600');
            btn.classList.replace('hover:bg-cyan-600', 'hover:bg-green-500');
            btn.classList.replace('border-[#4f4f8a]', 'border-green-500');
            btn.classList.replace('hover:border-cyan-400', 'hover:border-green-400');
            lucide.createIcons();
            
            setTimeout(() => {
                btn.innerHTML = originalHtml;
                btn.classList.replace('bg-green-600', 'bg-[#3f3f6e]');
                btn.classList.replace('hover:bg-green-500', 'hover:bg-cyan-600');
                btn.classList.replace('border-green-500', 'border-[#4f4f8a]');
                btn.classList.replace('hover:border-green-400', 'hover:border-cyan-400');
                lucide.createIcons();
            }, 2500);
        }

        function escapeHtml(unsafe) {
            return unsafe
                 .replace(/&/g, "&amp;")
                 .replace(/</g, "&lt;")
                 .replace(/>/g, "&gt;")
                 .replace(/"/g, "&quot;")
                 .replace(/'/g, "&#039;");
        }

        init();
    </script>
</body>
</html>
