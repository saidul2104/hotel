<?php
// Script to check foreign key relationships for hotel deletion
require_once 'database/config.php';

echo "<h1>Foreign Key Relationship Check</h1>";

// Get all hotels
$hotels = $pdo->query("SELECT id, name FROM hotels ORDER BY id")->fetchAll();

if (empty($hotels)) {
    echo "<p>No hotels found in database.</p>";
    exit;
}

foreach ($hotels as $hotel) {
    $hotel_id = $hotel['id'];
    echo "<h2>Hotel: {$hotel['name']} (ID: {$hotel_id})</h2>";
    
    // Check all tables that might reference this hotel
    $tables_to_check = [
        'users' => "SELECT COUNT(*) as count FROM users WHERE hotel_id = $hotel_id",
        'rooms' => "SELECT COUNT(*) as count FROM rooms WHERE hotel_id = $hotel_id",
        'bookings' => "SELECT COUNT(*) as count FROM bookings b INNER JOIN rooms r ON b.room_id = r.id WHERE r.hotel_id = $hotel_id",
        'room_categories' => "SELECT COUNT(*) as count FROM room_categories WHERE hotel_id = $hotel_id",
        'revenue' => "SELECT COUNT(*) as count FROM revenue WHERE hotel_id = $hotel_id",
        'hotel_managers' => "SELECT COUNT(*) as count FROM hotel_managers WHERE hotel_id = $hotel_id"
    ];
    
    echo "<ul>";
    foreach ($tables_to_check as $table_name => $query) {
        try {
            $stmt = $pdo->query($query);
            $result = $stmt->fetch();
            $count = $result['count'];
            
            if ($count > 0) {
                echo "<li style='color: red;'>❌ $table_name: $count records</li>";
            } else {
                echo "<li style='color: green;'>✅ $table_name: 0 records</li>";
            }
        } catch (Exception $e) {
            echo "<li style='color: orange;'>⚠️ $table_name: Error - " . $e->getMessage() . "</li>";
        }
    }
    echo "</ul>";
    
    // Check dynamic tables
    $rooms_table = "rooms_hotel_{$hotel_id}";
    $bookings_table = "bookings_hotel_{$hotel_id}";
    
    echo "<h3>Dynamic Tables:</h3>";
    echo "<ul>";
    
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE '$rooms_table'");
        if ($stmt->fetch()) {
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM `$rooms_table`");
            $result = $stmt->fetch();
            echo "<li>✅ $rooms_table: " . $result['count'] . " records</li>";
        } else {
            echo "<li>❌ $rooms_table: Table does not exist</li>";
        }
    } catch (Exception $e) {
        echo "<li>⚠️ $rooms_table: Error - " . $e->getMessage() . "</li>";
    }
    
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE '$bookings_table'");
        if ($stmt->fetch()) {
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM `$bookings_table`");
            $result = $stmt->fetch();
            echo "<li>✅ $bookings_table: " . $result['count'] . " records</li>";
        } else {
            echo "<li>❌ $bookings_table: Table does not exist</li>";
        }
    } catch (Exception $e) {
        echo "<li>⚠️ $bookings_table: Error - " . $e->getMessage() . "</li>";
    }
    
    echo "</ul>";
    echo "<hr>";
}

echo "<h2>Foreign Key Constraints Summary</h2>";
echo "<p>Based on the setup_database.php file, these foreign key constraints exist:</p>";
echo "<ul>";
echo "<li>users.hotel_id → hotels.id (ON DELETE SET NULL)</li>";
echo "<li>rooms.hotel_id → hotels.id (ON DELETE CASCADE)</li>";
echo "<li>bookings.room_id → rooms.id (ON DELETE CASCADE)</li>";
echo "<li>revenue.hotel_id → hotels.id (ON DELETE CASCADE)</li>";
echo "<li>hotel_managers.hotel_id → hotels.id (ON DELETE CASCADE)</li>";
echo "<li>room_categories.hotel_id → hotels.id (ON DELETE CASCADE)</li>";
echo "</ul>";

echo "<h2>Deletion Order</h2>";
echo "<p>The correct order to delete hotel data:</p>";
echo "<ol>";
echo "<li>Delete bookings that reference rooms in this hotel</li>";
echo "<li>Delete rooms for this hotel</li>";
echo "<li>Delete room categories for this hotel</li>";
echo "<li>Delete revenue records for this hotel</li>";
echo "<li>Delete hotel managers for this hotel</li>";
echo "<li>Update users to remove hotel assignment</li>";
echo "<li>Drop dynamic tables (rooms_hotel_X, bookings_hotel_X)</li>";
echo "<li>Finally delete the hotel</li>";
echo "</ol>";
?> 