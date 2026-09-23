<?php
// admin/view_album.php
require_once 'auth.php';
require_once '../api/db.php';

if (!isset($_GET['id'])) {
    header('Location: index.php');
    exit;
}

$id = (int)$_GET['id'];

// Weryfikacja CSRF dla zapytań POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token(true);
}

// Obsługa edycji albumu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_album') {
    $internalName = trim($_POST['internal_name'] ?? '');
    $publicTitle = trim($_POST['public_title'] ?? '');
    if (!empty($internalName) && !empty($publicTitle)) {
        $stmt = $pdo->prepare("UPDATE albums SET internal_name = ?, public_title = ? WHERE id = ?");
        $stmt->execute([$internalName, $publicTitle, $id]);
    }
    header("Location: view_album.php?id=$id");
    exit;
}

// Obsługa zmiany statusu wyboru klienta
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_selection_status') {
    $selectionId = (int)($_POST['selection_id'] ?? 0);
    $status = $_POST['status'] ?? 'new';
    if ($selectionId > 0 && in_array($status, ['new', 'processing', 'completed'])) {
        $stmt = $pdo->prepare("UPDATE selections SET status = ? WHERE id = ? AND album_id = ?");
        $stmt->execute([$status, $selectionId, $id]);
    }
    header("Location: view_album.php?id=$id");
    exit;
}

// Obsługa usuwania pojedynczego zdjęcia
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_photo') {
    $photoId = (int)($_POST['photo_id'] ?? 0);
    if ($photoId > 0) {
        $stmtP = $pdo->prepare("SELECT filename FROM photos WHERE id = ? AND album_id = ?");
        $stmtP->execute([$photoId, $id]);
        $photoFilename = $stmtP->fetchColumn();
        if ($photoFilename) {
            $pdo->prepare("DELETE FROM selected_photos WHERE photo_filename = ?")->execute([$photoFilename]);
            $pdo->prepare("DELETE FROM photos WHERE id = ?")->execute([$photoId]);
            @unlink("../photos/$photoFilename");
            @unlink("../photos/thumbnails/$photoFilename");
        }
    }
    header("Location: view_album.php?id=$id&tab=photos");
    exit;
}

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

    // Optymalizacja: pobranie wszystkich powiązanych zdjęć jednym zapytaniem SQL
    $selectionIds = array_column($selections, 'id');
    $photosBySelection = [];
    if (!empty($selectionIds)) {
        $inPlaceholders = implode(',', array_fill(0, count($selectionIds), '?'));
        $stmtPhotos = $pdo->prepare("
            SELECT sp.selection_id, p.filename, p.original_filename 
            FROM selected_photos sp 
            JOIN photos p ON sp.photo_filename = p.filename 
            WHERE sp.selection_id IN ($inPlaceholders)
        ");
        $stmtPhotos->execute($selectionIds);
        while ($pRow = $stmtPhotos->fetch(PDO::FETCH_ASSOC)) {
            $photosBySelection[$pRow['selection_id']][] = [
                'filename' => $pRow['filename'],
                'original_filename' => $pRow['original_filename']
            ];
        }
    }

    $selectionsData = [];
    foreach ($selections as $sel) {
        $sel['photos_data'] = $photosBySelection[$sel['id']] ?? [];
        $sel['status'] = $sel['status'] ?? 'new';
        $selectionsData[] = $sel;
    }

    // Pobranie wszystkich zdjęć w albumie
    $stmtAllPhotos = $pdo->prepare("SELECT id, filename, original_filename, upload_date FROM photos WHERE album_id = ? ORDER BY id ASC");
    $stmtAllPhotos->execute([$id]);
    $allAlbumPhotos = $stmtAllPhotos->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Błąd bazy danych: " . $e->getMessage());
}

$activeTab = $_GET['tab'] ?? 'selections';
if (!in_array($activeTab, ['selections', 'photos'])) {
    $activeTab = 'selections';
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
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #1a1a2e; color: #e0e0e0; }
        .modal { transition: opacity 0.3s ease, transform 0.3s ease; }
        body.modal-active { overflow: hidden; }
    </style>
</head>
<body class="bg-[#1a1a2e] text-gray-200 min-h-screen">
    <div class="container mx-auto p-4 max-w-6xl">
        <header class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 border-b border-[#3f3f6e] pb-5 gap-4">
            <div class="flex items-center">
                <a href="index.php" class="text-gray-500 hover:text-white mr-4 p-2 rounded-lg hover:bg-[#2c2c54] transition-colors" title="Powrót do listy">
                    <i data-lucide="arrow-left" class="w-6 h-6"></i>
                </a>
                <div>
                    <div class="flex items-center gap-3">
                        <h1 class="text-2xl font-bold text-white"><?php echo htmlspecialchars($album['internal_name']); ?></h1>
                        <button onclick="toggleEditModal()" class="text-gray-400 hover:text-cyan-400 p-1.5 rounded-lg hover:bg-[#2c2c54] transition-colors" title="Edytuj nazwę albumu">
                            <i data-lucide="edit-3" class="w-4 h-4"></i>
                        </button>
                    </div>
                    <div class="flex items-center gap-3 mt-1">
                        <p class="text-xs text-gray-400"><?php echo htmlspecialchars($album['public_title']); ?></p>
                        <span class="text-gray-600">&bull;</span>
                        <p class="text-xs text-cyan-400 font-mono">Slug: <?php echo htmlspecialchars($album['slug']); ?></p>
                    </div>
                </div>
            </div>
            
            <div class="flex flex-wrap items-center gap-3">
                <button onclick="copyShareLink(this)" class="bg-[#2c2c54] hover:bg-[#3f3f6e] text-cyan-400 hover:text-white px-4 py-2.5 rounded-xl transition-all flex items-center text-sm font-semibold border border-[#3f3f6e] shadow-sm">
                    <i data-lucide="share-2" class="w-4 h-4 mr-2"></i> Kopiuj link dla klienta
                </button>
                <a href="../album.html?s=<?php echo $album['slug']; ?>" id="header-client-link" target="_blank" class="bg-[#2c2c54] hover:bg-[#3f3f6e] text-gray-200 px-4 py-2.5 rounded-xl transition-all flex items-center text-sm font-semibold border border-[#3f3f6e]">
                    <i data-lucide="external-link" class="w-4 h-4 mr-2"></i> Otwórz galerię
                </a>
                <a href="upload.php?album_id=<?php echo $album['id']; ?>" class="bg-gradient-to-r from-cyan-600 to-blue-600 hover:from-cyan-500 hover:to-blue-500 text-white px-5 py-2.5 rounded-xl transition-all flex items-center text-sm font-semibold shadow-lg hover:shadow-cyan-500/20 border border-cyan-400/20">
                    <i data-lucide="upload-cloud" class="w-4 h-4 mr-2"></i> Prześlij zdjęcia
                </a>
            </div>
        </header>

        <main>
            <!-- Panel klucza i statusu ZKA -->
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
                        <p class="text-sm text-gray-400 mb-4">Gdy klucz tego albumu znajduje się w Sejfie Kluczy, wszystkie zaszyfrowane nazwy plików oraz miniatury są automatycznie i w locie odszyfrowywane w Twojej przeglądarce.</p>
                        
                        <div class="flex flex-col sm:flex-row gap-3">
                            <input type="text" id="manual-key-input" placeholder="Wklej klucz ręcznie lub pełny link, jeśli brakuje go w sejfie..." class="flex-grow bg-[#151525] border border-[#3f3f6e] rounded-xl p-3 text-sm text-cyan-400 font-mono focus:border-cyan-500 outline-none transition-all shadow-inner focus:ring-1 focus:ring-cyan-500">
                            <button id="apply-key-btn" class="bg-[#3f3f6e] hover:bg-cyan-600 text-white px-6 py-3 rounded-xl transition-all shadow-lg text-sm font-semibold flex items-center justify-center">
                                <i data-lucide="unlock" class="w-4 h-4 mr-2"></i> Zapisz w Sejfie
                            </button>
                        </div>
                        <p id="key-status-msg" class="text-xs mt-3 flex items-center gap-1.5 hidden"></p>
                    </div>
                </div>
            </div>

            <!-- Zakładki: Wybory Klientów vs Wszystkie Zdjęcia w Albumie -->
            <div class="flex items-center gap-2 border-b border-[#3f3f6e] mb-6">
                <button onclick="switchTab('selections')" id="tab-btn-selections" class="px-5 py-3 border-b-2 font-bold text-sm flex items-center gap-2 transition-all <?php echo $activeTab === 'selections' ? 'text-cyan-400 border-cyan-400' : 'text-gray-400 border-transparent hover:text-white'; ?>">
                    <i data-lucide="check-circle" class="w-4 h-4"></i>
                    <span>Wybory Klientów</span>
                    <span class="bg-cyan-500/20 text-cyan-400 text-xs px-2 py-0.5 rounded-full ml-1"><?php echo count($selections); ?></span>
                </button>
                <button onclick="switchTab('photos')" id="tab-btn-photos" class="px-5 py-3 border-b-2 font-bold text-sm flex items-center gap-2 transition-all <?php echo $activeTab === 'photos' ? 'text-cyan-400 border-cyan-400' : 'text-gray-400 border-transparent hover:text-white'; ?>">
                    <i data-lucide="images" class="w-4 h-4"></i>
                    <span>Wszystkie zdjęcia w albumie</span>
                    <span class="bg-[#3f3f6e] text-gray-300 text-xs px-2 py-0.5 rounded-full ml-1"><?php echo count($allAlbumPhotos); ?></span>
                </button>
            </div>

            <!-- SEKCJA 1: Wybory Klientów -->
            <div id="tab-content-selections" class="<?php echo $activeTab === 'selections' ? '' : 'hidden'; ?>">
                <div id="selections-container" class="space-y-6">
                    <!-- Dynamicznie renderowane przez JS -->
                </div>
                
                <?php if (empty($selections)): ?>
                    <div class="text-center py-16 bg-[#2c2c54]/50 rounded-2xl border border-dashed border-[#3f3f6e]">
                        <div class="bg-[#1f1f38] w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4 border border-[#3f3f6e] shadow-inner">
                            <i data-lucide="inbox" class="w-8 h-8 text-gray-500"></i>
                        </div>
                        <h3 class="text-lg font-bold text-white mb-1">Cisza w eterze</h3>
                        <p class="text-gray-400 text-sm">Klienci jeszcze nie dokonali swojego wyboru dla tego albumu.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- SEKCJA 2: Wszystkie zdjęcia w albumie -->
            <div id="tab-content-photos" class="<?php echo $activeTab === 'photos' ? '' : 'hidden'; ?>">
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
                    <div>
                        <h3 class="text-lg font-bold text-white flex items-center gap-2">
                            <i data-lucide="images" class="w-5 h-5 text-cyan-400"></i>
                            Wszystkie wgrane kadry (<?php echo count($allAlbumPhotos); ?>)
                        </h3>
                        <p class="text-xs text-gray-400">Podgląd wszystkich zdjęć znajdujących się w tym albumie z opcją usunięcia pojedynczych kadrów.</p>
                    </div>
                    <a href="upload.php?album_id=<?php echo $album['id']; ?>" class="bg-[#3f3f6e] hover:bg-cyan-600 text-white px-4 py-2 rounded-xl text-xs font-bold transition-all flex items-center shadow">
                        <i data-lucide="plus" class="w-3.5 h-3.5 mr-1.5"></i> Dodaj kolejne zdjęcia
                    </a>
                </div>

                <div id="all-photos-grid" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-4">
                    <!-- Dynamicznie renderowane przez JS -->
                </div>

                <?php if (empty($allAlbumPhotos)): ?>
                    <div class="text-center py-16 bg-[#2c2c54]/50 rounded-2xl border border-dashed border-[#3f3f6e]">
                        <div class="bg-[#1f1f38] w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4 border border-[#3f3f6e] shadow-inner">
                            <i data-lucide="image" class="w-8 h-8 text-gray-500"></i>
                        </div>
                        <h3 class="text-lg font-bold text-white mb-1">Brak zdjęć w albumie</h3>
                        <p class="text-gray-400 text-sm mb-4">Ten album nie zawiera jeszcze żadnych przesłanych zdjęć.</p>
                        <a href="upload.php?album_id=<?php echo $album['id']; ?>" class="inline-flex items-center px-4 py-2 bg-cyan-600 hover:bg-cyan-500 text-white text-xs font-semibold rounded-xl transition-all shadow">
                            <i data-lucide="upload-cloud" class="w-3.5 h-3.5 mr-1.5"></i> Wgraj pierwsze zdjęcia
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Modal Edycja Albumu -->
    <div id="editModal" class="modal opacity-0 pointer-events-none fixed w-full h-full top-0 left-0 flex items-center justify-center z-50 px-4">
        <div class="modal-overlay absolute w-full h-full bg-black/80 backdrop-blur-sm" onclick="toggleEditModal()"></div>
        <div class="modal-container bg-[#2c2c54] w-full max-w-md mx-auto rounded-2xl shadow-2xl z-50 overflow-y-auto border border-[#3f3f6e] transform scale-95 transition-transform duration-300">
            <div class="modal-content py-8 px-8 text-left">
                <div class="flex justify-between items-center mb-6">
                    <p class="text-2xl font-bold text-white">Edytuj Album</p>
                    <div class="modal-close cursor-pointer z-50 bg-[#1f1f38] p-2 rounded-full hover:bg-[#3f3f6e] transition-colors" onclick="toggleEditModal()">
                        <i data-lucide="x" class="w-5 h-5 text-gray-400"></i>
                    </div>
                </div>

                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="edit_album">
                    <div class="mb-5">
                        <label class="block text-gray-400 text-xs font-bold uppercase tracking-wider mb-2">Nazwa wewnętrzna (Admin)</label>
                        <input type="text" name="internal_name" required value="<?php echo htmlspecialchars($album['internal_name']); ?>" class="w-full bg-[#151525] border border-[#3f3f6e] rounded-xl p-3.5 text-white focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 outline-none transition-all placeholder-gray-600">
                    </div>
                    <div class="mb-8">
                        <label class="block text-gray-400 text-xs font-bold uppercase tracking-wider mb-2">Tytuł publiczny (Klient)</label>
                        <input type="text" name="public_title" required value="<?php echo htmlspecialchars($album['public_title']); ?>" class="w-full bg-[#151525] border border-[#3f3f6e] rounded-xl p-3.5 text-white focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 outline-none transition-all placeholder-gray-600">
                    </div>
                    <div class="flex justify-end gap-3">
                        <button type="button" onclick="toggleEditModal()" class="px-5 py-2.5 rounded-xl text-gray-400 font-semibold hover:bg-[#3f3f6e] transition-colors text-sm">Anuluj</button>
                        <button type="submit" class="bg-gradient-to-r from-cyan-600 to-blue-600 hover:from-cyan-500 hover:to-blue-500 px-6 py-2.5 text-white font-bold rounded-xl transition-transform transform active:scale-95 text-sm shadow-lg">Zapisz Zmiany</button>
                    </div>
                </form>
            </div>
        </div>
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
        const CSRF_TOKEN = '<?php echo get_csrf_token(); ?>';
        lucide.createIcons();
        
        const selectionsData = <?php echo json_encode($selectionsData); ?>;
        const allAlbumPhotos = <?php echo json_encode($allAlbumPhotos); ?>;
        const albumSlug = "<?php echo $album['slug']; ?>";
        const albumHash = "<?php echo $album['encryption_key_hash']; ?>";
        let activeKeyHex = null;
        const DECRYPTED_STORE = {};
        let allPhotosDecrypted = [];

        // KRYPTOGRAFIA (Zero-Knowledge Decoder)
        const CryptoHelper = {
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
            async importKey(keyStr, usages = ["decrypt"]) {
                const buf = this.parseKeyToBuffer(keyStr);
                return await window.crypto.subtle.importKey("raw", buf, { name: "AES-GCM" }, true, usages);
            },
            async importKeyFromHex(hex) {
                return await this.importKey(hex, ["decrypt"]);
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
                    return base64str;
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
                const displayName = photo.original_filename_dec || photo.original_filename || photo.filename;
                this.modalInfo.textContent = `${this.currentIndex + 1} / ${this.currentPhotos.length} — ${displayName}`;
                
                try {
                    const key = await CryptoHelper.importKeyFromHex(this.activeKeyHex);
                    const url = `../api/serve_image.php?type=full&file=${encodeURIComponent(photo.filename)}`;
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
                if (this.currentPhotos.length === 0) return;
                this.currentIndex = (this.currentIndex + 1) % this.currentPhotos.length;
                this.updateImage();
            },

            prev() {
                if (this.currentPhotos.length === 0) return;
                this.currentIndex = (this.currentIndex - 1 + this.currentPhotos.length) % this.currentPhotos.length;
                this.updateImage();
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
            async loadFromDatabase() {
                try {
                    const res = await fetch('vault_api.php?action=get_all');
                    const json = await res.json();
                    if (json.success && json.keys) {
                        Object.assign(this.keys, json.keys);
                        sessionStorage.setItem('admin_vault_keys', JSON.stringify(this.keys));
                    }
                } catch(e) {
                    console.error("Błąd ładowania kluczy z bazy:", e);
                }
            },
            async saveKey(hex) {
                const hash = await CryptoHelper.sha256(hex.toLowerCase());
                this.keys[hash] = hex.toLowerCase();
                sessionStorage.setItem('admin_vault_keys', JSON.stringify(this.keys));
                
                // Zapisz do bazy danych z tokenem CSRF
                try {
                    await fetch('vault_api.php', {
                        method: 'POST',
                        headers: { 
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': CSRF_TOKEN
                        },
                        body: JSON.stringify({ action: 'add_key', key_hex: hex.toLowerCase(), key_hash: hash })
                    });
                } catch(e) {
                    console.error("Błąd zapisu klucza do bazy:", e);
                }

                init(); // Re-render z nowym kluczem
            }
        };

        function setStatus(msg, type) {
            const el = document.getElementById('key-status-msg');
            el.className = `text-sm mt-4 flex items-center font-medium gap-2 ${type === 'success' ? 'text-green-400' : 'text-orange-400'}`;
            el.innerHTML = type === 'success' ? `<i data-lucide="check-circle" class="w-4 h-4"></i> ${msg}` : `<i data-lucide="alert-triangle" class="w-4 h-4"></i> ${msg}`;
            el.classList.remove('hidden');
            lucide.createIcons();
        }

        async function init() {
            // Najpierw upewnij się, że mamy klucze z bazy danych
            await VAULT.loadFromDatabase();
            activeKeyHex = VAULT.getKey();

            const headerLink = document.getElementById('header-client-link');
            if (headerLink) {
                headerLink.href = `../album.html?s=${albumSlug}${activeKeyHex ? '#' + activeKeyHex : ''}`;
            }

            if (activeKeyHex) {
                setStatus('Aktywowano deszyfrowanie. Klucz z Twojego Sejfu odblokowuje prawdziwe nazwy plików i miniatury.', 'success');
                const inp = document.getElementById('manual-key-input');
                inp.value = '**************** (Klucz działa, pochodzi z Sejfu i jest w pamięci RAM)';
                inp.disabled = true;
                inp.classList.replace('text-cyan-400', 'text-green-500');
                document.getElementById('apply-key-btn').style.display = 'none';
            } else {
                setStatus('Status zaszyfrowany (ZKA). Wklej klucz dostępu powyżej, aby odszyfrować kadry i miniatury.', 'warning');
            }

            await renderSelections(activeKeyHex);
            await renderAllPhotos(activeKeyHex);
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
                DECRYPTED_STORE[sel.id] = decryptedNames;
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

                const statusBadges = {
                    'new': { label: '🟡 Nowe', bg: 'bg-yellow-500/10 text-yellow-400 border-yellow-500/30' },
                    'processing': { label: '🔵 W realizacji', bg: 'bg-blue-500/10 text-blue-400 border-blue-500/30' },
                    'completed': { label: '🟢 Zrealizowane', bg: 'bg-green-500/10 text-green-400 border-green-500/30' }
                };
                const curStatus = sel.status || 'new';

                const cardId = `sel-card-${sel.id}`;
                const cardHtml = `
                    <div id="${cardId}" class="bg-[#2c2c54] rounded-2xl border border-[#3f3f6e] shadow-xl overflow-hidden flex flex-col lg:flex-row group transition-all hover:border-[#4f4f8a]">
                        <!-- Lewy panel - Wizytówka -->
                        <div class="p-6 lg:w-[35%] border-b lg:border-b-0 lg:border-r border-[#3f3f6e] bg-[#232342]/70 flex flex-col justify-between relative">
                            <div>
                                <div class="flex items-center justify-between gap-2 mb-2">
                                    <h3 class="text-xl font-bold text-white tracking-tight flex items-center">
                                        <span class="bg-cyan-500/20 text-cyan-400 p-1.5 rounded-lg mr-3 shadow-inner"><i data-lucide="user" class="w-5 h-5"></i></span>
                                        ${escapeHtml(sel.client_name)}
                                    </h3>
                                </div>
                                <p class="text-[11px] font-semibold text-gray-500 mb-4 flex items-center ml-11"><i data-lucide="calendar" class="w-3.5 h-3.5 mr-1.5"></i> ${dateStr}</p>

                                <!-- Status wyboru -->
                                <div class="mb-5 ml-2 p-2.5 bg-[#151525] rounded-xl border border-[#3f3f6e] flex items-center justify-between">
                                    <span class="text-xs text-gray-400 font-semibold">Status:</span>
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="csrf_token" value="${CSRF_TOKEN}">
                                        <input type="hidden" name="action" value="update_selection_status">
                                        <input type="hidden" name="selection_id" value="${sel.id}">
                                        <select name="status" onchange="this.form.submit()" class="bg-[#1f1f38] text-xs font-bold rounded-lg px-2.5 py-1.5 border border-[#3f3f6e] outline-none cursor-pointer text-gray-200">
                                            <option value="new" ${curStatus === 'new' ? 'selected' : ''}>🟡 Nowe</option>
                                            <option value="processing" ${curStatus === 'processing' ? 'selected' : ''}>🔵 W realizacji</option>
                                            <option value="completed" ${curStatus === 'completed' ? 'selected' : ''}>🟢 Zrealizowane</option>
                                        </select>
                                    </form>
                                </div>
                                
                                <div class="flex flex-col gap-2.5 text-xs text-gray-400 ml-2 font-medium">
                                    ${clientDetails}
                                </div>
                            </div>
                            
                            ${notesHtml}
                        </div>

                        <!-- Prawy panel - Raport i Miniaturki -->
                        <div class="p-6 lg:w-[65%] flex flex-col gap-5">
                            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">
                                <h4 class="text-sm font-bold text-gray-300 uppercase tracking-widest flex items-center">
                                    <i data-lucide="images" class="w-4 h-4 mr-2 text-blue-400"></i> ${sel.photos_data.length} wybranych kadrów
                                </h4>
                                
                                <!-- Formaty kopiowania -->
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <button onclick="copyFilesList(this, ${sel.id}, 'newline')" class="bg-[#3f3f6e] hover:bg-cyan-600 text-white px-3 py-1.5 rounded-lg text-xs font-semibold transition-all flex items-center shadow" title="Kopiuj nazwy w nowych liniach">
                                        <i data-lucide="copy" class="w-3 h-3 mr-1.5"></i> Linie
                                    </button>
                                    <button onclick="copyFilesList(this, ${sel.id}, 'comma')" class="bg-[#3f3f6e] hover:bg-cyan-600 text-white px-3 py-1.5 rounded-lg text-xs font-semibold transition-all flex items-center shadow" title="Kopiuj nazwy rozdzielone przecinkami (dla filtra Lightroom)">
                                        <i data-lucide="copy" class="w-3 h-3 mr-1.5"></i> Po przecinku
                                    </button>
                                    <button onclick="copyFilesList(this, ${sel.id}, 'noext')" class="bg-[#3f3f6e] hover:bg-cyan-600 text-white px-3 py-1.5 rounded-lg text-xs font-semibold transition-all flex items-center shadow" title="Kopiuj same nazwy bez rozszerzeń (.jpg)">
                                        <i data-lucide="copy" class="w-3 h-3 mr-1.5"></i> Bez .jpg
                                    </button>
                                </div>
                            </div>

                            <!-- Grid miniatur -->
                            <div class="thumbnails-grid grid grid-cols-4 sm:grid-cols-6 md:grid-cols-8 gap-2 p-3 bg-[#151525] rounded-xl border border-[#3f3f6e]">
                                ${decryptedNames.map((p, i) => `
                                    <div class="aspect-square bg-[#1a1a2e] rounded-lg border border-[#3f3f6e] overflow-hidden relative group-thumb" title="${escapeHtml(p.original_filename_dec)}">
                                        ${isDecrypted ? `
                                             <div class="absolute inset-0 flex items-center justify-center opacity-30 thumb-loader">
                                                 <i data-lucide="loader-2" class="w-4 h-4 animate-spin text-cyan-500"></i>
                                             </div>
                                             <button onclick="UI.showFullImage(${i}, DECRYPTED_STORE[${sel.id}], activeKeyHex)" class="w-full h-full block group/item">
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
                            
                            <div class="relative flex-grow h-36 group/textarea">
                                <textarea readonly class="w-full h-full min-h-[8rem] bg-[#151525] border border-[#3f3f6e] rounded-xl p-3 text-xs font-mono focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 outline-none transition-all resize-y shadow-inner ${isDecrypted ? 'text-emerald-400 selection:bg-emerald-900/50' : 'text-gray-600 selection:bg-gray-800'} scrollbar-thin scrollbar-thumb-gray-700 scrollbar-track-transparent">
${!isDecrypted ? '==================================================\n⚠️ STATUS ZASZYFROWANY (Zero-Knowledge Architecture)\n==================================================\nAby odszyfrować prawdziwe nazwy plików, wklej klucz w panelu powyżej.\n==================================================\n' : ''}${fileNamesOnly}</textarea>
                            </div>
                        </div>
                    </div>
                `;
                container.insertAdjacentHTML('beforeend', cardHtml);
                
                // Ładowanie miniatur
                if (key) {
                    const cardEl = document.getElementById(cardId);
                    const images = cardEl.querySelectorAll('img[data-filename]');
                    images.forEach(async img => {
                        const filename = img.dataset.filename;
                        const url = `../api/serve_image.php?type=thumb&file=${encodeURIComponent(filename)}`;
                        const blobUrl = await CryptoHelper.decryptImage(url, key);
                        if (blobUrl) {
                            img.src = blobUrl;
                            img.onload = () => {
                                img.classList.remove('opacity-0');
                                if (img.previousElementSibling) img.previousElementSibling.remove();
                            };
                        }
                    });
                }
            }
            lucide.createIcons();
        }

        async function renderAllPhotos(keyHex) {
            const grid = document.getElementById('all-photos-grid');
            if (!grid) return;
            grid.innerHTML = '';

            let key = null;
            if (keyHex) {
                try { key = await CryptoHelper.importKeyFromHex(keyHex); }
                catch(e) { console.error('Błędny klucz:', e); }
            }

            allPhotosDecrypted = [];
            for (let i = 0; i < allAlbumPhotos.length; i++) {
                const p = allAlbumPhotos[i];
                let decName = p.original_filename;
                if (key) {
                    try {
                        decName = await CryptoHelper.decryptString(p.original_filename, key);
                    } catch(e) {}
                }
                allPhotosDecrypted.push({
                    ...p,
                    original_filename_dec: decName
                });
            }

            allPhotosDecrypted.forEach((photo, idx) => {
                const card = document.createElement('div');
                card.className = 'bg-[#2c2c54] rounded-xl border border-[#3f3f6e] overflow-hidden flex flex-col group relative hover:border-cyan-400 transition-all';
                card.innerHTML = `
                    <div class="aspect-square bg-[#151525] relative overflow-hidden flex items-center justify-center cursor-pointer" onclick="UI.showFullImage(${idx}, allPhotosDecrypted, activeKeyHex)">
                        <div class="absolute inset-0 flex items-center justify-center opacity-30 thumb-loader">
                            <i data-lucide="loader-2" class="w-5 h-5 animate-spin text-cyan-500"></i>
                        </div>
                        <img data-all-filename="${photo.filename}" class="w-full h-full object-cover opacity-0 transition-transform duration-300 group-hover:scale-105" alt="">
                        <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center">
                            <i data-lucide="maximize-2" class="w-6 h-6 text-white drop-shadow"></i>
                        </div>
                    </div>
                    <div class="p-2.5 flex items-center justify-between gap-1 bg-[#1f1f38] border-t border-[#3f3f6e]">
                        <span class="text-[11px] font-mono text-gray-300 truncate" title="${escapeHtml(photo.original_filename_dec)}">
                            ${escapeHtml(photo.original_filename_dec)}
                        </span>
                        <form method="POST" onsubmit="return confirm('Czy na pewno chcesz usunąć to zdjęcie z albumu?')" class="inline shrink-0">
                            <input type="hidden" name="csrf_token" value="${CSRF_TOKEN}">
                            <input type="hidden" name="action" value="delete_photo">
                            <input type="hidden" name="photo_id" value="${photo.id}">
                            <button type="submit" class="p-1 text-gray-400 hover:text-red-400 hover:bg-red-500/10 rounded transition-colors" title="Usuń zdjęcie">
                                <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                            </button>
                        </form>
                    </div>
                `;
                grid.appendChild(card);
            });

            // Ładowanie miniatur dla wszystkich zdjęć
            if (key) {
                const images = grid.querySelectorAll('img[data-all-filename]');
                images.forEach(async img => {
                    const filename = img.dataset.allFilename;
                    const url = `../api/serve_image.php?type=thumb&file=${encodeURIComponent(filename)}`;
                    const blobUrl = await CryptoHelper.decryptImage(url, key);
                    if (blobUrl) {
                        img.src = blobUrl;
                        img.onload = () => {
                            img.classList.remove('opacity-0');
                            if (img.previousElementSibling) img.previousElementSibling.remove();
                        };
                    }
                });
            }
            lucide.createIcons();
        }

        function switchTab(tab) {
            const btnSel = document.getElementById('tab-btn-selections');
            const btnPhotos = document.getElementById('tab-btn-photos');
            const contentSel = document.getElementById('tab-content-selections');
            const contentPhotos = document.getElementById('tab-content-photos');

            if (tab === 'selections') {
                btnSel.className = "px-5 py-3 border-b-2 font-bold text-sm flex items-center gap-2 transition-all text-cyan-400 border-cyan-400";
                btnPhotos.className = "px-5 py-3 border-b-2 font-bold text-sm flex items-center gap-2 transition-all text-gray-400 border-transparent hover:text-white";
                contentSel.classList.remove('hidden');
                contentPhotos.classList.add('hidden');
            } else {
                btnPhotos.className = "px-5 py-3 border-b-2 font-bold text-sm flex items-center gap-2 transition-all text-cyan-400 border-cyan-400";
                btnSel.className = "px-5 py-3 border-b-2 font-bold text-sm flex items-center gap-2 transition-all text-gray-400 border-transparent hover:text-white";
                contentPhotos.classList.remove('hidden');
                contentSel.classList.add('hidden');
            }
        }

        function toggleEditModal() {
            const body = document.querySelector('body');
            const modal = document.querySelector('#editModal');
            const modalContent = modal.querySelector('.modal-container');
            
            modal.classList.toggle('opacity-0');
            modal.classList.toggle('pointer-events-none');
            body.classList.toggle('modal-active');
            
            if (!modal.classList.contains('opacity-0')) {
                modalContent.classList.remove('scale-95');
                modalContent.classList.add('scale-100');
            } else {
                modalContent.classList.remove('scale-100');
                modalContent.classList.add('scale-95');
            }
        }

        function copyShareLink(btn) {
            const host = window.location.origin;
            const path = window.location.pathname;
            const projectRoot = path.substring(0, path.lastIndexOf('/admin/'));
            let url = `${host}${projectRoot}/album.html?s=${albumSlug}`;
            if (activeKeyHex) {
                url += `#${activeKeyHex}`;
            }

            navigator.clipboard.writeText(url).then(() => {
                const orig = btn.innerHTML;
                btn.innerHTML = '<i data-lucide="check" class="w-4 h-4 mr-2 text-green-400"></i> Skopiowano link!';
                lucide.createIcons();
                setTimeout(() => {
                    btn.innerHTML = orig;
                    lucide.createIcons();
                }, 2500);
            }).catch(e => {
                prompt("Skopiuj link dla klienta:", url);
            });
        }

        function copyFilesList(btn, selectionId, format) {
            const photos = DECRYPTED_STORE[selectionId] || [];
            let text = '';

            if (format === 'comma') {
                text = photos.map(p => p.original_filename_dec).join(', ');
            } else if (format === 'noext') {
                text = photos.map(p => {
                    const name = p.original_filename_dec;
                    const lastDot = name.lastIndexOf('.');
                    return lastDot !== -1 ? name.substring(0, lastDot) : name;
                }).join(', ');
            } else {
                // domyślnie linie
                text = photos.map(p => p.original_filename_dec).join('\n');
            }

            navigator.clipboard.writeText(text).then(() => {
                const orig = btn.innerHTML;
                btn.innerHTML = '<i data-lucide="check" class="w-3 h-3 mr-1.5 text-green-400"></i> Skopiowano!';
                btn.classList.add('bg-green-600');
                lucide.createIcons();
                setTimeout(() => {
                    btn.innerHTML = orig;
                    btn.classList.remove('bg-green-600');
                    lucide.createIcons();
                }, 2000);
            });
        }

        document.getElementById('apply-key-btn').addEventListener('click', async () => {
            const val = document.getElementById('manual-key-input').value.trim();
            let key = val;
            if(val.includes('#')) key = val.split('#').pop().trim();
            
            try {
                CryptoHelper.parseKeyToBuffer(key);
                await VAULT.saveKey(key);
            } catch (e) {
                alert("Nieprawidłowy klucz (wymagany poprawny Base64, Hex lub pełny link z #kluczem).");
            }
        });

        function escapeHtml(unsafe) {
            if (!unsafe) return '';
            return unsafe
                 .replace(/&/g, "&amp;")
                 .replace(/</g, "&lt;")
                 .replace(/>/g, "&gt;")
                 .replace(/"/g, "&quot;")
                 .replace(/'/g, "&#039;");
        }

        init();
    </script>
    <script src="https://cdn.jsdelivr.net/gh/WowkDigital/WowkDigitalFooter@latest/wowk-digital-footer.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            WowkDigitalFooter.init({
                siteName: 'Photo Proofing - Wyniki',
                container: 'body',
                brandName: 'Wowk Digital',
                brandUrl: 'https://github.com/WowkDigital'
            });
        });
    </script>
</body>
</html>
