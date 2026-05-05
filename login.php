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
    <title>Logowanie - Photo Proofing</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap');
        body {
            font-family: 'Inter', sans-serif;
            background-color: #1a1a2e;
            color: #e0e0e0;
        }
        .glass-card {
            background: rgba(44, 44, 84, 0.6);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 1.5rem;
        }
        .input-field {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: all 0.3s ease;
        }
        .input-field:focus {
            border-color: #4ade80;
            background: rgba(255, 255, 255, 0.1);
            box-shadow: 0 0 0 2px rgba(74, 222, 128, 0.2);
        }
    </style>
</head>
<body class="min-h-screen flex flex-col items-center justify-center p-4">
    <!-- Background Elements -->
    <div class="fixed inset-0 z-0 overflow-hidden pointer-events-none">
        <div class="absolute top-[-10%] left-[-10%] w-[40%] h-[40%] bg-green-500/10 rounded-full blur-[120px]"></div>
        <div class="absolute bottom-[-10%] right-[-10%] w-[40%] h-[40%] bg-blue-500/10 rounded-full blur-[120px]"></div>
    </div>

    <div class="w-full max-w-md relative z-10">
        <!-- Logo/Header -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-green-500/20 rounded-2xl text-green-400 mb-4 border border-green-500/30">
                <i data-lucide="lock" class="w-8 h-8"></i>
            </div>
            <h1 class="text-3xl font-bold text-white mb-2">Dostęp do galerii</h1>
            <p class="text-gray-400">Wprowadź hasło, aby przejrzeć swoje zdjęcia</p>
        </div>

        <!-- Login Card -->
        <div class="glass-card p-8 shadow-2xl">
            <form method="POST" action="login.php" class="space-y-6">
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-300 mb-2">Hasło dostępu</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500">
                            <i data-lucide="key-round" class="w-5 h-5"></i>
                        </span>
                        <input type="password" id="password" name="password" required 
                               class="input-field w-full pl-11 pr-4 py-3 rounded-xl text-white placeholder-gray-500 focus:outline-none"
                               placeholder="Twoje hasło...">
                    </div>
                </div>

                <?php if ($error): ?>
                    <div class="flex items-center space-x-2 text-red-400 bg-red-400/10 border border-red-400/20 p-3 rounded-lg text-sm animate-pulse">
                        <i data-lucide="alert-circle" class="w-4 h-4"></i>
                        <span><?php echo $error; ?></span>
                    </div>
                <?php endif; ?>

                <button type="submit" 
                        class="w-full bg-green-500 hover:bg-green-600 text-white font-bold py-4 px-6 rounded-xl transition-all shadow-lg shadow-green-500/20 flex items-center justify-center space-x-2 group">
                    <span>Zaloguj się</span>
                    <i data-lucide="arrow-right" class="w-5 h-5 group-hover:translate-x-1 transition-transform"></i>
                </button>
            </form>
            
            <div class="mt-8 pt-6 border-t border-white/5 text-center">
                <a href="index.php" class="text-sm text-gray-500 hover:text-green-400 transition-colors flex items-center justify-center space-x-1">
                    <i data-lucide="chevron-left" class="w-4 h-4"></i>
                    <span>Powrót do strony głównej</span>
                </a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/gh/WowkDigital/WowkDigitalFooter@latest/wowk-digital-footer.js"></script>
    <script>
        // Inicjalizacja ikon Lucide
        lucide.createIcons();

        // Inicjalizacja footera
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