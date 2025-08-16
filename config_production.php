<?php
// Set Bangladesh timezone
date_default_timezone_set('Asia/Dhaka');

// Database configuration for Production Environment
// UPDATE THESE VALUES WITH YOUR HOSTING PROVIDER'S DATABASE DETAILS
$host = 'localhost'; // Usually 'localhost' for shared hosting
$db   = 'hotelgro_modern'; // Your hosting database name
$user = 'hotelgro_modern'; // Your hosting database username
$pass = 'modern@gmail'; // Your hosting database password
$charset = 'utf8mb4';

// Production DSN (simplified for hosting)
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

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