<?php
// admin/index.php
require_once 'auth.php';
require_once '../api/db.php';

// Dodawanie nowego albumu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_album') {
    $slug = bin2hex(random_bytes(8)); // Losowy slug
    $internalName = $_POST['internal_name'] ?? 'Nowy Album';
    $publicTitle = $_POST['public_title'] ?? 'Galeria Zdjęć';
    
    $stmt = $pdo->prepare("INSERT INTO albums (slug, internal_name, public_title) VALUES (?, ?, ?)");
    $stmt->execute([$slug, $internalName, $publicTitle]);
    header('Location: index.php');
    exit;
}

// Usuwanie albumu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_album') {
    $albumId = (int)$_POST['album_id'];
    
    try {
        $pdo->beginTransaction();
        
        // 1. Pobierz listę plików do usunięcia
        $stmt = $pdo->prepare("SELECT filename FROM photos WHERE album_id = ?");
        $stmt->execute([$albumId]);
        $files = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // 2. Usuń z bazy
        $pdo->prepare("DELETE FROM selections WHERE album_id = ?")->execute([$albumId]);
        $pdo->prepare("DELETE FROM photos WHERE album_id = ?")->execute([$albumId]);
        $pdo->prepare("DELETE FROM albums WHERE id = ?")->execute([$albumId]);
        
        $pdo->commit();
        
        // 3. Usuń fizyczne pliki
        foreach ($files as $file) {
            @unlink("../photos/$file");
            @unlink("../photos/thumbnails/$file");
        }
        
    } catch (Exception $e) {
        $pdo->rollBack();
        die("Błąd usuwania albumu: " . $e->getMessage());
    }
    
    header('Location: index.php');
    exit;
}

// Pobierz listę albumów
try {
    $stmt = $pdo->query("SELECT a.*, 
        (SELECT filename FROM photos WHERE album_id = a.id ORDER BY filename ASC LIMIT 1) as cover_photo,
        (SELECT COUNT(*) FROM photos WHERE album_id = a.id) as photo_count,
        (SELECT COUNT(*) FROM selections WHERE album_id = a.id) as selection_count
        FROM albums a ORDER BY created_at DESC");
    $albums = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Błąd bazy danych: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="pl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel Administratora - Albumy</title>
    <script src="https://cdn.tailwindcss.com"></script>
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

        .stat-card {
            background-color: #2c2c54;
            border: 1px solid #3f3f6e;
            transition: transform 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            border-color: #4f4f8a;
        }

        .album-card {
            background-color: #2c2c54;
            border: 1px solid #3f3f6e;
            transition: all 0.3s ease;
        }
        
        .album-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3);
            border-color: #4ade80;
        }

        .btn-primary {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            box-shadow: 0 4px 6px -1px rgba(16, 185, 129, 0.2);
            transition: all 0.2s;
        }
        
        .btn-primary:hover {
            box-shadow: 0 6px 8px -1px rgba(16, 185, 129, 0.3);
            transform: translateY(-1px);
        }

        .session-vault {
            background: linear-gradient(145deg, #232342 0%, #1f1f38 100%);
            border: 1px solid #353560;
        }

        /* Modal transitions */
        .modal { transition: opacity 0.3s ease, transform 0.3s ease; }
        body.modal-active { overflow: hidden; }
    </style>
</head>

<body class="min-h-screen flex flex-col">
    <!-- Navbar -->
    <header class="dashboard-header sticky top-0 z-40 w-full">
        <div class="container mx-auto px-4 py-4 flex flex-col md:flex-row justify-between items-center gap-4">
            <div class="flex items-center space-x-3">
                <div class="bg-gradient-to-br from-cyan-500 to-blue-600 p-2.5 rounded-lg shadow-lg">
                    <i data-lucide="layout-dashboard" class="w-6 h-6 text-white"></i>
                </div>
                <div>
                    <h1 class="text-xl font-bold text-white tracking-tight">Panel Administratora</h1>
                    <p class="text-xs text-gray-400 font-medium">Zarządzanie Albumami v2.0</p>
                </div>
            </div>
            
            <div class="flex items-center gap-3">
                <button onclick="exportAllSelections()" class="bg-blue-600 hover:bg-blue-500 text-white px-5 py-2.5 rounded-xl transition-colors flex items-center text-sm font-semibold border border-transparent shadow-lg hover:shadow-xl">
                    <i data-lucide="download" class="w-4 h-4 mr-2"></i> Eksportuj Wybory
                </button>

                <button onclick="toggleModal()" class="btn-primary text-white px-5 py-2.5 rounded-xl flex items-center text-sm font-semibold group">
                    <i data-lucide="plus-circle" class="w-4 h-4 mr-2 group-hover:rotate-90 transition-transform"></i> Nowy Album
                </button>

                <a href="upload.php" class="bg-[#3f3f6e] hover:bg-[#4f4f8a] text-gray-200 px-5 py-2.5 rounded-xl transition-colors flex items-center text-sm font-semibold border border-transparent hover:border-gray-500">
                    <i data-lucide="upload-cloud" class="w-4 h-4 mr-2"></i> Prześlij
                </a>
                
                <a href="logout.php" class="ml-2 text-gray-400 hover:text-red-400 p-2 rounded-lg hover:bg-red-500/10 transition-colors" title="Wyloguj">
                    <i data-lucide="log-out" class="w-5 h-5"></i>
                </a>
            </div>
        </div>
    </header>

    <div class="container mx-auto p-4 max-w-7xl flex-grow">
        
        <!-- Stats & Vault Section -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-10 mt-6">
            <!-- Global Stats -->
            <div class="lg:col-span-1 grid grid-cols-2 gap-4">
                <div class="stat-card rounded-2xl p-5 relative overflow-hidden group">
                    <div class="absolute right-0 top-0 opacity-10 transform translate-x-2 -translate-y-2 group-hover:scale-110 transition-transform">
                        <i data-lucide="image" class="w-24 h-24 text-cyan-400"></i>
                    </div>
                    <p class="text-gray-400 text-xs font-bold uppercase tracking-wider mb-1">Wszystkie Zdjęcia</p>
                    <p class="text-3xl font-bold text-white"><?php echo array_sum(array_column($albums, 'photo_count')); ?></p>
                </div>
                <div class="stat-card rounded-2xl p-5 relative overflow-hidden group">
                    <div class="absolute right-0 top-0 opacity-10 transform translate-x-2 -translate-y-2 group-hover:scale-110 transition-transform">
                         <i data-lucide="check-circle" class="w-24 h-24 text-green-400"></i>
                    </div>
                    <p class="text-gray-400 text-xs font-bold uppercase tracking-wider mb-1">Dokonane Wybory</p>
                    <p class="text-3xl font-bold text-green-400"><?php echo array_sum(array_column($albums, 'selection_count')); ?></p>
                </div>
                <div class="stat-card rounded-2xl p-5 col-span-2 flex items-center justify-between">
                    <div>
                        <p class="text-gray-400 text-xs font-bold uppercase tracking-wider mb-1">Aktywne Albumy</p>
                        <p class="text-2xl font-bold text-white"><?php echo count($albums); ?></p>
                    </div>
                    <div class="h-10 w-10 bg-blue-500/20 rounded-full flex items-center justify-center text-blue-400">
                        <i data-lucide="folder-heart" class="w-5 h-5"></i>
                    </div>
                </div>
            </div>

            <!-- Key Vault -->
            <div class="lg:col-span-2 session-vault rounded-2xl p-6 shadow-xl relative overflow-hidden">
                <div class="absolute top-0 right-0 w-32 h-32 bg-cyan-500/10 rounded-full blur-3xl -translate-y-1/2 translate-x-1/2 pointer-events-none"></div>
                
                <div class="flex items-center justify-between mb-4 relative z-10">
                    <div class="flex items-center space-x-3">
                        <div class="bg-cyan-500/20 p-2.5 rounded-xl text-cyan-400 shadow-inner">
                            <i data-lucide="shield-check" class="w-6 h-6"></i>
                        </div>
                        <div>
                            <h2 class="text-lg font-bold text-white">Sejf Kluczy Sesji</h2>
                            <p class="text-xs text-gray-400">Automatyczne odblokowywanie podglądu</p>
                        </div>
                    </div>
                    <div class="flex items-center space-x-2 bg-black/20 px-3 py-1.5 rounded-lg border border-white/5">
                        <div class="w-2 h-2 rounded-full bg-green-500 animate-pulse"></div>
                        <span id="vaultStatus" class="text-xs font-mono text-gray-300 font-semibold">0 aktywnych</span>
                    </div>
                </div>
                
                <div class="relative group">
                    <textarea id="sessionKeysInput" 
                        placeholder="Kliknij, aby wkleić linki lub klucze..." 
                        class="w-full h-24 bg-[#151525] border border-gray-700/50 rounded-xl p-4 text-cyan-400 font-mono text-sm focus:border-cyan-500/50 focus:ring-2 focus:ring-cyan-500/20 outline-none transition-all resize-none shadow-inner placeholder-gray-600 focus:bg-[#1a1a2e]"></textarea>
                    
                    <div class="absolute bottom-3 right-3 opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none">
                        <span class="text-[10px] text-gray-500 bg-black/40 px-2 py-1 rounded">Auto-save</span>
                    </div>
                </div>
            </div>
        </div>

        <main>
            <?php if (empty($albums)): ?>
                <div class="text-center py-12 text-gray-500">
                    <i data-lucide="folder-open" class="w-16 h-16 mx-auto mb-4 opacity-50"></i>
                    <p class="text-xl">Brak albumów. Stwórz swój pierwszy album!</p>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($albums as $album): ?>
                        <div class="album-card rounded-2xl overflow-hidden shadow-lg group flex flex-col relative" 
                             data-album-id="<?php echo $album['id']; ?>" 
                             data-slug="<?php echo $album['slug']; ?>" 
                             data-key-hash="<?php echo $album['encryption_key_hash']; ?>" 
                             data-cover="<?php echo $album['cover_photo']; ?>">
                            
                            <!-- Miniaturka -->
                            <div class="album-cover aspect-video bg-[#1a1a2e] flex items-center justify-center relative overflow-hidden group-hover:shadow-2xl transition-all duration-500 cursor-not-allowed border-b border-[#3f3f6e]"
                                 onclick="if(this.dataset.href) window.open(this.dataset.href, '_blank')">
                                <i data-lucide="image" class="w-10 h-10 text-gray-700"></i>
                                <img src="" class="hidden absolute inset-0 w-full h-full object-cover z-10 transition-opacity duration-700 opacity-0" onload="this.classList.replace('opacity-0', 'opacity-100')">
                                
                                <!-- Overlay on hover -->
                                <div class="absolute inset-0 bg-black/60 opacity-0 group-hover:opacity-100 transition-opacity z-20 flex items-center justify-center backdrop-blur-[2px]">
                                    <div class="unlock-prompt text-white text-xs font-bold uppercase tracking-widest border border-white/30 px-4 py-2 rounded-full hidden">
                                        Otwórz Galerię
                                    </div>
                                </div>
                            </div>

                            <div class="p-5 flex-grow flex flex-col relative z-30 bg-[#2c2c54]">
                                <div class="flex justify-between items-start mb-2">
                                    <h2 class="text-lg font-bold text-white group-hover:text-cyan-400 transition-colors leading-snug line-clamp-1" title="<?php echo htmlspecialchars($album['internal_name']); ?>">
                                        <?php echo htmlspecialchars($album['internal_name'] ?: 'Bez nazwy'); ?>
                                    </h2>
                                </div>
                                <p class="text-xs text-gray-400 mb-5 line-clamp-1"><?php echo htmlspecialchars($album['public_title']); ?></p>
                                
                                <div class="grid grid-cols-2 gap-3 mt-auto mb-4">
                                    <div class="bg-[#1f1f38] rounded-xl p-2.5 text-center border border-[#3f3f6e]">
                                        <span class="block text-lg font-bold text-cyan-400"><?php echo $album['photo_count']; ?></span>
                                        <span class="text-[10px] text-gray-500 uppercase font-semibold">Zdjęć</span>
                                    </div>
                                    <div class="bg-[#1f1f38] rounded-xl p-2.5 text-center border border-[#3f3f6e] relative overflow-hidden">
                                        <?php if($album['selection_count'] > 0): ?>
                                            <div class="absolute top-0 right-0 w-2 h-2 bg-green-500 rounded-full m-1"></div>
                                        <?php endif; ?>
                                        <span class="block text-lg font-bold text-green-400"><?php echo $album['selection_count']; ?></span>
                                        <span class="text-[10px] text-gray-500 uppercase font-semibold">Wyborów</span>
                                    </div>
                                </div>

                                <div class="pt-4 border-t border-[#3f3f6e] flex justify-between items-end">
                                    <div class="flex flex-col">
                                        <span class="text-[10px] text-gray-500 mb-1">ID: <span class="font-mono text-gray-400"><?php echo htmlspecialchars($album['slug']); ?></span></span>
                                        <span class="text-[10px] text-gray-600"><?php echo date('d.m.Y', strtotime($album['created_at'])); ?></span>
                                    </div>

                                    <div class="flex space-x-1">
                                        <a href="../album.html?s=<?php echo $album['slug']; ?>" target="_blank" class="view-album-btn hidden p-2 text-cyan-400 hover:text-white hover:bg-cyan-500/20 rounded-lg transition-all" title="Otwórz album">
                                            <i data-lucide="external-link" class="w-4 h-4"></i>
                                        </a>
                                        <a href="view_album.php?id=<?php echo $album['id']; ?>" class="p-2 text-gray-400 hover:text-white hover:bg-[#3f3f6e] rounded-lg transition-all" title="Szczegóły">
                                            <i data-lucide="eye" class="w-4 h-4"></i>
                                        </a>
                                        <a href="upload.php?album_id=<?php echo $album['id']; ?>" class="p-2 text-gray-400 hover:text-cyan-400 hover:bg-[#3f3f6e] rounded-lg transition-all" title="Dodaj zdjęcia">
                                            <i data-lucide="upload-cloud" class="w-4 h-4"></i>
                                        </a>
                                        <form method="POST" onsubmit="return confirm('Czy usunąć album?')" class="inline">
                                            <input type="hidden" name="action" value="delete_album">
                                            <input type="hidden" name="album_id" value="<?php echo $album['id']; ?>">
                                            <button type="submit" class="p-2 text-gray-400 hover:text-red-400 hover:bg-[#3f3f6e] rounded-lg transition-all" title="Usuń">
                                                <i data-lucide="trash-2" class="w-4 h-4"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>

    </div>

    <script src="https://cdn.jsdelivr.net/gh/WowkDigital/WowkDigitalFooter@latest/wowk-digital-footer.js"></script>

    <!-- Modal Nowy Album -->
    <div id="modal" class="modal opacity-0 pointer-events-none fixed w-full h-full top-0 left-0 flex items-center justify-center z-50 px-4">
        <div class="modal-overlay absolute w-full h-full bg-black/80 backdrop-blur-sm" onclick="toggleModal()"></div>
        <div class="modal-container bg-[#2c2c54] w-full max-w-md mx-auto rounded-2xl shadow-2xl z-50 overflow-y-auto border border-[#3f3f6e] transform scale-95 transition-transform duration-300">
            <div class="modal-content py-8 px-8 text-left">
                <div class="flex justify-between items-center mb-6">
                    <p class="text-2xl font-bold text-white">Nowy Album</p>
                    <div class="modal-close cursor-pointer z-50 bg-[#1f1f38] p-2 rounded-full hover:bg-[#3f3f6e] transition-colors" onclick="toggleModal()">
                        <i data-lucide="x" class="w-5 h-5 text-gray-400"></i>
                    </div>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="create_album">
                    <div class="mb-5">
                        <label class="block text-gray-400 text-xs font-bold uppercase tracking-wider mb-2">Nazwa wewnętrzna (Admin)</label>
                        <input type="text" id="internal_name" name="internal_name" required placeholder="np. Sesja ślubna Anna i Jan" class="w-full bg-[#151525] border border-[#3f3f6e] rounded-xl p-3.5 text-white focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 outline-none transition-all placeholder-gray-600">
                    </div>
                    <div class="mb-8">
                        <label class="block text-gray-400 text-xs font-bold uppercase tracking-wider mb-2">Tytuł publiczny (Klient)</label>
                        <input type="text" id="public_title" name="public_title" required placeholder="np. Nasza Galeria Ślubna" class="w-full bg-[#151525] border border-[#3f3f6e] rounded-xl p-3.5 text-white focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 outline-none transition-all placeholder-gray-600">
                    </div>
                    <div class="flex justify-end gap-3">
                        <button type="button" onclick="toggleModal()" class="px-5 py-2.5 rounded-xl text-gray-400 font-semibold hover:bg-[#3f3f6e] transition-colors text-sm">Anuluj</button>
                        <button type="submit" class="btn-primary px-6 py-2.5 text-white font-bold rounded-xl transition-transform transform active:scale-95 text-sm shadow-lg">Stwórz Album</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        lucide.createIcons();
        
        const CryptoHelper = {
            async importKeyFromHex(hex) {
                if(!hex || hex.length !== 64) throw new Error("Invalid hex key");
                const buf = new Uint8Array(hex.match(/.{1,2}/g).map(byte => parseInt(byte, 16)));
                return await window.crypto.subtle.importKey("raw", buf, { name: "AES-GCM" }, true, ["decrypt"]);
            },
            async sha256(hex) {
                const msgBuffer = new TextEncoder().encode(hex.trim());
                const hashBuffer = await window.crypto.subtle.digest('SHA-256', msgBuffer);
                const hashArray = Array.from(new Uint8Array(hashBuffer));
                return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
            },
            async decrypt(dataBuffer, key) {
                const iv = new Uint8Array(dataBuffer.slice(0, 12));
                const ct = new Uint8Array(dataBuffer.slice(12));
                return await window.crypto.subtle.decrypt({ name: "AES-GCM", iv: iv }, key, ct);
            }
        };

        const VAULT = {
            keys: {}, // hash -> hex
            async addKeys(text) {
                // Obsługa wklejania całych linków (wyciągamy fragment po #)
                const potentialKeys = text.split(/[\n\s,]+/).map(k => {
                    const trimmed = k.trim();
                    if (trimmed.includes('#')) return trimmed.split('#').pop();
                    return trimmed;
                }).filter(k => k.length === 64 && /^[0-9a-fA-F]+$/.test(k));

                let added = 0;
                for (const hex of potentialKeys) {
                    const hash = await CryptoHelper.sha256(hex.toLowerCase());
                    if (!this.keys[hash]) {
                        this.keys[hash] = hex.toLowerCase();
                        added++;
                    }
                }
                if (added > 0) {
                    this.saveToSession();
                    this.processAlbums();
                    
                    // Zaktualizuj widok, maskując klucze
                    const maskedKeys = Object.values(this.keys).map(k => k.substring(0, 6) + '***');
                    document.getElementById('sessionKeysInput').value = maskedKeys.join('\n');
                }
            },
            saveToSession() {
                sessionStorage.setItem('admin_vault_keys', JSON.stringify(this.keys));
                document.getElementById('vaultStatus').textContent = `${Object.keys(this.keys).length} aktywnych`;
            },
            loadFromSession() {
                const saved = sessionStorage.getItem('admin_vault_keys');
                if (saved) {
                    this.keys = JSON.parse(saved);
                    document.getElementById('vaultStatus').textContent = `${Object.keys(this.keys).length} aktywnych`;
                    
                    // Wyświetl tylko maskowane klucze
                    const maskedKeys = Object.values(this.keys).map(k => k.substring(0, 6) + '***');
                    document.getElementById('sessionKeysInput').value = maskedKeys.join('\n');
                }
            },
            async processAlbums() {
                const cards = document.querySelectorAll('.album-card');
                for (const card of cards) {
                    const hash = card.dataset.keyHash;
                    if (!hash) continue;
                    
                    const hex = this.keys[hash];
                    if (hex && !card.dataset.decrypted_session) {
                        this.unlockAlbumUI(card, hex);
                    }
                }
            },
            async unlockAlbumUI(card, hex) {
                card.dataset.decrypted_session = "true";
                const coverContainer = card.querySelector('.album-cover');
                const viewBtn = card.querySelector('.view-album-btn');
                const prompt = card.querySelector('.unlock-prompt');
                const fullUrl = `../album.html?s=${card.dataset.slug}#${hex}`;

                // Aktywacja linków i UI
                if (viewBtn) {
                    viewBtn.href = fullUrl;
                    viewBtn.classList.remove('hidden');
                }

                if (coverContainer) {
                    coverContainer.dataset.href = fullUrl;
                    coverContainer.style.cursor = 'pointer';
                    coverContainer.classList.remove('cursor-not-allowed');
                    if (prompt) prompt.parentNode.classList.remove('hidden'); // Fix for new structure
                    if (prompt) prompt.classList.remove('hidden');
                }

                // Odszyfrowanie okładki jeśli jest
                const coverFilename = card.dataset.cover;
                if (coverFilename) {
                    const img = card.querySelector('.album-cover img');
                    const icon = card.querySelector('.album-cover i');
                    try {
                        const key = await CryptoHelper.importKeyFromHex(hex);
                        const res = await fetch(`../api/serve_image.php?type=thumb&file=${encodeURIComponent(coverFilename)}`);
                        if (res.ok) {
                            const decryptedBuffer = await CryptoHelper.decrypt(await res.arrayBuffer(), key);
                            const blob = new Blob([decryptedBuffer], { type: 'image/jpeg' });
                            img.src = URL.createObjectURL(blob);
                            img.classList.remove('hidden');
                            if(icon) icon.classList.add('hidden');
                        }
                    } catch (e) {
                        console.error("Błąd dekodowania okładki:", e);
                    }
                }
            }
        };

        function toggleModal() {
            const body = document.querySelector('body');
            const modal = document.querySelector('#modal');
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

        // Init Vault
        VAULT.loadFromSession();
        VAULT.processAlbums();

        const sessionInput = document.getElementById('sessionKeysInput');
        
        sessionInput.addEventListener('input', (e) => {
            VAULT.addKeys(e.target.value);
        });

        sessionInput.addEventListener('focus', () => {
            if (sessionInput.value.includes('***')) {
                sessionInput.value = '';
            }
        });

        sessionInput.addEventListener('blur', () => {
            const maskedKeys = Object.values(VAULT.keys).map(k => k.substring(0, 6) + '***');
            if (maskedKeys.length > 0) {
                sessionInput.value = maskedKeys.join('\n');
            }
        });
        async function exportAllSelections() {
            const btn = event.currentTarget;
            const originalContent = btn.innerHTML;
            btn.innerHTML = '<i data-lucide="loader-2" class="w-4 h-4 mr-2 animate-spin"></i> Pobieranie...';
            btn.disabled = true;
            lucide.createIcons();

            try {
                const response = await fetch('../api/get-all-selections.php');
                const result = await response.json();

                if (result.status !== 'success') {
                    throw new Error(result.message || 'Błąd pobierania danych');
                }

                const selections = result.data;
                const keys = VAULT.keys;

                // Przygotuj nagłówek CSV
                let csvRows = ['Album,Data,Klient,Email,Telefon,Wybrane Pliki,Notatki'];

                for (const sel of selections) {
                    const hash = sel.encryption_key_hash;
                    const hex = keys[hash];
                    let fileNames = [];

                    if (hex) {
                        const key = await CryptoHelper.importKeyFromHex(hex);
                        for (const p of sel.photos_data) {
                            try {
                                const decryptedName = await decryptString(p.original_filename, key);
                                fileNames.push(decryptedName);
                            } catch (e) {
                                fileNames.push(p.original_filename);
                            }
                        }
                    } else {
                        fileNames = sel.photos_data.map(p => p.original_filename + " (Zaszyfrowane)");
                    }

                    const row = [
                        `"${sel.album_name.replace(/"/g, '""')}"`,
                        `"${sel.selection_date}"`,
                        `"${sel.client_name.replace(/"/g, '""')}"`,
                        `"${(sel.client_email || '').replace(/"/g, '""')}"`,
                        `"${(sel.client_phone || '').replace(/"/g, '""')}"`,
                        `"${fileNames.join(', ').replace(/"/g, '""')}"`,
                        `"${(sel.client_notes || '').replace(/"/g, '""')}"`
                    ];
                    csvRows.push(row.join(','));
                }

                const csvContent = "\uFEFF" + csvRows.join('\n'); // Adding BOM for Excel compatibility (UTF-8)
                const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.setAttribute('href', url);
                link.setAttribute('download', `eksport_wyborow_${new Date().toISOString().split('T')[0]}.csv`);
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);

            } catch (e) {
                console.error(e);
                alert("Wystąpił błąd podczas eksportu: " + e.message);
            } finally {
                btn.innerHTML = originalContent;
                btn.disabled = false;
                lucide.createIcons();
            }
        }

        async function decryptString(base64str, key) {
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
        }
    </script>
</body>
</html>
