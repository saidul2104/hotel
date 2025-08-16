<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
require_once 'database/config.php';
require_once 'php/booking_history_functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit();
}

try {
    // Get form data from modal
    $room_id = (int)($_POST['room_id'] ?? 0);
    $rooms = (int)($_POST['rooms'] ?? 1); // Number of rooms being booked
    $checkin_date = $_POST['checkin_date'] ?? '';
    $checkin_time = $_POST['checkin_time'] ?? '';
    $checkout_date = $_POST['checkout_date'] ?? '';
    $checkout_time = $_POST['checkout_time'] ?? '';
    $paid = (float)($_POST['paid'] ?? 0);
    $num_guests = (int)($_POST['num_guests'] ?? 1);
    $guest_name = trim($_POST['guest_name'] ?? '');
    $nid_number = trim($_POST['nid_number'] ?? '');
    $profession = trim($_POST['profession'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $discount = (float)($_POST['discount'] ?? 0);
    $booking_type = $_POST['booking_type'] ?? 'offline';
    $address = trim($_POST['address'] ?? '');
    
    // New booking form fields
    $note = trim($_POST['note'] ?? '');
    $reference = trim($_POST['reference'] ?? '');
    
    // Meal add-ons
    $breakfast_price = (float)($_POST['breakfast_price'] ?? 0);
    $breakfast_quantity = (int)($_POST['breakfast_quantity'] ?? 0);
    $breakfast_total = (float)($_POST['breakfast_total'] ?? 0);
    $lunch_price = (float)($_POST['lunch_price'] ?? 0);
    $lunch_quantity = (int)($_POST['lunch_quantity'] ?? 0);
    $lunch_total = (float)($_POST['lunch_total'] ?? 0);
    $dinner_price = (float)($_POST['dinner_price'] ?? 0);
    $dinner_quantity = (int)($_POST['dinner_quantity'] ?? 0);
    $dinner_total = (float)($_POST['dinner_total'] ?? 0);
    $meal_total = (float)($_POST['meal_total'] ?? 0);

    // Validate required fields
    if (!$room_id || empty($guest_name) || empty($nid_number) || empty($phone) || empty($checkin_date) || empty($checkout_date)) {
        echo json_encode(['success' => false, 'message' => 'Please fill in all required fields.']);
        exit();
    }

    // Validate dates
    $checkin = new DateTime($checkin_date);
    $checkout = new DateTime($checkout_date);
    $today = new DateTime();
    $today->setTime(0, 0, 0);
    if ($checkin < $today) {
        echo json_encode(['success' => false, 'message' => 'Check-in date cannot be in the past.']);
        exit();
    }
    if ($checkout <= $checkin) {
        echo json_encode(['success' => false, 'message' => 'Check-out date must be after check-in date.']);
        exit();
    }

    // Fetch room info from per-hotel table
    $hotel_id = isset($_POST['hotel_id']) ? (int)$_POST['hotel_id'] : 0;
    $rooms_table = "rooms_hotel_{$hotel_id}";
    $bookings_table = "bookings_hotel_{$hotel_id}";
    $stmt = $pdo->prepare("SELECT * FROM `$rooms_table` WHERE id = ?");
    $stmt->execute([$room_id]);
    $room = $stmt->fetch();
    if (!$room) {
        echo json_encode(['success' => false, 'message' => 'Room not found.']);
        exit();
    }

    // Check room availability before booking using enhanced logic
    require_once 'php/room_availability.php';
    $is_available = isRoomAvailableForDates($pdo, $hotel_id, $room_id, $checkin_date, $checkout_date);
    
    if (!$is_available) {
        echo json_encode([
            'success' => false, 
            'message' => 'No Available Room - This room is already booked for the selected dates.',
            'error_type' => 'room_unavailable'
        ]);
        exit();
    }

    // Calculate total amount
    $nights = $checkin->diff($checkout)->days;
    $room_total = $room['price'] * $nights;
    
    // Total Amount = Room Prices + Meal Prices
    $total_amount = $room_total + $meal_total;
    
    // Amount to be Paid = Total Amount - Discount
    $amount_to_be_paid = $total_amount - $discount;
    
    // Due Amount = Amount to be Paid - Paid Amount
    $due = $amount_to_be_paid - $paid;
    if ($due < 0) $due = 0;

    $pdo->beginTransaction();

    // Insert guest
    $stmt = $pdo->prepare('INSERT INTO guests (name, nid, profession, email, phone, no_of_guests, hotel_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$guest_name, $nid_number, $profession, $email, $phone, $num_guests, $hotel_id]);
    $guest_id = $pdo->lastInsertId();

    // Insert booking into per-hotel table - use dynamic column detection
    $stmt = $pdo->query("SHOW COLUMNS FROM `$bookings_table`");
    $all_columns = $stmt->fetchAll();
    $insert_columns = [];
    foreach ($all_columns as $col) {
        if ($col['Field'] !== 'id') {
            $insert_columns[] = $col['Field'];
        }
    }
    
    // Build parameters dynamically based on available columns
    $insert_params = [];
    foreach ($insert_columns as $col) {
        switch ($col) {
            case 'room_id': $insert_params[] = $room_id; break;
            case 'rooms': $insert_params[] = $rooms; break;
            case 'guest_name': $insert_params[] = $guest_name; break;
            case 'guest_contact': $insert_params[] = $phone; break;
            case 'checkin_date': $insert_params[] = $checkin_date; break;
            case 'checkout_date': $insert_params[] = $checkout_date; break;
            case 'checkin_time': $insert_params[] = $checkin_time; break;
            case 'checkout_time': $insert_params[] = $checkout_time; break;
            case 'total_amount': $insert_params[] = $amount_to_be_paid; break;
            case 'discount': $insert_params[] = $discount; break;
            case 'paid': $insert_params[] = $paid; break;
            case 'due': $insert_params[] = $due; break;
            case 'status': $insert_params[] = 'active'; break;
            case 'booking_type': $insert_params[] = $booking_type; break;
            case 'reference': $insert_params[] = $reference; break;
            case 'note': $insert_params[] = $note; break;
            case 'breakfast_price': $insert_params[] = $breakfast_price; break;
            case 'breakfast_quantity': $insert_params[] = $breakfast_quantity; break;
            case 'breakfast_total': $insert_params[] = $breakfast_total; break;
            case 'lunch_price': $insert_params[] = $lunch_price; break;
            case 'lunch_quantity': $insert_params[] = $lunch_quantity; break;
            case 'lunch_total': $insert_params[] = $lunch_total; break;
            case 'dinner_price': $insert_params[] = $dinner_price; break;
            case 'dinner_quantity': $insert_params[] = $dinner_quantity; break;
            case 'dinner_total': $insert_params[] = $dinner_total; break;
            case 'meal_total': $insert_params[] = $meal_total; break;
            case 'breakfast_enabled': $insert_params[] = ($breakfast_quantity > 0 ? 1 : 0); break;
            case 'lunch_enabled': $insert_params[] = ($lunch_quantity > 0 ? 1 : 0); break;
            case 'dinner_enabled': $insert_params[] = $dinner_quantity > 0 ? 1 : 0; break;
            case 'nid_number': $insert_params[] = $nid_number; break;
            case 'profession': $insert_params[] = $profession; break;
            case 'email': $insert_params[] = $email; break;
            case 'address': $insert_params[] = $address; break;
            case 'num_guests': $insert_params[] = $num_guests; break;
            case 'created_at': $insert_params[] = date('Y-m-d H:i:s'); break;
        }
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO `$bookings_table` (
            " . implode(', ', $insert_columns) . "
        ) VALUES (
            " . str_repeat('?,', count($insert_columns) - 1) . "?
        )
    ");
    
    $stmt->execute($insert_params);
    $booking_id = $pdo->lastInsertId();

    // Log the booking creation
    $new_booking_data = [
        'room_id' => $room_id,
        'rooms' => $rooms,
        'guest_name' => $guest_name,
        'guest_contact' => $phone,
        'checkin_date' => $checkin_date,
        'checkout_date' => $checkout_date,
        'checkin_time' => $checkin_time,
        'checkout_time' => $checkout_time,
        'total_amount' => $amount_to_be_paid,
        'discount' => $discount,
        'paid' => $paid,
        'due' => $due,
        'status' => 'active',
        'booking_type' => $booking_type,
        'reference' => $reference,
        'note' => $note,
        'breakfast_price' => $breakfast_price,
        'breakfast_quantity' => $breakfast_quantity,
        'breakfast_total' => $breakfast_total,
        'lunch_price' => $lunch_price,
        'lunch_quantity' => $lunch_quantity,
        'lunch_total' => $lunch_total,
        'dinner_price' => $dinner_price,
        'dinner_quantity' => $dinner_quantity,
        'dinner_total' => $dinner_total,
        'meal_total' => $meal_total,
        'nid_number' => $nid_number,
        'profession' => $profession,
        'email' => $email,
        'address' => $address,
        'num_guests' => $num_guests
    ];
    
    log_booking_change($hotel_id, $booking_id, 'created', 'New booking created', null, $new_booking_data);

    // Update room status in per-hotel table
    $stmt = $pdo->prepare("UPDATE `$rooms_table` SET status = ? WHERE id = ?");
    $room_status = 'booked';
    $stmt->execute([$room_status, $room_id]);

    $pdo->commit();

    // Return booking summary as JSON
    echo json_encode([
        'success' => true,
        'booking_id' => $booking_id,
        'guest_name' => $guest_name,
        'room_number' => $room['room_number'],
        'category' => $room['category'],
        'checkin_date' => $checkin_date,
        'checkin_time' => $checkin_time,
        'checkout_date' => $checkout_date,
        'checkout_time' => $checkout_time,
        'total' => $amount_to_be_paid,
        'paid' => $paid,
        'due' => $due,
        'nights' => $nights,
        'discount' => $discount,
        'email' => $email,
        'phone' => $phone,
        'address' => $address,
        'note' => $note,
        'reference' => $reference,
        'meal_total' => $meal_total,
        'breakfast_total' => $breakfast_total,
        'lunch_total' => $lunch_total,
        'dinner_total' => $dinner_total
    ]);
    exit();

} catch (Exception $e) {
    $pdo->rollback();
    echo json_encode(['success' => false, 'message' => 'Error creating booking: ' . $e->getMessage()]);
    exit();
}

function generatePDF($booking_id, $guest_name, $room, $checkin_date, $checkout_date, $total, $discount) {
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Booking Confirmation</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 40px; }
            .header { text-align: center; margin-bottom: 30px; }
            .logo { font-size: 24px; font-weight: bold; color: #667eea; }
            .booking-details { margin: 20px 0; }
            .row { display: flex; margin: 10px 0; }
            .label { font-weight: bold; width: 150px; }
            .value { flex: 1; }
            .total { font-size: 18px; font-weight: bold; margin-top: 20px; padding-top: 10px; border-top: 2px solid #667eea; }
        </style>
    </head>
    <body>
        <div class="header">
            <div class="logo">Hotel</div>
            <h2>Booking Confirmation</h2>
        </div>
        <div class="booking-details">
            <div class="row">
                <div class="label">Booking ID:</div>
                <div class="value">#' . $booking_id . '</div>
            </div>
            <div class="row">
                <div class="label">Guest Name:</div>
                <div class="value">' . $guest_name . '</div>
            </div>
            <div class="row">
                <div class="label">Room Number:</div>
                <div class="value">' . $room['room_no'] . ' (' . $room['category'] . ')</div>
            </div>
            <div class="row">
                                    <div class="label">Check-in Date:</div>
                    <div class="value">' . format_display_date($checkin_date) . '</div>
                </div>
                <div class="row">
                    <div class="label">Check-out Date:</div>
                    <div class="value">' . format_display_date($checkout_date) . '</div>
            </div>
            <div class="row">
                <div class="label">Room Price:</div>
                <div class="value">৳' . number_format($room['price'], 2) . ' per night</div>
            </div>
            <div class="row">
                <div class="label">Discount:</div>
                <div class="value">' . $discount . '%</div>
            </div>
            <div class="row">
                <div class="label">Total Amount:</div>
                <div class="value">৳' . number_format($total, 2) . '</div>
            </div>
        </div>
        <div style="margin-top: 40px; text-align: center; color: #666;">
            Thank you for choosing Hotel!
        </div>
    </body>
    </html>';
    return $html;
}
?>
