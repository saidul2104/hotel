<?php
// Set Bangladesh timezone
date_default_timezone_set('Asia/Dhaka');

// Database configuration for Local Environment
$host = 'localhost';
$db   = 'hotel';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

// Try to connect with socket first, fallback to TCP if socket doesn't exist
$socket_path = '/opt/lampp/var/mysql/mysql.sock';
if (file_exists($socket_path)) {
    $dsn = "mysql:host=$host;dbname=$db;charset=$charset;unix_socket=$socket_path";
} else {
    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
}
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Function to get mysqli connection for backward compatibility
function get_db_connection() {
    global $host, $user, $pass, $db;
    $conn = new mysqli($host, $user, $pass, $db);
    
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    return $conn;
}

// Function to get PDO connection
function get_pdo_connection() {
    global $pdo;
    return $pdo;
}

// Helper function for consistent date formatting (dd/mm/yy)
function format_date($date, $format = 'd/m/y') {
    if (is_string($date)) {
        $date = new DateTime($date);
    }
    return $date->format($format);
}

// Helper function for database date format (Y-m-d)
function format_db_date($date) {
    if (is_string($date)) {
        $date = new DateTime($date);
    }
    return $date->format('Y-m-d');
}

// Helper function for display date format (dd/mm/yy)
function format_display_date($date) {
    return format_date($date, 'd/m/y');
}

// Helper function for full date display (dd/mm/yyyy)
function format_full_date($date) {
    return format_date($date, 'd/m/Y');
}
?>
