<?php
header('Content-Type: application/json');
require_once 'database/config.php';
require_once 'php/room_availability.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid input data');
    }
    
    $room_id = $input['room_id'] ?? null;
    $checkin_date = $input['checkin_date'] ?? null;
    $checkout_date = $input['checkout_date'] ?? null;
    $hotel_id = $input['hotel_id'] ?? null;
    
    if (!$room_id || !$checkin_date || !$checkout_date || !$hotel_id) {
        throw new Exception('Missing required parameters');
    }
    
    // Use the enhanced availability function
    $is_available = isRoomAvailableForDates($pdo, $hotel_id, $room_id, $checkin_date, $checkout_date);
    
    // Get conflicting bookings for detailed information
    $bookings_table = "bookings_hotel_{$hotel_id}";
    $stmt = $pdo->prepare("
        SELECT id, guest_name, checkin_date, checkout_date, checkout_time
        FROM {$bookings_table} 
        WHERE room_id = ? 
        AND status IN ('active', 'checked_in') 
        AND (
            -- Standard overlap check: existing booking overlaps with requested period
            (checkin_date < ? AND checkout_date > ?) OR
            -- Special case: if checkout date is same as checkin date, check checkout time
            (checkout_date = ? AND checkout_time > '11:00:00' AND ? = ?) OR
            -- Special case: if checkin date is same as existing checkout date, check checkout time
            (checkout_date = ? AND checkout_time > '11:00:00' AND ? = ?)
        )
    ");
    
    $stmt->execute([
        $room_id,
        $checkout_date, $checkin_date,  // Standard overlap
        $checkin_date, $checkin_date, $checkout_date,  // Same day checkout/checkin
        $checkin_date, $checkin_date, $checkout_date   // Same day checkout/checkin reverse
    ]);
    $conflicts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $response = [
        'success' => true,
        'available' => $is_available,
        'conflicts' => $conflicts,
        'checkout_time' => '11:00:00',
        'message' => $is_available ? 'Room is available for the selected dates' : 'Room is not available for the selected dates'
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?> 