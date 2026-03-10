<?php
// admin/login.php
session_start();
require_once '../api/config.php';

if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['password']) && password_verify($_POST['password'], ADMIN_PASSWORD_HASH)) {
        $_SESSION['admin_logged_in'] = true;
        header('Location: index.php');
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
    <title>Logowanie do panelu administratora</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-900 text-white flex items-center justify-center min-h-screen">
    <div class="bg-gray-800 p-8 rounded-2xl shadow-2xl w-full max-w-sm border border-gray-700">
        <h1 class="text-2xl font-bold text-center text-cyan-400 mb-6">Panel Administratora</h1>
        <form method="POST">
            <div class="mb-4">
                <label for="password" class="block text-sm font-medium text-gray-300 mb-2">Hasło:</label>
                <input type="password" id="password" name="password" required class="w-full bg-gray-700 border border-gray-600 rounded-md p-2 text-gray-200 focus:outline-none focus:ring-2 focus:ring-cyan-500">
            </div>
            <?php if ($error): ?>
                <p class="text-red-500 text-sm text-center mb-4"><?php echo $error; ?></p>
            <?php endif; ?>
            <button type="submit" class="w-full bg-cyan-600 hover:bg-cyan-700 text-white font-bold py-2 px-4 rounded-md transition-colors">Zaloguj się</button>
        </form>
        <p class="mt-4 text-center text-gray-500 text-xs"><a href="../album.html" class="hover:underline">Wróć do albumu</a></p>
    </div>
</body>
</html>
