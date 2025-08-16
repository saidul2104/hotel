<?php
session_start();
require_once 'database/config.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn = get_db_connection();
    $username = $conn->real_escape_string($_POST['username']);
    $password = $_POST['password'];
    $sql = "SELECT * FROM users WHERE username='$username'";
    $result = $conn->query($sql);
    if ($result && $result->num_rows === 1) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password'])) {
            $_SESSION['user'] = [
                'id' => $user['id'],
                'username' => $user['username'],
                'role' => $user['role'],
                'hotel_id' => $user['hotel_id']
            ];
            header("Location: index.php");
            exit;
        } else {
            $error = "Invalid password.";
        }
    } else {
        $error = "User not found.";
    }
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Hotel Management Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-blue-900 to-indigo-700 min-h-screen flex items-center justify-center">
    <div class="bg-white/90 shadow-2xl rounded-xl p-10 w-full max-w-md">
        <div class="flex flex-col items-center mb-6">
            <img src="assets/icons/hotel.svg" class="w-16 h-16 mb-2" alt="Logo">
            <h1 class="text-3xl font-bold text-indigo-800">Hotel Manager Login</h1>
        </div>
        <?php if ($error): ?>
            <div class="bg-red-100 text-red-700 px-4 py-2 rounded mb-4"><?= $error ?></div>
        <?php endif; ?>
        <form method="post" class="space-y-6">
            <input type="text" name="username" placeholder="Username" required class="w-full px-4 py-2 border rounded focus:outline-none focus:ring-2 focus:ring-indigo-400">
            <input type="password" name="password" placeholder="Password" required class="w-full px-4 py-2 border rounded focus:outline-none focus:ring-2 focus:ring-indigo-400">
            <button type="submit" class="w-full bg-indigo-700 text-white py-2 rounded font-semibold hover:bg-indigo-800 transition">Login</button>
        </form>
        <div class="mt-6 text-center text-gray-500 text-sm">
            &copy; <?= date('Y') ?> Hotel. All rights reserved.
        </div>
    </div>
</body>
</html>
