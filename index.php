<?php
/**
 * Strona główna aplikacji Photo Proofing
 */
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Photo Proofing - System Wyboru Zdjęć</title>
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
            border-radius: 1rem;
        }
        .feature-card {
            background-color: #2c2c54;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border: 1px solid #374151;
        }
        .feature-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.3);
            border-color: #4ade80;
        }
    </style>
</head>
<body class="min-h-screen flex flex-col">
    <!-- Hero Section -->
    <header class="relative py-20 px-4 overflow-hidden">
        <div class="absolute inset-0 z-0">
            <div class="absolute top-[-10%] left-[-10%] w-[40%] h-[40%] bg-green-500/10 rounded-full blur-[120px]"></div>
            <div class="absolute bottom-[-10%] right-[-10%] w-[40%] h-[40%] bg-blue-500/10 rounded-full blur-[120px]"></div>
        </div>
        
        <div class="max-w-4xl mx-auto text-center relative z-10">
            <div class="inline-flex items-center space-x-2 px-3 py-1 bg-green-500/10 border border-green-500/20 rounded-full text-green-400 text-sm font-medium mb-6">
                <i data-lucide="camera" class="w-4 h-4"></i>
                <span>Photo Proofing System v2.0</span>
            </div>
            <h1 class="text-5xl md:text-7xl font-bold mb-6 tracking-tight text-white">
                Twój Profesjonalny <br>
                <span class="text-transparent bg-clip-text bg-gradient-to-r from-green-400 to-emerald-500">System Wyboru Zdjęć</span>
            </h1>
            <p class="text-xl text-gray-400 mb-10 max-w-2xl mx-auto leading-relaxed">
                Wygodne narzędzie dla fotografów i klientów. Przeglądaj, wybieraj i zamawiaj ujęcia w nowoczesnym, responsywnym interfejsie.
            </p>
            
            <div class="flex flex-wrap justify-center gap-4">
                <a href="login.php" class="px-8 py-4 bg-green-500 hover:bg-green-600 text-white font-bold rounded-xl transition-all shadow-lg shadow-green-500/20 flex items-center space-x-2">
                    <i data-lucide="log-in" class="w-5 h-5"></i>
                    <span>Zaloguj się do galerii</span>
                </a>
                <a href="admin" class="px-8 py-4 bg-gray-700 hover:bg-gray-600 text-white font-bold rounded-xl transition-all flex items-center space-x-2">
                    <i data-lucide="layout-dashboard" class="w-5 h-5"></i>
                    <span>Panel Administratora</span>
                </a>
            </div>
        </div>
    </header>

    <!-- Features -->
    <section class="py-20 px-4 bg-black/20">
        <div class="max-w-6xl mx-auto">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <!-- Feature 1 -->
                <div class="feature-card p-8 rounded-2xl">
                    <div class="w-12 h-12 bg-green-500/20 rounded-lg flex items-center justify-center text-green-400 mb-6">
                        <i data-lucide="layout-grid" class="w-6 h-6"></i>
                    </div>
                    <h3 class="text-xl font-bold mb-3 text-white">Intuicyjny Grid</h3>
                    <p class="text-gray-400">Płynna zmiana układu od 2 do 6 kolumn. Optymalne przeglądanie na każdym urządzeniu.</p>
                </div>
                <!-- Feature 2 -->
                <div class="feature-card p-8 rounded-2xl">
                    <div class="w-12 h-12 bg-blue-500/20 rounded-lg flex items-center justify-center text-blue-400 mb-6">
                        <i data-lucide="lock" class="w-6 h-6"></i>
                    </div>
                    <h3 class="text-xl font-bold mb-3 text-white">Bezpieczeństwo</h3>
                    <p class="text-gray-400">Opcjonalne szyfrowanie AES-GCM zapewnia, że tylko powołane osoby mają dostęp do zdjęć.</p>
                </div>
                <!-- Feature 3 -->
                <div class="feature-card p-8 rounded-2xl">
                    <div class="w-12 h-12 bg-purple-500/20 rounded-lg flex items-center justify-center text-purple-400 mb-6">
                        <i data-lucide="zap" class="w-6 h-6"></i>
                    </div>
                    <h3 class="text-xl font-bold mb-3 text-white">Szybki Wybór</h3>
                    <p class="text-gray-400">Błyskawiczne zaznaczanie ujęć i gotowy formularz zamówienia z integracją social media.</p>
                </div>
            </div>
        </div>
    </section>

    <script src="https://cdn.jsdelivr.net/gh/WowkDigital/WowkDigitalFooter@latest/wowk-digital-footer.js"></script>

    <script>
        // Inicjalizacja ikon Lucide
        lucide.createIcons();
    </script>
</body>
</html>
