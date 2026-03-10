<?php
// admin/view_album.php
require_once 'auth.php';
require_once '../api/db.php';

if (!isset($_GET['id'])) {
    header('Location: index.php');
    exit;
}

$id = $_GET['id'];

try {
    // Pobierz dane albumu
    $stmt = $pdo->prepare("SELECT * FROM albums WHERE id = ?");
    $stmt->execute([$id]);
    $album = $stmt->fetch();

    if (!$album) {
        die("Album nie znaleziony.");
    }

    // Pobierz wybory dla tego albumu
    $stmtSelections = $pdo->prepare("SELECT * FROM selections WHERE album_id = ? ORDER BY selection_date DESC");
    $stmtSelections->execute([$id]);
    $selections = $stmtSelections->fetchAll();

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
<body class="bg-gray-900 text-gray-200 font-sans">
    <div class="container mx-auto p-4 max-w-6xl">
        <header class="flex flex-col md:flex-row justify-between items-center mb-8 border-b border-gray-700 pb-4 gap-4">
            <div class="flex items-center">
                <a href="index.php" class="text-gray-500 hover:text-white mr-4 transition-colors"><i data-lucide="arrow-left" class="w-6 h-6"></i></a>
                <div>
                    <h1 class="text-2xl font-bold text-white"><?php echo htmlspecialchars($album['internal_name']); ?></h1>
                    <p class="text-xs text-gray-500">Slug: <?php echo htmlspecialchars($album['slug']); ?></p>
                </div>
            </div>
            
            <div class="flex items-center gap-4">
                <a href="upload.php?album_id=<?php echo $album['id']; ?>" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-md transition-colors flex items-center text-sm font-medium">
                    <i data-lucide="upload" class="w-4 h-4 mr-2"></i> Prześlij zdjęcia
                </a>
            </div>
        </header>

        <main>
            <div class="mb-8 grid grid-cols-1 md:grid-cols-4 gap-4">
                 <div class="bg-gray-800 p-4 rounded-lg border border-gray-700 col-span-1 md:col-span-3">
                     <h3 class="text-sm font-semibold text-gray-400 mb-3 uppercase tracking-wider">Podgląd Publiczny</h3>
                     <div class="flex flex-col sm:flex-row gap-3">
                         <input type="text" id="album-key-input" placeholder="Wklej klucz/hash, aby podejrzeć zdjęcia..." class="flex-grow bg-gray-900 border border-gray-700 rounded-lg p-2.5 text-sm text-gray-300 focus:border-cyan-500 outline-none">
                         <button onclick="viewAlbum()" class="bg-cyan-600 hover:bg-cyan-700 text-white px-5 py-2.5 rounded-lg transition-colors flex items-center justify-center text-sm">
                             <i data-lucide="external-link" class="w-4 h-4 mr-2"></i> Otwórz Album
                         </button>
                     </div>
                     <p class="text-[10px] text-gray-600 mt-2">Klucz jest generowany podczas przesyłania zdjęć. Znajdziesz go w podsumowaniu uploadu.</p>
                 </div>
                 <div class="bg-gray-800 p-4 rounded-lg border border-gray-700 flex flex-col justify-center items-center text-center">
                     <span class="text-xs text-gray-500 uppercase mb-1">Status</span>
                     <span class="text-green-400 font-bold flex items-center"><i data-lucide="check-circle" class="w-4 h-4 mr-1"></i> Aktywny</span>
                 </div>
            </div>

            <h2 class="text-xl font-bold text-white mb-6">Wybory Klientów (<?php echo count($selections); ?>)</h2>

            <?php if (empty($selections)): ?>
                <div class="text-center py-12 text-gray-500 bg-gray-800/20 rounded-xl border-2 border-dashed border-gray-700">
                    <i data-lucide="users" class="w-12 h-12 mx-auto mb-3 opacity-30"></i>
                    <p>Cisza... Nikt jeszcze nie wysłał swojego wyboru.</p>
                </div>
            <?php else: ?>
                <div class="grid gap-4">
                    <?php foreach ($selections as $selection): ?>
                        <div class="bg-gray-800 rounded-lg shadow-md border border-gray-700 p-5 flex flex-col md:flex-row justify-between items-start md:items-center hover:bg-gray-750 transition-colors">
                            <div class="flex-grow">
                                <div class="flex items-center mb-1">
                                    <h3 class="text-lg font-bold text-white mr-3"><?php echo htmlspecialchars($selection['client_name']); ?></h3>
                                    <span class="text-[10px] text-gray-500"><?php echo date('d.m.Y H:i', strtotime($selection['selection_date'])); ?></span>
                                </div>
                                <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-400">
                                    <?php if ($selection['client_email']): ?><span><i data-lucide="mail" class="w-3 h-3 inline mr-1"></i><?php echo htmlspecialchars($selection['client_email']); ?></span><?php endif; ?>
                                    <?php if ($selection['client_phone']): ?><span><i data-lucide="phone" class="w-3 h-3 inline mr-1"></i><?php echo htmlspecialchars($selection['client_phone']); ?></span><?php endif; ?>
                                </div>
                            </div>
                            <div class="mt-4 md:mt-0 flex items-center gap-3">
                                <a href="view_selection.php?id=<?php echo $selection['id']; ?>" class="bg-gray-700 hover:bg-cyan-600 text-white px-4 py-2 rounded-md text-sm transition-all flex items-center">
                                    <i data-lucide="file-text" class="w-4 h-4 mr-2"></i> Szczegóły
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>
    <script>
        lucide.createIcons();
        function viewAlbum() {
            const key = document.getElementById('album-key-input').value.trim();
            if (!key) { alert('Podaj klucz do odszyfrowania zdjęć.'); return; }
            const slug = "<?php echo $album['slug']; ?>";
            // Slug w URL: album.html?s=slug#key
            window.open('../album.html?s=' + slug + '#' + key, '_blank');
        }
    </script>
</body>
</html>
