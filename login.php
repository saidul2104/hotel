<?php
session_start();
require_once 'database/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hotel Login</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gradient-to-br from-green-200 via-blue-200 to-purple-300 min-h-screen flex items-center justify-center transition-colors duration-700">
    <div class="w-full max-w-md p-8 bg-white rounded-2xl shadow-2xl border-t-8 border-gradient-to-r from-indigo-500 via-green-400 to-purple-500 animate-fade-in">
        <div class="flex flex-col items-center mb-6">
            <svg width="60" height="60" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg" class="mb-2 rounded-full shadow-lg border-4 border-indigo-300 animate-bounce-slow">
                <rect width="40" height="40" rx="8" fill="#22c55e"/>
                <rect x="10" y="18" width="20" height="12" rx="2" fill="#fff"/>
                <rect x="14" y="22" width="4" height="4" rx="1" fill="#22c55e"/>
                <rect x="22" y="22" width="4" height="4" rx="1" fill="#22c55e"/>
                <rect x="18" y="14" width="4" height="6" rx="1" fill="#22c55e"/>
            </svg>
            <h1 class="text-3xl font-extrabold text-transparent bg-clip-text bg-gradient-to-r from-indigo-700 via-green-600 to-purple-700 tracking-wide animate-gradient-x">WELCOME</h1>
            <p class="text-gray-500 mt-1 animate-fade-in">Login</p>
        </div>
        <?php if(isset($_SESSION['login_error'])): ?>
            <div class="mb-4 p-3 bg-gradient-to-r from-red-200 via-yellow-100 to-red-100 text-red-700 rounded shadow animate-fade-in">
                <?php echo $_SESSION['login_error']; unset($_SESSION['login_error']); ?>
            </div>
        <?php endif; ?>
        <form action="php/authenticate.php" method="POST" class="space-y-6">
            <div>
                <label for="username" class="block text-indigo-700 font-semibold mb-1 transition-colors duration-300">Email</label>
                <input type="email" id="username" name="username" required class="w-full px-4 py-2 border-2 border-indigo-200 rounded-lg focus:outline-none focus:ring-4 focus:ring-green-300 focus:border-green-400 transition-all duration-300 bg-gradient-to-r from-white via-blue-50 to-green-50">
            </div>
            <div>
                <label for="password" class="block text-indigo-700 font-semibold mb-1 transition-colors duration-300">Password</label>
                <input type="password" id="password" name="password" required class="w-full px-4 py-2 border-2 border-indigo-200 rounded-lg focus:outline-none focus:ring-4 focus:ring-purple-300 focus:border-purple-400 transition-all duration-300 bg-gradient-to-r from-white via-purple-50 to-green-50">
            </div>
            <button type="submit" class="w-full py-2 px-4 bg-gradient-to-r from-indigo-600 via-green-500 to-purple-600 hover:from-green-600 hover:to-indigo-700 text-white font-bold rounded-lg shadow-lg transition-all duration-300 transform hover:scale-105">Log In</button>
        </form>
        <div class="mt-6 text-center text-gray-400 text-xs animate-fade-in">&copy; <?php echo date('Y'); ?> Hotel growthsolutions. All rights reserved.</div>
    </div>
    <style>
    @keyframes gradient-x {
      0%, 100% { background-position: 0% 50%; }
      50% { background-position: 100% 50%; }
    }
    .animate-gradient-x {
      background-size: 200% 200%;
      animation: gradient-x 3s ease-in-out infinite;
    }
    @keyframes fade-in {
      from { opacity: 0; }
      to { opacity: 1; }
    }
    .animate-fade-in { animation: fade-in 1.2s ease; }
    @keyframes bounce-slow {
      0%, 100% { transform: translateY(0); }
      50% { transform: translateY(-8px); }
    }
    .animate-bounce-slow { animation: bounce-slow 2.5s infinite; }
    </style>
</body>
</html>