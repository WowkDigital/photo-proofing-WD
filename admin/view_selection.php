<?php
// admin/view_selection.php
require_once 'auth.php';
require_once '../api/db.php';

if (!isset($_GET['id'])) {
    header('Location: index.php');
    exit;
}

$id = $_GET['id'];

try {
    // Pobierz dane sesji
    $stmt = $pdo->prepare("SELECT * FROM selections WHERE id = ?");
    $stmt->execute([$id]);
    $selection = $stmt->fetch();

    if (!$selection) {
        die("Sesja nie znaleziona.");
    }

    // Pobierz zdjęcia
    $stmtPhotos = $pdo->prepare("SELECT p.original_filename FROM selected_photos sp JOIN photos p ON sp.photo_filename = p.filename WHERE sp.selection_id = ?");
    $stmtPhotos->execute([$id]);
    $photos = $stmtPhotos->fetchAll(PDO::FETCH_COLUMN);

} catch (PDOException $e) {
    die("Błąd bazy danych: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Szczegóły Wyboru - <?php echo htmlspecialchars($selection['client_name']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="bg-gray-900 text-gray-200 font-sans min-h-screen">
    <div class="container mx-auto p-4 max-w-5xl">
        <header class="flex items-center mb-8 border-b border-gray-700 pb-4">
            <a href="view_album.php?id=<?php echo $selection['album_id']; ?>" class="text-gray-400 hover:text-white transition-colors mr-4">
                <i data-lucide="arrow-left" class="w-6 h-6"></i>
            </a>
            <h1 class="text-2xl font-bold text-white">Szczegóły Wyboru: <span class="text-cyan-400"><?php echo htmlspecialchars($selection['client_name'] ?? 'Klient'); ?></span></h1>
        </header>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Sidebar: Dane Klienta -->
            <div class="lg:col-span-1 space-y-6">
                <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 shadow-lg sticky top-6">
                    <h2 class="text-lg font-semibold text-white mb-4 border-b border-gray-700 pb-2">Dane Kontaktowe</h2>
                    <ul class="space-y-4 text-sm">
                        <li class="flex flex-col">
                            <span class="text-gray-500 text-xs uppercase tracking-wider">Imię / Nickname</span>
                            <span class="font-medium text-white text-lg"><?php echo htmlspecialchars($selection['client_name']); ?></span>
                        </li>
                        <?php if ($selection['client_email']): ?>
                        <li class="flex flex-col">
                            <span class="text-gray-500 text-xs uppercase tracking-wider">Email</span>
                            <a href="mailto:<?php echo htmlspecialchars($selection['client_email']); ?>" class="text-blue-400 hover:underline break-all"><?php echo htmlspecialchars($selection['client_email']); ?></a>
                        </li>
                        <?php endif; ?>
                        <?php if ($selection['client_phone']): ?>
                        <li class="flex flex-col">
                            <span class="text-gray-500 text-xs uppercase tracking-wider">Telefon</span>
                            <a href="tel:<?php echo htmlspecialchars($selection['client_phone']); ?>" class="text-green-400 hover:underline"><?php echo htmlspecialchars($selection['client_phone']); ?></a>
                        </li>
                        <?php endif; ?>
                        
                        <!-- Social Media -->
                        <?php if ($selection['client_telegram'] || $selection['client_instagram'] || $selection['client_facebook']): ?>
                            <li class="pt-4 border-t border-gray-700">
                                <span class="text-gray-500 text-xs uppercase tracking-wider block mb-2">Social Media</span>
                                <div class="space-y-2">
                                    <?php if ($selection['client_telegram']): ?>
                                        <div class="flex items-center space-x-2"><i data-lucide="send" class="w-4 h-4 text-blue-400"></i><span><?php echo htmlspecialchars($selection['client_telegram']); ?></span></div>
                                    <?php endif; ?>
                                    <?php if ($selection['client_instagram']): ?>
                                        <div class="flex items-center space-x-2"><i data-lucide="instagram" class="w-4 h-4 text-pink-500"></i><span><?php echo htmlspecialchars($selection['client_instagram']); ?></span></div>
                                    <?php endif; ?>
                                    <?php if ($selection['client_facebook']): ?>
                                        <div class="flex items-center space-x-2"><i data-lucide="facebook" class="w-4 h-4 text-blue-600"></i><span><?php echo htmlspecialchars($selection['client_facebook']); ?></span></div>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endif; ?>

                        <?php if ($selection['client_notes']): ?>
                        <li class="pt-4 border-t border-gray-700">
                            <span class="text-gray-500 text-xs uppercase tracking-wider block mb-2">Notatki</span>
                            <p class="text-gray-300 italic bg-gray-900/50 p-3 rounded-md border border-gray-700/50">"<?php echo nl2br(htmlspecialchars($selection['client_notes'])); ?>"</p>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <!-- Content: Lista Zdjęć -->
            <div class="lg:col-span-2">
                <div class="bg-gray-800 rounded-lg border border-gray-700 shadow-lg overflow-hidden flex flex-col h-full">
                    <div class="p-4 border-b border-gray-700 flex flex-col sm:flex-row justify-between items-center bg-gray-800/50 gap-4">
                        <h2 class="text-lg font-semibold text-white">Wybrane Zdjęcia (<?php echo count($photos); ?>)</h2>
                        
                        <div class="flex items-center space-x-2 w-full sm:w-auto">
                            <input type="text" id="admin-key" placeholder="Wklej klucz z linku..." class="w-full sm:w-64 bg-gray-900 border border-gray-600 rounded-md py-1.5 px-3 text-sm text-cyan-400 font-mono focus:border-cyan-500 outline-none">
                            <button id="decrypt-btn" class="bg-cyan-600 hover:bg-cyan-700 text-white text-sm font-medium py-1.5 px-4 rounded-md transition-colors whitespace-nowrap">
                                Odszyfruj nazwy
                            </button>
                        </div>
                    </div>
                    
                    <div class="p-4 bg-gray-900/30 flex-grow flex flex-col">
                        <div class="flex justify-between items-center mb-2">
                            <span class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Lista plików:</span>
                            <button onclick="copyFilenames()" class="text-gray-400 hover:text-white text-xs font-medium flex items-center transition-colors">
                                <i data-lucide="copy" class="w-4 h-4 mr-1"></i> Kopiuj
                            </button>
                        </div>
                        <textarea id="filenames-list" class="w-full flex-grow bg-gray-900 border border-gray-700 rounded-md p-3 text-sm text-gray-400 font-mono min-h-[16rem] focus:ring-2 focus:ring-cyan-500 focus:outline-none" readonly>Nazwy plików są zanonimizowane i zaszyfrowane (Zero-Knowledge Architecture).
Wklej klucz sesji w pole powyżej i kliknij 'Odszyfruj nazwy', aby pobrać oryginalne nazwy plików.</textarea>
                    </div>

                    <div class="p-6 text-center text-gray-500 text-sm border-t border-gray-700 bg-gray-800/80">
                        <i data-lucide="lock" class="w-8 h-8 mx-auto mb-2 opacity-50"></i>
                        <p>Podgląd samych obrazów jest niedostępny, ponieważ pliki na serwerze są zaszyfrowane End-to-End.</p>
                        <p>Klucz służy tylko do odszyfrowania prawdziwych nazw plików wybranych przez klienta.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
        lucide.createIcons();
        function copyFilenames() {
            const textarea = document.getElementById('filenames-list');
            textarea.select();
            document.execCommand('copy'); 
            alert('Skopiowano listę plików do schowka!');
        }

        const encryptedPhotos = <?php echo json_encode($photos); ?>;
        
        // --- KRYPTOGRAFIA KLIENTA DLA ADMINA ---
        function hexStringToArrayBuffer(hexString) {
            const bytes = new Uint8Array(hexString.length / 2);
            for (let i = 0; i < hexString.length; i += 2) {
                bytes[i / 2] = parseInt(hexString.substring(i, i + 2), 16);
            }
            return bytes.buffer;
        }

        async function importKey(hexKey) {
            if (hexKey.length !== 64) throw new Error("Nieprawidłowa długość klucza.");
            const keyBuffer = hexStringToArrayBuffer(hexKey);
            return crypto.subtle.importKey("raw", keyBuffer, { name: "AES-GCM" }, true, ["decrypt"]);
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
                return base64str; // Fallback
            }
        }

        document.getElementById('decrypt-btn').addEventListener('click', async () => {
            const keyInput = document.getElementById('admin-key').value.trim();
            if (!keyInput) return alert('Podaj klucz!');
            
            const btn = document.getElementById('decrypt-btn');
            btn.textContent = 'Trwa...';
            btn.disabled = true;

            try {
                const key = await importKey(keyInput);
                const decryptedNames = [];
                for (const encName of encryptedPhotos) {
                    const decName = await decryptString(encName, key);
                    decryptedNames.push(decName);
                }

                // Sortuj alfabetycznie odszyfrowane nazwy
                decryptedNames.sort((a, b) => a.localeCompare(b));

                const textarea = document.getElementById('filenames-list');
                textarea.value = decryptedNames.join('\n');
                textarea.classList.remove('text-gray-400');
                textarea.classList.add('text-green-400');
                btn.textContent = 'Rozszyfrowano!';
                btn.classList.replace('bg-cyan-600', 'bg-green-600');
            } catch (error) {
                alert('Błąd deszyfrowania! Upewnij się, że podałeś prawidłowy klucz.\n' + error.message);
                btn.textContent = 'Odszyfruj nazwy';
                btn.disabled = false;
            }
        });
    </script>
</body>
</html>
