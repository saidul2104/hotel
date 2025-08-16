<?php
// Test script for hotel deletion functionality
require_once 'database/config.php';

echo "<h1>Hotel Deletion Test</h1>";

// Get all hotels
$hotels = $pdo->query("SELECT id, name FROM hotels ORDER BY id")->fetchAll();

if (empty($hotels)) {
    echo "<p>No hotels found in database.</p>";
    exit;
}

echo "<h2>Available Hotels:</h2>";
echo "<ul>";
foreach ($hotels as $hotel) {
    echo "<li>ID: {$hotel['id']} - {$hotel['name']}</li>";
}
echo "</ul>";

// Test table existence check
echo "<h2>Testing Table Existence Check:</h2>";
foreach ($hotels as $hotel) {
    $hotel_id = $hotel['id'];
    $rooms_table = "rooms_hotel_{$hotel_id}";
    $bookings_table = "bookings_hotel_{$hotel_id}";
    
    echo "<h3>Hotel: {$hotel['name']} (ID: {$hotel_id})</h3>";
    
    // Check rooms table
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE '$rooms_table'");
        if ($stmt->fetch()) {
            echo "<p>✅ Rooms table exists: $rooms_table</p>";
        } else {
            echo "<p>❌ Rooms table not found: $rooms_table</p>";
        }
    } catch (Exception $e) {
        echo "<p>❌ Error checking rooms table: " . $e->getMessage() . "</p>";
    }
    
    // Check bookings table
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE '$bookings_table'");
        if ($stmt->fetch()) {
            echo "<p>✅ Bookings table exists: $bookings_table</p>";
        } else {
            echo "<p>❌ Bookings table not found: $bookings_table</p>";
        }
    } catch (Exception $e) {
        echo "<p>❌ Error checking bookings table: " . $e->getMessage() . "</p>";
    }
    
    echo "<hr>";
}

echo "<h2>Test Complete</h2>";
echo "<p>This script tests the table existence checking functionality used in hotel deletion.</p>";
?> 