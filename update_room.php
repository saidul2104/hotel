<?php
header('Content-Type: application/json');
require_once 'database/config.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid input data');
    }
    
    $room_id = $input['room_id'] ?? null;
    $hotel_id = $input['hotel_id'] ?? null;
    $price = $input['price'] ?? null;
    $category = $input['category'] ?? null;
    $description = $input['description'] ?? null;
    
    if (!$room_id || !$hotel_id) {
        throw new Exception('Missing required parameters');
    }
    
    $rooms_table = "rooms_hotel_{$hotel_id}";
    
    $update_fields = [];
    $params = [];
    
    if ($price !== null) {
        $update_fields[] = "price = ?";
        $params[] = $price;
    }
    
    if ($category !== null) {
        $update_fields[] = "category = ?";
        $params[] = $category;
    }
    
    if ($description !== null) {
        $update_fields[] = "description = ?";
        $params[] = $description;
    }
    
    if (empty($update_fields)) {
        throw new Exception('No fields to update');
    }
    
    $params[] = $room_id;
    
    $sql = "UPDATE {$rooms_table} SET " . implode(', ', $update_fields) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    $response = [
        'success' => true,
        'message' => 'Room updated successfully'
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?> 