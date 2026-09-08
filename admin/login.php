<?php
// admin/login.php
session_start();
require_once '../api/config.php';
require_once '../api/db.php';

if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../api/logger.php';
    if (isset($_POST['password']) && password_verify($_POST['password'], ADMIN_PASSWORD_HASH)) {
        $_SESSION['admin_logged_in'] = true;
        Logger::auth('Logowanie udane', 'Panel Administratora');
        
        // Inicjalizacja sejfu kluczy
        require_once '../api/crypto_helper.php';
        $stmtSalt = $pdo->query("SELECT value FROM settings WHERE key = 'VAULT_SALT'");
        $vaultSalt = $stmtSalt->fetchColumn();
        if ($vaultSalt) {
            $_SESSION['vault_key'] = bin2hex(VaultCrypto::deriveKey($_POST['password'], $vaultSalt));
        }

        header('Location: index.php');
        exit;
    } else {
        $error = 'Nieprawidłowe hasło.';
        Logger::warn('Nieudana próba logowania', 'Błędne hasło');
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel Administratora - Logowanie</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        body {
            font-family: 'Inter', sans-serif;
            background-color: #1a1a2e;
            color: #e0e0e0;
        }
        .glass-card {
            background: rgba(44, 44, 84, 0.7);
            backdrop-filter: blur(16px);
            border: 1px solid #3f3f6e;
            border-radius: 1.5rem;
            box-shadow: 0 20px 40px -15px rgba(0, 0, 0, 0.5);
        }
        .input-field {
            background-color: #151525;
            border: 1px solid #3f3f6e;
            transition: all 0.2s ease;
        }
        .input-field:focus {
            border-color: #06b6d4;
            box-shadow: 0 0 0 2px rgba(6, 182, 212, 0.2);
            outline: none;
        }
        .btn-primary {
            background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
            box-shadow: 0 4px 12px rgba(6, 182, 212, 0.3);
            transition: all 0.2s ease;
        }
        .btn-primary:hover {
            box-shadow: 0 6px 18px rgba(6, 182, 212, 0.4);
            transform: translateY(-1px);
        }
    </style>
</head>
<body class="min-h-screen flex flex-col items-center justify-center p-4 relative overflow-hidden">
    <!-- Ambient glowing orbs -->
    <div class="fixed inset-0 z-0 overflow-hidden pointer-events-none">
        <div class="absolute top-[-10%] left-[-10%] w-[45%] h-[45%] bg-cyan-500/10 rounded-full blur-[140px]"></div>
        <div class="absolute bottom-[-10%] right-[-10%] w-[45%] h-[45%] bg-blue-600/10 rounded-full blur-[140px]"></div>
    </div>

    <div class="w-full max-w-sm relative z-10">
        <!-- Logo / Brand Header -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-cyan-500/15 rounded-2xl text-cyan-400 mb-4 border border-cyan-500/30 shadow-lg shadow-cyan-500/10">
                <i data-lucide="shield-check" class="w-8 h-8"></i>
            </div>
            <h1 class="text-2xl font-black text-white tracking-tight">Panel Administratora</h1>
            <p class="text-xs text-gray-400 mt-1">Zarządzanie sesjami i galeriami klientów</p>
        </div>

        <!-- Login Card -->
        <div class="glass-card p-8">
            <form method="POST" class="space-y-5">
                <div>
                    <label for="password" class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-2">Hasło administratora</label>
                    <div class="relative">
                        <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-500">
                            <i data-lucide="lock" class="w-4 h-4"></i>
                        </span>
                        <input type="password" id="password" name="password" required autofocus
                               class="input-field w-full pl-10 pr-4 py-3 rounded-xl text-sm text-white placeholder-gray-500"
                               placeholder="••••••••••••">
                    </div>
                </div>

                <?php if ($error): ?>
                    <div class="flex items-center space-x-2 text-red-400 bg-red-500/10 border border-red-500/20 p-3 rounded-xl text-xs">
                        <i data-lucide="alert-circle" class="w-4 h-4 shrink-0"></i>
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>

                <button type="submit" class="btn-primary w-full text-white font-bold py-3.5 px-4 rounded-xl text-sm flex items-center justify-center space-x-2 group">
                    <span>Zaloguj się</span>
                    <i data-lucide="arrow-right" class="w-4 h-4 group-hover:translate-x-1 transition-transform"></i>
                </button>
            </form>

            <div class="mt-6 pt-5 border-t border-[#3f3f6e]/60 text-center">
                <a href="../album.html" class="text-xs text-gray-400 hover:text-cyan-400 transition-colors inline-flex items-center space-x-1.5">
                    <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i>
                    <span>Wróć do albumu klienta</span>
                </a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/gh/WowkDigital/WowkDigitalFooter@latest/wowk-digital-footer.js"></script>
    <script>
        lucide.createIcons();
        document.addEventListener('DOMContentLoaded', () => {
            WowkDigitalFooter.init({
                siteName: 'Panel Administratora - Logowanie',
                container: 'body',
                brandName: 'Wowk Digital',
                brandUrl: 'https://github.com/WowkDigital'
            });
        });
    </script>
</body>
</html>
