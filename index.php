<?php
session_start();

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['user_role'] === 'admin') {
        header('Location: dashboard.php');
    } else if ($_SESSION['user_role'] === 'manager') {
        header('Location: hotel_dashboard.php');
    }
    exit();
}

// Redirect to login page
header('Location: login.php');
exit();
?>