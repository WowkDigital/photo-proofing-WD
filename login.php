<?php
session_start();
// Dołączamy plik konfiguracyjny
require_once 'api/config.php';

// Jeśli ochrona hasłem jest wyłączona, od razu przekieruj do albumu
if (PASSWORD_PROTECTION_ENABLED === false) {
    header('Location: album.html');
    exit;
}

$error = '';

// Jeśli użytkownik jest już zalogowany, przekieruj go do albumu
if (isset($_SESSION['is_logged_in']) && $_SESSION['is_logged_in'] === true) {
    header('Location: album.html');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['password']) && $_POST['password'] === GALLERY_PASSWORD) {
        $_SESSION['is_logged_in'] = true;
        session_regenerate_id(true);
        header('Location: album.html');
        exit;
    } else {
        $error = 'Nieprawidłowe hasło.';
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logowanie do galerii</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-900 text-white flex items-center justify-center min-h-screen">
    <div class="bg-gray-800 p-8 rounded-2xl shadow-2xl w-full max-w-sm border border-gray-700">
        <h1 class="text-2xl font-bold text-center text-cyan-400 mb-6">Dostęp do galerii</h1>
        <form method="POST" action="login.php">
            <div class="mb-4">
                <label for="password" class="block text-sm font-medium text-gray-300 mb-2">Hasło:</label>
                <input type="password" id="password" name="password" required class="w-full bg-gray-700 border border-gray-600 rounded-md p-2 text-gray-200 focus:outline-none focus:ring-2 focus:ring-cyan-500">
            </div>
            <?php if ($error): ?>
                <p class="text-red-500 text-sm text-center mb-4"><?php echo $error; ?></p>
            <?php endif; ?>
            <button type="submit" class="w-full bg-cyan-600 hover:bg-cyan-700 text-white font-bold py-2 px-4 rounded-md transition-colors">Zaloguj się</button>
        </form>
    </div>
    <script src="https://cdn.jsdelivr.net/gh/WowkDigital/WowkDigitalFooter@latest/wowk-digital-footer.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            WowkDigitalFooter.init({
                siteName: 'Photo Proofing - Logowanie',
                container: 'body',
                brandName: 'Wowk Digital',
                brandUrl: 'https://github.com/WowkDigital'
            });
        });
    </script>
</body>
</html>