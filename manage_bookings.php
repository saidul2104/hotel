<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

// Add cache-busting headers
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Force refresh if requested
if (isset($_GET['force_refresh'])) {
    // Clear any session cache
    session_write_close();
    session_start();
}

require_once 'database/config.php';
require_once 'php/booking_history_functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Get hotel_id from URL (admin) or from manager's user record
if ($_SESSION['user_role'] === 'admin') {
    $hotel_id = isset($_GET['hotel_id']) ? intval($_GET['hotel_id']) : 0;
    if (!$hotel_id) {
        header('Location: dashboard.php');
        exit();
    }
} else if ($_SESSION['user_role'] === 'manager') {
    $hotel_id = $_SESSION['hotel_id'] ?? 0;
    if (!$hotel_id) {
        echo '<h2 class="text-red-600">No hotel assigned. Contact admin.</h2>';
        exit();
    }
} else {
    header('Location: login.php');
    exit();
}

// Build the per-hotel table names
$bookings_table = "bookings_hotel_{$hotel_id}";
$rooms_table = "rooms_hotel_{$hotel_id}";

// Default sorting (no longer used in UI but kept for backward compatibility)
$sort_field = 'checkin_date';
$sort_dir = 'asc';

// Handle edit, delete actions
$message = '';

// Check for success message from URL
if (isset($_GET['message'])) {
    if ($_GET['message'] === 'updated') {
        $message = 'Booking updated successfully! All details including meal add-ons have been saved.';
    } elseif ($_GET['message'] === 'deleted') {
        $message = 'Booking deleted successfully!';
    } elseif ($_GET['message'] === 'error') {
        $message = 'An error occurred. Please try again.';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['edit_booking'])) {
        // Debug: Log the POST data
        error_log("Edit booking POST data: " . print_r($_POST, true));
        
        $booking_id = $_POST['booking_id'];
        $guest_name = $_POST['guest_name'];
        $phone = $_POST['phone'];
        $status = $_POST['status'];
        $paid = isset($_POST['paid']) ? floatval($_POST['paid']) : 0;
        $discount = isset($_POST['discount']) ? floatval($_POST['discount']) : 0;
        $profession = $_POST['profession'] ?? '';
        $email = $_POST['email'] ?? '';
        $num_guests = $_POST['num_guests'] ?? 1;
        $checkout_date = $_POST['checkout_date'] ?? '';
        $booking_type = $_POST['booking_type'] ?? 'offline';
        $address = $_POST['address'] ?? '';
        $checkin_date = $_POST['checkin_date'] ?? '';
        $checkin_time = $_POST['checkin_time'] ?? '';
        $checkout_time = $_POST['checkout_time'] ?? '';
        $nid_number = $_POST['nid_number'] ?? '';
        $reference = $_POST['reference'] ?? '';
        $guest_notes = $_POST['note'] ?? '';
        
        // Preserve room information for multiple bookings while updating guest notes
        $stmt = $pdo->prepare("SELECT note FROM `$bookings_table` WHERE id=?");
        $stmt->execute([$booking_id]);
        $current_note = $stmt->fetchColumn();
        
        if (strpos($current_note, 'Multiple booking - Rooms:') !== false) {
            // This is a multiple booking - preserve room info and update guest notes
            $room_info = '';
            if (preg_match('/Multiple booking - Rooms:\s*([^|]+)/', $current_note, $matches)) {
                $room_info = 'Multiple booking - Rooms: ' . trim($matches[1]);
            }
            
            if (!empty($guest_notes)) {
                $note = $room_info . ' | Guest Notes: ' . $guest_notes;
            } else {
                $note = $room_info;
            }
        } else {
            // Single booking - use guest notes as is
            $note = $guest_notes;
        }
        
        // Meal add-ons
        $breakfast_price = $_POST['breakfast_price'] ?? 0;
        $breakfast_quantity = $_POST['breakfast_quantity'] ?? 0;
        $breakfast_total = $_POST['breakfast_total'] ?? 0;
        $lunch_price = $_POST['lunch_price'] ?? 0;
        $lunch_quantity = $_POST['lunch_quantity'] ?? 0;
        $lunch_total = $_POST['lunch_total'] ?? 0;
        $dinner_price = $_POST['dinner_price'] ?? 0;
        $dinner_quantity = $_POST['dinner_quantity'] ?? 0;
        $dinner_total = $_POST['dinner_total'] ?? 0;
        $meal_total = $_POST['meal_total'] ?? 0;
        
        // Fetch current booking details for recalculation
        $stmt = $pdo->prepare("SELECT total_amount, room_id, note FROM `$bookings_table` WHERE id=?");
        $stmt->execute([$booking_id]);
        $current_booking = $stmt->fetch();
        
        // Calculate room total based on booking type (single or multiple)
        $room_total = 0;
        if ($checkin_date && $checkout_date) {
            $checkin = new DateTime($checkin_date);
            $checkout = new DateTime($checkout_date);
            $nights = $checkin->diff($checkout)->days;
            
            if ($nights > 0) {
                // Check if this is a multiple room booking
                if (strpos($current_note, 'Multiple booking - Rooms:') !== false) {
                    // Multiple room booking - calculate total for all rooms
                    if (preg_match('/Multiple booking - Rooms:\s*([^|]+)/', $current_note, $matches)) {
                        $room_numbers = array_map('trim', explode(',', $matches[1]));
                        
                        foreach ($room_numbers as $room_number) {
                            $stmt = $pdo->prepare("SELECT price FROM `$rooms_table` WHERE room_number = ?");
                            $stmt->execute([$room_number]);
                            $room = $stmt->fetch();
                            if ($room) {
                                $room_total += $room['price'] * $nights;
                            }
                        }
                    }
                } else {
                    // Single room booking - calculate for one room
                    $stmt = $pdo->prepare("SELECT price FROM `$rooms_table` WHERE id=?");
                    $stmt->execute([$current_booking['room_id']]);
                    $room = $stmt->fetch();
                    $room_price = $room['price'] ?? 0;
                    $room_total = $room_price * $nights;
                }
            }
        }
        
        // Total Amount = Room Prices + Meal Prices
        $total_amount = $room_total + $meal_total;
        
        // Amount to be Paid = Total Amount - Discount
        $amount_to_be_paid = $total_amount - $discount;
        
        // Due Amount = Amount to be Paid - Paid Amount
        $due = max(0, $amount_to_be_paid - $paid);
        
        // Get current booking data for logging
        $stmt = $pdo->prepare("SELECT * FROM `$bookings_table` WHERE id = ?");
        $stmt->execute([$booking_id]);
        $old_booking_data = $stmt->fetch();
        
        // Prepare new booking data for logging
        $new_booking_data = [
            'guest_name' => $guest_name,
            'guest_contact' => $phone,
            'status' => $status,
            'paid' => $paid,
            'discount' => $discount,
            'due' => $due,
            'total_amount' => $amount_to_be_paid,
            'profession' => $profession,
            'email' => $email,
            'num_guests' => $num_guests,
            'checkout_date' => $checkout_date,
            'booking_type' => $booking_type,
            'address' => $address,
            'checkin_date' => $checkin_date,
            'checkin_time' => $checkin_time,
            'checkout_time' => $checkout_time,
            'note' => $note,
            'reference' => $reference,
            'breakfast_price' => $breakfast_price,
            'breakfast_quantity' => $breakfast_quantity,
            'breakfast_total' => $breakfast_total,
            'lunch_price' => $lunch_price,
            'lunch_quantity' => $lunch_quantity,
            'lunch_total' => $lunch_total,
            'dinner_price' => $dinner_price,
            'dinner_quantity' => $dinner_quantity,
            'dinner_total' => $dinner_total,
            'meal_total' => $meal_total
        ];
        
        // Log the booking update
        log_booking_change($hotel_id, $booking_id, 'updated', 'Booking details updated', $old_booking_data, $new_booking_data);
        
        // Start output buffering to prevent header issues
        ob_start();
        
        // Update booking with all details including meal add-ons and recalculated total
        $stmt = $pdo->prepare("UPDATE `$bookings_table` SET guest_name=?, guest_contact=?, status=?, paid=?, discount=?, due=?, total_amount=?, profession=?, email=?, num_guests=?, checkout_date=?, booking_type=?, address=?, checkin_date=?, checkin_time=?, checkout_time=?, note=?, reference=?, breakfast_price=?, breakfast_quantity=?, breakfast_total=?, lunch_price=?, lunch_quantity=?, lunch_total=?, dinner_price=?, dinner_quantity=?, dinner_total=?, meal_total=? WHERE id=?");
        $stmt->execute([$guest_name, $phone, $status, $paid, $discount, $due, $amount_to_be_paid, $profession, $email, $num_guests, $checkout_date, $booking_type, $address, $checkin_date, $checkin_time, $checkout_time, $note, $reference, $breakfast_price, $breakfast_quantity, $breakfast_total, $lunch_price, $lunch_quantity, $lunch_total, $dinner_price, $dinner_quantity, $dinner_total, $meal_total, $booking_id]);
        
        // Update or insert guest information
        $stmt = $pdo->prepare("INSERT INTO guests (name, phone, nid, profession, email, address, no_of_guests, hotel_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE profession=?, email=?, address=?, no_of_guests=?");
        $stmt->execute([$guest_name, $phone, $nid_number, $profession, $email, $address, $num_guests, $hotel_id, $profession, $email, $address, $num_guests]);
        
        // Maintain room status data integrity after booking update
        require_once 'php/room_status_functions.php';
        maintainRoomStatus($hotel_id);
        
        // Clear any output buffer
        ob_end_clean();
        
        $message = 'Booking updated successfully! All details including meal add-ons have been saved.';
        // Redirect to prevent form resubmission and duplication
        header("Location: manage_bookings.php?hotel_id=$hotel_id&message=updated");
        exit();
    } elseif (isset($_POST['delete_booking'])) {
        $booking_id = $_POST['booking_id'];
        
        // Get booking data before deletion for logging
        $stmt = $pdo->prepare("SELECT * FROM `$bookings_table` WHERE id = ?");
        $stmt->execute([$booking_id]);
        $deleted_booking_data = $stmt->fetch();
        
        // Log the booking deletion
        if ($deleted_booking_data) {
            log_booking_change($hotel_id, $booking_id, 'deleted', 'Booking deleted', $deleted_booking_data, null);
        }
        
        // Start output buffering to prevent header issues
        ob_start();
        
        // Simple deletion for individual bookings
        $stmt = $pdo->prepare("DELETE FROM `$bookings_table` WHERE id = ?");
        $stmt->execute([$booking_id]);
        
        // Maintain room status data integrity after booking deletion
        require_once 'php/room_status_functions.php';
        maintainRoomStatus($hotel_id);
        
        // Clear any output buffer
        ob_end_clean();
        
        $message = 'Booking deleted successfully!';
        // Redirect to prevent form resubmission
        header("Location: manage_bookings.php?hotel_id=$hotel_id&message=deleted");
        exit();
    } elseif (isset($_POST['delete_multiple_booking'])) {
        $booking_ids = $_POST['booking_ids'] ?? [];
        if (!empty($booking_ids)) {
            // Get booking data before deletion for logging
            $placeholders = str_repeat('?,', count($booking_ids) - 1) . '?';
            $stmt = $pdo->prepare("SELECT * FROM `$bookings_table` WHERE id IN ($placeholders)");
            $stmt->execute($booking_ids);
            $deleted_bookings_data = $stmt->fetchAll();
            
            // Log each booking deletion
            foreach ($deleted_bookings_data as $booking_data) {
                log_booking_change($hotel_id, $booking_data['id'], 'deleted', 'Booking deleted (multiple deletion)', $booking_data, null);
            }
            
            // Start output buffering to prevent header issues
            ob_start();
            
            $placeholders = str_repeat('?,', count($booking_ids) - 1) . '?';
            $stmt = $pdo->prepare("DELETE FROM `$bookings_table` WHERE id IN ($placeholders)");
            $stmt->execute($booking_ids);
            
            // Maintain room status data integrity after multiple booking deletion
            require_once 'php/room_status_functions.php';
            maintainRoomStatus($hotel_id);
            
            // Clear any output buffer
            ob_end_clean();
            
            $message = 'Multiple bookings deleted successfully!';
            // Redirect to prevent form resubmission
            header("Location: manage_bookings.php?hotel_id=$hotel_id&message=deleted");
            exit();
        }
    }
}
// Fetch all bookings for this hotel
$stmt = $pdo->prepare("
    SELECT 
        b.*, 
        r.room_number, 
        r.price as room_price,
        1 as rooms_count
    FROM `$bookings_table` b 
    JOIN `$rooms_table` r ON b.room_id = r.id 
    ORDER BY b.id DESC
");
$stmt->execute();
$bookings = $stmt->fetchAll();

// Process bookings to add display fields
foreach ($bookings as &$booking) {
    // Ensure all required fields exist
    if (!isset($booking['id']) || $booking['id'] === null) {
        $booking['id'] = 'N/A';
    }
    
    // Set rooms count (default to 1 for single room bookings)
    $booking['rooms_count'] = 1;
    
    // Set room numbers - for now just the single room, will be enhanced for multiple rooms
    $booking['all_room_numbers'] = $booking['room_number'];
    
    // Add missing meal enabled fields
    $booking['breakfast_enabled'] = ($booking['breakfast_quantity'] ?? 0) > 0 ? 1 : 0;
    $booking['lunch_enabled'] = ($booking['lunch_quantity'] ?? 0) > 0 ? 1 : 0;
    $booking['dinner_enabled'] = ($booking['dinner_quantity'] ?? 0) > 0 ? 1 : 0;
}

// Old date filtering logic removed - now handled by enhanced search and filter form

// Fetch hotel info
$stmt = $pdo->prepare("SELECT * FROM hotels WHERE id = ?");
$stmt->execute([$hotel_id]);
$hotel = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Manage Bookings - Admin Panel</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-gray-50">
    <div class="flex min-h-screen">
        <!-- Sidebar -->
        <div class="w-64 h-screen fixed top-0 left-0 pt-0 shadow-lg bg-gradient-to-b from-indigo-500 to-purple-600 text-white z-30 sidebar">
            <div class="flex items-center justify-center h-16 p-4">
                <?php if (!empty($hotel['logo'])): ?>
                    <img src="<?php echo htmlspecialchars($hotel['logo']); ?>" alt="<?php echo htmlspecialchars($hotel['name']); ?> Logo" class="h-10 w-auto max-w-16 object-contain">
                <?php else: ?>
                    <i class="fas fa-hotel text-2xl"></i>
                <?php endif; ?>
                <span class="ml-3 text-xl font-bold"><?php echo htmlspecialchars($hotel['name']); ?></span>
            </div>
            <nav class="mt-8">
                <a href="hotel_dashboard.php?hotel_id=<?php echo $hotel_id; ?>" class="block px-6 py-3 hover:bg-white hover:bg-opacity-20 transition-colors"><i class="fas fa-tachometer-alt mr-3"></i>Dashboard</a>
                
                 <a href="calendar.php?hotel_id=<?php echo $hotel_id; ?>" class="block px-6 py-3 hover:bg-white hover:bg-opacity-20 transition-colors"><i class="fas fa-calendar-alt mr-3"></i>Calendar</a>
                 
                 
                <?php if ($_SESSION['user_role'] === 'admin'): ?>
                <a href="manage_rooms.php?hotel_id=<?php echo $hotel_id; ?>" class="block px-6 py-3 hover:bg-white hover:bg-opacity-20 transition-colors"><i class="fas fa-bed mr-3"></i>Manage Rooms</a>
                <?php endif; ?>
                <a href="manage_bookings.php?hotel_id=<?php echo $hotel_id; ?>" class="block px-6 py-3 bg-white bg-opacity-20 border-r-4 border-white"><i class="fas fa-list mr-3"></i>Manage Bookings</a>
                <a href="pricing.php?hotel_id=<?php echo $hotel_id; ?>" class="block px-6 py-3 hover:bg-white hover:bg-opacity-20 transition-colors"><i class="fas fa-tags mr-3"></i>Pricing</a>
               
               
               
                <a href="logout.php" class="block px-6 py-3 mt-8 hover:bg-white hover:bg-opacity-20 transition-colors"><i class="fas fa-sign-out-alt mr-3"></i>Logout</a>
            </nav>
        </div>
        <!-- Main Content -->
        <div class="flex-1 p-8 ml-64 flex flex-col h-screen">
            <!-- Fixed Header Section -->
            <div class="flex-shrink-0">
                <h1 class="text-3xl font-bold mb-6 text-gray-800">Manage Bookings</h1>
                <div class="mb-6">
                    <a href="calendar.php?hotel_id=<?php echo $hotel_id; ?>&t=<?php echo time(); ?>" class="inline-block bg-green-600 hover:bg-green-700 text-white font-semibold px-6 py-2 rounded-lg shadow transition duration-200">
                        <i class="fas fa-calendar-plus mr-2"></i>Quick Booking
                    </a>
                    <a href="manage_bookings.php?hotel_id=<?php echo $hotel_id; ?>&force_refresh=1&t=<?php echo time(); ?>" class="inline-block bg-blue-600 hover:bg-blue-700 text-white font-semibold px-6 py-2 rounded-lg shadow transition duration-200 ml-4">
                        <i class="fas fa-sync-alt mr-2"></i>Refresh Data
                    </a>
                    <a href="booking_history.php?hotel_id=<?php echo $hotel_id; ?>" class="inline-block bg-purple-600 hover:bg-purple-700 text-white font-semibold px-6 py-2 rounded-lg shadow transition duration-200 ml-4">
                        <i class="fas fa-history mr-2"></i>Booking History
                    </a>
                </div>
                
                <!-- Debug Information -->
                <?php if (isset($_GET['debug'])): ?>
                <div class="mb-4 bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded">
                    <h4 class="font-semibold">Debug Information:</h4>
                    <p>Total bookings: <?php echo count($bookings); ?></p>
                    <p>Sample booking ID: <?php echo $bookings[0]['id'] ?? 'N/A'; ?></p>
                    <p>Sample room number: <?php echo $bookings[0]['room_number'] ?? 'N/A'; ?></p>
                    <p>Timestamp: <?php echo date('Y-m-d H:i:s'); ?></p>
                </div>
                <?php endif; ?>
                
                <!-- Booking Search and Filter Bar -->
                <div class="mb-6 bg-white p-4 rounded-lg shadow">
                <form method="post" action="" class="space-y-4">
                    <div class="flex flex-col md:flex-row items-center gap-4">
                <input type="hidden" name="hotel_id" value="<?php echo $hotel_id; ?>">
                        <input type="text" name="booking_search" placeholder="Dynamic search: Phone, NID, Room, Name, Reference (partial match)" value="<?php echo isset($_POST['booking_search']) ? htmlspecialchars($_POST['booking_search']) : ''; ?>" class="border border-gray-300 rounded px-4 py-2 w-full md:w-64 focus:outline-none focus:ring-2 focus:ring-green-500">
                        
                        <!-- Filter by Booking Type -->
                        <select name="booking_type_filter" class="border border-gray-300 rounded px-4 py-2 focus:outline-none focus:ring-2 focus:ring-green-500">
                            <option value="">All Booking Types</option>
                            <option value="online" <?php echo (isset($_POST['booking_type_filter']) && $_POST['booking_type_filter'] === 'online') ? 'selected' : ''; ?>>Online</option>
                            <option value="offline" <?php echo (isset($_POST['booking_type_filter']) && $_POST['booking_type_filter'] === 'offline') ? 'selected' : ''; ?>>Offline</option>
                        </select>
                        
                        <!-- Filter by Status -->
                        <select name="status_filter" class="border border-gray-300 rounded px-4 py-2 focus:outline-none focus:ring-2 focus:ring-green-500">
                            <option value="">All Status</option>
                            <option value="active" <?php echo (isset($_POST['status_filter']) && $_POST['status_filter'] === 'active') ? 'selected' : ''; ?>>Active</option>
                            <option value="cancelled" <?php echo (isset($_POST['status_filter']) && $_POST['status_filter'] === 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                        
                <button type="submit" class="bg-green-600 hover:bg-green-700 text-white font-semibold px-6 py-2 rounded-lg shadow transition duration-200">
                            <i class="fas fa-search mr-2"></i>Search & Filter
                        </button>
                        
                        <button type="button" onclick="clearFilters()" class="bg-gray-500 hover:bg-gray-600 text-white font-semibold px-6 py-2 rounded-lg shadow transition duration-200">
                            <i class="fas fa-times mr-2"></i>Clear
                </button>
                    </div>
                    
                    <!-- Date Range Filter -->
                    <div class="flex flex-col md:flex-row items-center gap-4 border-t pt-4">
                        <label class="font-medium text-gray-700 flex items-center">
                            <i class="fas fa-calendar-alt mr-2"></i>Date Range:
                        </label>
                        <div class="flex items-center gap-2">
                            <label class="text-sm text-gray-600">From:</label>
                            <input type="date" name="date_from" value="<?php echo isset($_POST['date_from']) ? htmlspecialchars($_POST['date_from']) : ''; ?>" class="border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-green-500">
                        </div>
                        <div class="flex items-center gap-2">
                            <label class="text-sm text-gray-600">To:</label>
                            <input type="date" name="date_to" value="<?php echo isset($_POST['date_to']) ? htmlspecialchars($_POST['date_to']) : ''; ?>" class="border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-green-500">
                        </div>
                        <div class="flex items-center gap-2">
                            <label class="text-sm text-gray-600">Filter by:</label>
                            <select name="date_filter_type" class="border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-green-500">
                                <option value="checkin" <?php echo (isset($_POST['date_filter_type']) && $_POST['date_filter_type'] === 'checkin') ? 'selected' : ''; ?>>Check-in Date</option>
                                <option value="checkout" <?php echo (isset($_POST['date_filter_type']) && $_POST['date_filter_type'] === 'checkout') ? 'selected' : ''; ?>>Check-out Date</option>
                                <option value="created" <?php echo (isset($_POST['date_filter_type']) && $_POST['date_filter_type'] === 'created') ? 'selected' : ''; ?>>Booking Date</option>
                            </select>
                        </div>
                    </div>
            </form>
            </div>
            </div>
            <!-- Scrollable Content Section -->
            <div class="flex-1 overflow-auto">
            <?php
            // Enhanced filtering logic
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $search = isset($_POST['booking_search']) ? trim($_POST['booking_search']) : '';
                $booking_type_filter = isset($_POST['booking_type_filter']) ? trim($_POST['booking_type_filter']) : '';
                $status_filter = isset($_POST['status_filter']) ? trim($_POST['status_filter']) : '';
                $date_from = isset($_POST['date_from']) ? trim($_POST['date_from']) : '';
                $date_to = isset($_POST['date_to']) ? trim($_POST['date_to']) : '';
                $date_filter_type = isset($_POST['date_filter_type']) ? trim($_POST['date_filter_type']) : 'checkin';
                
                $where_conditions = [];
                $params = [];
                
                // Search condition - Dynamic search by Phone, NID, Room Number, or Guest Name (partial matching)
                if (!empty($search)) {
                    $where_conditions[] = "(b.guest_contact LIKE ? OR b.nid_number LIKE ? OR r.room_number LIKE ? OR b.guest_name LIKE ? OR b.reference LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                
                // Booking type filter
                if (!empty($booking_type_filter)) {
                    $where_conditions[] = "b.booking_type = ?";
                    $params[] = $booking_type_filter;
                }
                
                // Status filter
                if (!empty($status_filter)) {
                    $where_conditions[] = "b.status = ?";
                    $params[] = $status_filter;
                }
                
                // Date range filter
                if (!empty($date_from) && !empty($date_to)) {
                    switch ($date_filter_type) {
                        case 'checkin':
                            $where_conditions[] = "b.checkin_date BETWEEN ? AND ?";
                            $params[] = $date_from;
                            $params[] = $date_to;
                            break;
                        case 'checkout':
                            $where_conditions[] = "b.checkout_date BETWEEN ? AND ?";
                            $params[] = $date_from;
                            $params[] = $date_to;
                            break;
                        case 'created':
                            $where_conditions[] = "DATE(b.created_at) BETWEEN ? AND ?";
                            $params[] = $date_from;
                            $params[] = $date_to;
                            break;
                    }
                } elseif (!empty($date_from)) {
                    // Only from date provided
                    switch ($date_filter_type) {
                        case 'checkin':
                            $where_conditions[] = "b.checkin_date >= ?";
                            $params[] = $date_from;
                            break;
                        case 'checkout':
                            $where_conditions[] = "b.checkout_date >= ?";
                            $params[] = $date_from;
                            break;
                        case 'created':
                            $where_conditions[] = "DATE(b.created_at) >= ?";
                            $params[] = $date_from;
                            break;
                    }
                } elseif (!empty($date_to)) {
                    // Only to date provided
                    switch ($date_filter_type) {
                        case 'checkin':
                            $where_conditions[] = "b.checkin_date <= ?";
                            $params[] = $date_to;
                            break;
                        case 'checkout':
                            $where_conditions[] = "b.checkout_date <= ?";
                            $params[] = $date_to;
                            break;
                        case 'created':
                            $where_conditions[] = "DATE(b.created_at) <= ?";
                            $params[] = $date_to;
                            break;
                    }
                }
                
                $where_clause = '';
                if (!empty($where_conditions)) {
                    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
                }
                
                // First, get all bookings with room information - ensure unique bookings
                $sql = "SELECT DISTINCT b.*, r.room_number, r.price as room_price 
                        FROM `$bookings_table` b JOIN `$rooms_table` r ON b.room_id = r.id $where_clause 
                        GROUP BY b.id ORDER BY b.id DESC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $all_bookings = $stmt->fetchAll();
                
                // Process multiple room bookings to add calculated totals
                $processed_bookings = [];
                foreach ($all_bookings as $booking) {
                    $note = $booking['note'] ?? '';
                    
                    // Check if this is a multiple room booking
                    if (preg_match('/Multiple booking - Rooms:\s*([^|]+)/', $note, $matches)) {
                        $room_numbers = array_map('trim', explode(',', $matches[1]));
                        $calculated_room_total = 0;
                        
                        // Calculate total for all rooms in the multiple booking
                        foreach ($room_numbers as $room_number) {
                            $stmt = $pdo->prepare("SELECT price FROM `$rooms_table` WHERE room_number = ?");
                            $stmt->execute([$room_number]);
                            $room = $stmt->fetch();
                            if ($room) {
                                $calculated_room_total += $room['price'];
                            }
                        }
                        
                        // Calculate nights
                        $checkin = new DateTime($booking['checkin_date']);
                        $checkout = new DateTime($booking['checkout_date']);
                        $nights = $checkin->diff($checkout)->days;
                        
                        // Calculate total room cost for the stay
                        $total_room_cost = $calculated_room_total * $nights;
                        
                        // Add calculated values to booking data
                        $booking['calculated_room_total'] = $total_room_cost;
                        $booking['calculated_room_price_per_night'] = $calculated_room_total;
                        $booking['actual_room_count'] = count($room_numbers);
                        $booking['room_numbers_from_note'] = $room_numbers;
                    } else {
                        // Single room booking
                        $booking['calculated_room_total'] = $booking['room_total'] ?? 0;
                        $booking['calculated_room_price_per_night'] = $booking['room_price'] ?? 0;
                        $booking['actual_room_count'] = 1;
                        $booking['room_numbers_from_note'] = [$booking['room_number']];
                    }
                    
                    $processed_bookings[] = $booking;
                }
                
                // Use processed bookings directly - no grouping needed for multiple room bookings
                // since they are already single records with all room information in note field
                $bookings = $processed_bookings;
            } else {
                // Default query when no filters are applied - ensure unique bookings
                $sql = "SELECT DISTINCT b.*, r.room_number, r.price as room_price 
                        FROM `$bookings_table` b JOIN `$rooms_table` r ON b.room_id = r.id 
                        GROUP BY b.id ORDER BY b.id DESC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute();
                $all_bookings = $stmt->fetchAll();
                
                // Process multiple room bookings to add calculated totals
                $processed_bookings = [];
                foreach ($all_bookings as $booking) {
                    $note = $booking['note'] ?? '';
                    
                    // Check if this is a multiple room booking
                    if (preg_match('/Multiple booking - Rooms:\s*([^|]+)/', $note, $matches)) {
                        $room_numbers = array_map('trim', explode(',', $matches[1]));
                        $calculated_room_total = 0;
                        
                        // Calculate total for all rooms in the multiple booking
                        foreach ($room_numbers as $room_number) {
                            $stmt = $pdo->prepare("SELECT price FROM `$rooms_table` WHERE room_number = ?");
                            $stmt->execute([$room_number]);
                            $room = $stmt->fetch();
                            if ($room) {
                                $calculated_room_total += $room['price'];
                            }
                        }
                        
                        // Calculate nights
                        $checkin = new DateTime($booking['checkin_date']);
                        $checkout = new DateTime($booking['checkout_date']);
                        $nights = $checkin->diff($checkout)->days;
                        
                        // Calculate total room cost for the stay
                        $total_room_cost = $calculated_room_total * $nights;
                        
                        // Add calculated values to booking data
                        $booking['calculated_room_total'] = $total_room_cost;
                        $booking['calculated_room_price_per_night'] = $calculated_room_total;
                        $booking['actual_room_count'] = count($room_numbers);
                        $booking['room_numbers_from_note'] = $room_numbers;
                    } else {
                        // Single room booking
                        $booking['calculated_room_total'] = $booking['room_total'] ?? 0;
                        $booking['calculated_room_price_per_night'] = $booking['room_price'] ?? 0;
                        $booking['actual_room_count'] = 1;
                        $booking['room_numbers_from_note'] = [$booking['room_number']];
                    }
                    
                    $processed_bookings[] = $booking;
                }
                
                // Use processed bookings directly - no grouping needed for multiple room bookings
                // since they are already single records with all room information in note field
                $bookings = $processed_bookings;
            }
            ?>
            <?php if ($message): ?>
                <div class="mb-4 p-4 bg-green-100 text-green-800 rounded-lg"> <?php echo $message; ?> </div>
            <?php endif; ?>
            <!-- Download Guest History by Date Range -->
            <div class="mb-6 bg-white p-4 rounded-lg shadow">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">
                    <i class="fas fa-download mr-2"></i>Download Guest History Report
                </h3>
                <form method="get" action="php/download_guest_history.php" class="flex flex-col md:flex-row items-center gap-4" onsubmit="return validateDateRange()">
                    <input type="hidden" name="hotel_id" value="<?php echo $hotel_id; ?>">
                    
                    <div class="flex items-center gap-2">
                        <label class="font-medium text-gray-700">From:</label>
                        <input type="date" name="start_date" id="start_date" required 
                               class="border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    </div>
                    
                    <div class="flex items-center gap-2">
                        <label class="font-medium text-gray-700">To:</label>
                        <input type="date" name="end_date" id="end_date" required 
                               class="border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    </div>
                    
                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-6 py-2 rounded-lg shadow transition duration-200">
                        <i class="fas fa-download mr-2"></i>Download PDF Report
                    </button>
                    
                    <button type="button" onclick="setQuickDateRange('today')" class="bg-green-600 hover:bg-green-700 text-white font-semibold px-4 py-2 rounded-lg shadow transition duration-200">
                        Today
                    </button>
                    
                    <button type="button" onclick="setQuickDateRange('week')" class="bg-green-600 hover:bg-green-700 text-white font-semibold px-4 py-2 rounded-lg shadow transition duration-200">
                        This Week
                    </button>
                    
                    <button type="button" onclick="setQuickDateRange('month')" class="bg-green-600 hover:bg-green-700 text-white font-semibold px-4 py-2 rounded-lg shadow transition duration-200">
                        This Month
                    </button>
                </form>
                
                <div class="mt-3 text-sm text-gray-600">
                    <i class="fas fa-info-circle mr-1"></i>
                    The report will include all bookings with check-in dates within the selected range, including guest details, financial information, and summary statistics.
                </div>
            </div>
            <!-- Booking List Table -->
            <div class="bg-white p-6 rounded-lg shadow">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-xl font-semibold">All Bookings</h2>
                    <div class="text-sm text-gray-600 flex items-center gap-4">
                        <span class="bg-green-50 text-green-700 px-2 py-1 rounded">
                            <i class="fas fa-info-circle mr-1"></i>Green rows = Fully paid (Due: BDT 0.00)
                        </span>
                        <span class="bg-blue-50 text-blue-700 px-2 py-1 rounded">
                            <i class="fas fa-bed mr-1"></i>Multiple room bookings show single booking ID
                        </span>
                    </div>
                </div>
                <div class="overflow-x-auto">
                <table class="min-w-full table-auto">
                    <thead>
                        <tr>
                            <th class="px-4 py-2">Booking ID</th>
                            <th class="px-4 py-2">Room Numbers</th>
                            <th class="px-4 py-2"># Rooms</th>
                            <th class="px-4 py-2">Guest Name</th>
                            <th class="px-4 py-2">Phone</th>
                            <th class="px-4 py-2">NID</th>
                            <th class="px-4 py-2">Profession</th>
                            <th class="px-4 py-2">Email</th>
                            <th class="px-4 py-2">Guests</th>
                            <th class="px-4 py-2">Reference</th>
                            <th class="px-4 py-2">Note</th>
                            <th class="px-4 py-2">Meal Add-ons</th>
                            <th class="px-4 py-2">Check-in</th>
                            <th class="px-4 py-2">Check-out</th>
                            <th class="px-4 py-2">Type</th>
                            <th class="px-4 py-2">Total</th>
                            <th class="px-4 py-2">Discount</th>
                            <th class="px-4 py-2">Paid</th>
                            <th class="px-4 py-2">Due</th>
                            <th class="px-4 py-2">Status</th>
                            <th class="px-4 py-2">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bookings as $booking): ?>
                        <?php 
                        $due_amount = $booking['due'] ?? 0;
                        $row_class = $due_amount == 0 ? 'border-b bg-green-50 hover:bg-green-100' : 'border-b hover:bg-gray-50';
                        
                        // Check if this is a multiple room booking
                        $note = $booking['note'] ?? '';
                        $room_numbers_from_note = [];
                        
                        // Extract room numbers from note if it contains "Multiple booking - Rooms:"
                        if (preg_match('/Multiple booking - Rooms:\s*([^|]+)/', $note, $matches)) {
                            $room_numbers_from_note = array_map('trim', explode(',', $matches[1]));
                        }
                        
                        $is_multiple_booking = (isset($booking['room_numbers']) && count($booking['room_numbers']) > 1) || !empty($room_numbers_from_note);
                        $display_booking_id = $booking['id'] ?? 'N/A'; // Use actual booking ID with fallback
                        ?>
                        <tr class="<?php echo $row_class; ?>" data-booking-id="<?php echo $display_booking_id; ?>" data-guest-email="<?php echo htmlspecialchars($booking['email'] ?? ''); ?>">
                                <td class="px-4 py-2">
                                    <?php echo $display_booking_id; ?>
                                    <?php if ($is_multiple_booking): ?>
                                        <span class="ml-1 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">
                                            <i class="fas fa-bed mr-1"></i>Multiple
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-2">
                                    <?php 
                                    // Use calculated room numbers from the processed booking data
                                    if (isset($booking['room_numbers_from_note']) && is_array($booking['room_numbers_from_note'])) {
                                        echo htmlspecialchars(implode(', ', $booking['room_numbers_from_note']));
                                    } elseif (isset($booking['room_numbers']) && is_array($booking['room_numbers']) && count($booking['room_numbers']) > 1) {
                                        // Multiple rooms from grouped bookings - show all room numbers
                                        echo htmlspecialchars(implode(', ', $booking['room_numbers']));
                                    } else {
                                        // Single room
                                        echo htmlspecialchars($booking['room_number'] ?? 'N/A');
                                    }
                                    ?>
                                </td>
                                <td class="px-4 py-2">
                                    <?php 
                                    // Use calculated room count from the processed booking data
                                    $rooms_count = $booking['actual_room_count'] ?? 1;
                                    
                                    if ($rooms_count > 1) {
                                        // Multiple rooms - show count
                                        echo '<span class="bg-blue-100 text-blue-800 px-2 py-1 rounded text-sm font-medium">' . $rooms_count . '</span>';
                                    } else {
                                        // Single room
                                        echo '<span class="bg-gray-100 text-gray-800 px-2 py-1 rounded text-sm font-medium">1</span>';
                                    }
                                    ?>
                                </td>
                            <td class="px-4 py-2"><?php echo htmlspecialchars($booking['guest_name']); ?></td>
                            <td class="px-4 py-2"><?php echo htmlspecialchars($booking['guest_contact']); ?></td>
                                <td class="px-4 py-2"><?php echo htmlspecialchars($booking['nid_number'] ?? ''); ?></td>
                            <td class="px-4 py-2"><?php echo htmlspecialchars($booking['profession'] ?? ''); ?></td>
                            <td class="px-4 py-2"><?php echo htmlspecialchars($booking['email'] ?? ''); ?></td>
                            <td class="px-4 py-2"><?php echo htmlspecialchars($booking['num_guests'] ?? 1); ?></td>
                            <td class="px-4 py-2"><?php echo htmlspecialchars($booking['reference'] ?? ''); ?></td>
                            <td class="px-4 py-2">
                                <?php 
                                $note = trim($booking['note'] ?? '');
                                if (!empty($note)) {
                                    // Extract guest notes from multiple booking format
                                    if (strpos($note, '| Guest Notes:') !== false) {
                                        $parts = explode('| Guest Notes:', $note);
                                        $guest_notes = trim($parts[1] ?? '');
                                        if (!empty($guest_notes)) {
                                            echo htmlspecialchars($guest_notes);
                                        } else {
                                            echo '<span class="text-gray-400">-</span>';
                                        }
                                    } else {
                                        echo htmlspecialchars($note);
                                    }
                                } else {
                                    echo '<span class="text-gray-400">-</span>';
                                }
                                ?>
                            </td>
                            <td class="px-4 py-2">
                                <?php
                                $meal_addons = [];
                                if (!empty($booking['breakfast_quantity']) && $booking['breakfast_quantity'] > 0) {
                                    $meal_addons[] = "Breakfast ({$booking['breakfast_quantity']}x BDT " . number_format($booking['breakfast_price'] ?? 0, 2) . ")";
                                }
                                if (!empty($booking['lunch_quantity']) && $booking['lunch_quantity'] > 0) {
                                    $meal_addons[] = "Lunch ({$booking['lunch_quantity']}x BDT " . number_format($booking['lunch_price'] ?? 0, 2) . ")";
                                }
                                if (!empty($booking['dinner_quantity']) && $booking['dinner_quantity'] > 0) {
                                    $meal_addons[] = "Dinner ({$booking['dinner_quantity']}x BDT " . number_format($booking['dinner_price'] ?? 0, 2) . ")";
                                }
                                
                                // Also check for enabled flags and totals as fallback (like in PDF)
                                if (empty($meal_addons) && (($booking['breakfast_enabled'] ?? false) || ($booking['lunch_enabled'] ?? false) || ($booking['dinner_enabled'] ?? false))) {
                                    if (($booking['breakfast_enabled'] ?? false) && ($booking['breakfast_total'] ?? 0) > 0) {
                                        $meal_addons[] = "Breakfast (" . ($booking['breakfast_quantity'] ?? 1) . "x BDT " . number_format($booking['breakfast_price'] ?? 0, 2) . ")";
                                    }
                                    if (($booking['lunch_enabled'] ?? false) && ($booking['lunch_total'] ?? 0) > 0) {
                                        $meal_addons[] = "Lunch (" . ($booking['lunch_quantity'] ?? 1) . "x BDT " . number_format($booking['lunch_price'] ?? 0, 2) . ")";
                                    }
                                    if (($booking['dinner_enabled'] ?? false) && ($booking['dinner_total'] ?? 0) > 0) {
                                        $meal_addons[] = "Dinner (" . ($booking['dinner_quantity'] ?? 1) . "x BDT " . number_format($booking['dinner_price'] ?? 0, 2) . ")";
                                    }
                                }
                                echo !empty($meal_addons) ? implode('<br>', $meal_addons) : '<span class="text-gray-400">None</span>';
                                ?>
                            </td>
                                <td class="px-4 py-2"><?php echo htmlspecialchars($booking['checkin_date']); ?></td>
                            <td class="px-4 py-2"><?php echo htmlspecialchars($booking['checkout_date']); ?></td>
                                <td class="px-4 py-2"><?php echo htmlspecialchars($booking['booking_type'] ?? 'offline'); ?></td>
                                <td class="px-4 py-2">
                                    <?php 
                                    // Show calculated room total for multiple bookings, or original total for single bookings
                                    if (isset($booking['calculated_room_total']) && $booking['calculated_room_total'] > 0) {
                                        $meal_total = $booking['meal_total'] ?? 0;
                                        $discount = $booking['discount'] ?? 0;
                                        $calculated_total = $booking['calculated_room_total'] + $meal_total - $discount;
                                        echo 'BDT ' . number_format($calculated_total, 2);
                                    } else {
                                        echo 'BDT ' . number_format($booking['total_amount'] ?? 0, 2);
                                    }
                                    ?>
                                </td>
                                <td class="px-4 py-2">BDT <?php echo number_format($booking['discount'] ?? 0, 2); ?></td>
                                <td class="px-4 py-2">BDT <?php echo number_format($booking['paid'] ?? 0, 2); ?></td>
                                <td class="px-4 py-2">
                                    <?php if (($booking['due'] ?? 0) == 0): ?>
                                        <span class="text-green-600 font-semibold">
                                            <i class="fas fa-check-circle mr-1"></i>BDT <?php echo number_format($booking['due'] ?? 0, 2); ?>
                                        </span>
                                    <?php else: ?>
                                        BDT <?php echo number_format($booking['due'] ?? 0, 2); ?>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-2">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $booking['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                    <?php echo ucfirst($booking['status']); ?>
                                </span>
                                </td>
                                <td class="px-4 py-2 flex space-x-2">
                                <?php 
                                // Prepare booking data with room information for edit modal
                                $edit_booking_data = $booking;
                                
                                // Add room information for multiple bookings
                                if (strpos($booking['note'] ?? '', 'Multiple booking - Rooms:') !== false) {
                                    if (preg_match('/Multiple booking - Rooms:\s*([^|]+)/', $booking['note'], $matches)) {
                                        $room_numbers = array_map('trim', explode(',', $matches[1]));
                                        
                                        // Use calculated room total price from processed data
                                        $room_total = $booking['calculated_room_price_per_night'] ?? 0;
                                        
                                        $edit_booking_data['room_numbers'] = $room_numbers;
                                        $edit_booking_data['room_total_price'] = $room_total;
                                        $edit_booking_data['is_multiple_booking'] = true;
                                    }
                                } else {
                                    // Single room booking - get room price
                                    $stmt = $pdo->prepare("SELECT price FROM `$rooms_table` WHERE id = ?");
                                    $stmt->execute([$booking['room_id']]);
                                    $room = $stmt->fetch();
                                    $room_price = $room ? $room['price'] : 0;
                                    
                                    $edit_booking_data['room_price'] = $room_price;
                                    $edit_booking_data['room_total_price'] = $room_price;
                                    $edit_booking_data['is_multiple_booking'] = false;
                                }
                                ?>
                                <button type="button" onclick="console.log('Edit button clicked for booking:', <?php echo htmlspecialchars(json_encode($edit_booking_data)); ?>); openEditBookingModal(<?php echo htmlspecialchars(json_encode($edit_booking_data)); ?>)" class="bg-blue-500 text-white px-3 py-1 rounded hover:bg-blue-600" title="Edit Booking"><i class="fas fa-edit"></i></button>
                                    <button type="button" onclick="downloadReceipt(<?php echo $display_booking_id; ?>)" class="bg-green-500 text-white px-3 py-1 rounded hover:bg-green-600" title="Download Receipt"><i class="fas fa-download"></i></button>
                                    <button type="button" onclick="sendEmailReceipt(<?php echo $display_booking_id; ?>)" class="bg-purple-500 text-white px-3 py-1 rounded hover:bg-purple-600" title="Send Email"><i class="fas fa-envelope"></i></button>
                                <form method="post" style="display: inline;" onsubmit="return confirm('Delete this booking?');">
                                        <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                    <button type="submit" name="delete_booking" class="bg-red-500 text-white px-3 py-1 rounded hover:bg-red-600" title="Delete Booking"><i class="fas fa-trash"></i></button>
                                </form>
                                </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
            </div>
        </div>
    </div>

    <!-- Edit Booking Modal -->
    <div id="editBookingModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-4xl w-full max-h-screen overflow-y-auto">
                <div class="p-6 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-800">Edit Booking</h3>
                        <button onclick="closeEditBookingModal()" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>
                </div>
                
                <form id="editBookingForm" method="post" class="p-6" onsubmit="return validateEditForm()">
                    <input type="hidden" id="editBookingId" name="booking_id">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Room Numbers</label>
                            <input type="text" id="editRoomNumber" readonly class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50">
                            <small class="text-gray-500">Multiple rooms are displayed as comma-separated values</small>
                            <div id="editMultipleRoomIndicator" class="mt-2 hidden">
                                <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-blue-100 text-blue-800">
                                    <i class="fas fa-bed mr-1"></i>Multiple Room Booking
                                </span>
                            </div>
                            <div id="editRoomCalculationInfo" class="mt-2 hidden">
                                <small class="text-blue-600">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    Total calculation includes all selected rooms
                                </small>
                            </div>
                        </div>
                        
                        <div class="md:col-span-2">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Check-in Date</label>
                                    <input type="date" id="editCheckinDate" name="checkin_date" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Check-in Time</label>
                                    <input type="time" id="editCheckinTime" name="checkin_time" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                </div>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Check-out Date</label>
                                    <input type="date" id="editCheckoutDate" name="checkout_date" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Check-out Time</label>
                                    <input type="time" id="editCheckoutTime" name="checkout_time" value="11:00" readonly class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50">
                                    <small class="text-gray-500">Fixed at 11:00 AM</small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Meal Add-ons Section -->
                        <div class="md:col-span-2">
                            <h4 class="text-lg font-medium text-gray-800 mb-4">Meal Add-ons</h4>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <!-- Breakfast -->
                                <div class="border border-gray-200 rounded-lg p-4">
                                    <div class="flex items-center mb-3">
                                        <input type="checkbox" id="editBreakfastCheckbox" name="breakfast_enabled" class="mr-2">
                                        <label for="editBreakfastCheckbox" class="font-medium text-gray-700">Breakfast</label>
                                    </div>
                                    <div class="space-y-2">
                                        <div>
                                            <label class="block text-sm text-gray-600">Price (BDT)</label>
                                            <input type="number" id="editBreakfastPrice" name="breakfast_price" min="0" step="0.01" value="0" class="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                                        </div>
                                        <div>
                                            <label class="block text-sm text-gray-600">Quantity</label>
                                            <input type="number" id="editBreakfastQuantity" name="breakfast_quantity" min="0" value="0" class="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                                        </div>
                                        <div>
                                            <label class="block text-sm text-gray-600">Total</label>
                                            <input type="number" id="editBreakfastTotal" name="breakfast_total" readonly class="w-full px-2 py-1 border border-gray-300 rounded text-sm bg-gray-50">
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Lunch -->
                                <div class="border border-gray-200 rounded-lg p-4">
                                    <div class="flex items-center mb-3">
                                        <input type="checkbox" id="editLunchCheckbox" name="lunch_enabled" class="mr-2">
                                        <label for="editLunchCheckbox" class="font-medium text-gray-700">Lunch</label>
                                    </div>
                                    <div class="space-y-2">
                                        <div>
                                            <label class="block text-sm text-gray-600">Price (BDT)</label>
                                            <input type="number" id="editLunchPrice" name="lunch_price" min="0" step="0.01" value="0" class="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                                        </div>
                                        <div>
                                            <label class="block text-sm text-gray-600">Quantity</label>
                                            <input type="number" id="editLunchQuantity" name="lunch_quantity" min="0" value="0" class="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                                        </div>
                                        <div>
                                            <label class="block text-sm text-gray-600">Total</label>
                                            <input type="number" id="editLunchTotal" name="lunch_total" readonly class="w-full px-2 py-1 border border-gray-300 rounded text-sm bg-gray-50">
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Dinner -->
                                <div class="border border-gray-200 rounded-lg p-4">
                                    <div class="flex items-center mb-3">
                                        <input type="checkbox" id="editDinnerCheckbox" name="dinner_enabled" class="mr-2">
                                        <label for="editDinnerCheckbox" class="font-medium text-gray-700">Dinner</label>
                                    </div>
                                    <div class="space-y-2">
                                        <div>
                                            <label class="block text-sm text-gray-600">Price (BDT)</label>
                                            <input type="number" id="editDinnerPrice" name="dinner_price" min="0" step="0.01" value="0" class="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                                        </div>
                                        <div>
                                            <label class="block text-sm text-gray-600">Quantity</label>
                                            <input type="number" id="editDinnerQuantity" name="dinner_quantity" min="0" value="0" class="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                                        </div>
                                        <div>
                                            <label class="block text-sm text-gray-600">Total</label>
                                            <input type="number" id="editDinnerTotal" name="dinner_total" readonly class="w-full px-2 py-1 border border-gray-300 rounded text-sm bg-gray-50">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-4">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Total Meal Add-ons (BDT)</label>
                                <input type="number" id="editMealTotal" name="meal_total" readonly class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50">
                            </div>
                        </div>
                        
                        <!-- Calculation Summary Section -->
                        <div class="md:col-span-2">
                            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                                <h5 class="font-semibold text-blue-800 mb-3">
                                    <i class="fas fa-calculator mr-2"></i>Calculation Summary
                                </h5>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                                    <div>
                                        <label class="block text-gray-700 font-medium">Room Cost Breakdown:</label>
                                        <div id="editRoomBreakdown" class="text-gray-600 mt-1">
                                            <!-- Room breakdown will be populated by JavaScript -->
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-gray-700 font-medium">Calculation Formula:</label>
                                        <div class="text-gray-600 mt-1">
                                            <div>• Room Total = Room Price(s) × Nights</div>
                                            <div>• Subtotal = Room Total + Meal Total</div>
                                            <div>• Final Total = Subtotal - Discount</div>
                                            <div>• Due Amount = Final Total - Paid</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Total Amount (BDT)</label>
                            <input type="number" id="editTotalAmount" name="total_amount" readonly class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Discount Amount (BDT)</label>
                            <input type="number" id="editDiscountPercent" name="discount" min="0" step="0.01" value="0" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Paid Amount (BDT)</label>
                            <input type="number" id="editPaidAmount" name="paid" min="0" step="0.01" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Due Amount (BDT)</label>
                            <input type="number" id="editDueAmount" name="due" readonly class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Number of Guests</label>
                            <input type="number" id="editNumGuests" name="num_guests" min="1" max="10" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        </div>
                    </div>
                    
                    <div class="mt-6 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Guest Name</label>
                            <input type="text" id="editGuestName" name="guest_name" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Reference (Optional)</label>
                                <input type="text" id="editReference" name="reference" placeholder="Reference number or code" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Note (Optional)</label>
                                <textarea id="editNote" name="note" placeholder="Additional notes or special requests" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></textarea>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">NID Number</label>
                                <input type="text" id="editNidNumber" name="nid_number" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Profession</label>
                                <input type="text" id="editProfession" name="profession" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Email</label>
                                <input type="email" id="editEmail" name="email" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Phone Number</label>
                                <input type="tel" id="editPhone" name="phone" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Address</label>
                            <input type="text" id="editAddress" name="address" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        </div>
                        
                        <div class="mt-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Booking Type</label>
                            <select id="editBookingType" name="booking_type" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                <option value="online">Online</option>
                                <option value="offline">Offline</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                            <select id="editStatus" name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                <option value="active">Active</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" onclick="closeEditBookingModal()" class="px-4 py-2 text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">
                            Cancel
                        </button>
                        <button type="submit" name="edit_booking" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                            Update Booking
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Clear filters function
        function clearFilters() {
            document.querySelector('input[name="booking_search"]').value = '';
            document.querySelector('select[name="booking_type_filter"]').value = '';
            document.querySelector('select[name="status_filter"]').value = '';
            document.querySelector('input[name="date_from"]').value = '';
            document.querySelector('input[name="date_to"]').value = '';
            document.querySelector('select[name="date_filter_type"]').value = 'checkin';
            document.querySelector('form').submit();
        }

        // Download receipt function
        function downloadReceipt(bookingId) {
            window.open(`php/generate_booking_pdf.php?booking_id=${bookingId}&hotel_id=<?php echo $hotel_id; ?>`, '_blank');
        }

        // Send email receipt function
        function sendEmailReceipt(bookingId) {
            // Get the guest email from the booking data
            const bookingRow = document.querySelector(`tr[data-booking-id="${bookingId}"]`);
            const guestEmail = bookingRow ? bookingRow.getAttribute('data-guest-email') : '';
            
            if (!guestEmail) {
                alert('Guest email not found. Please add email address to the booking first.');
                return;
            }
            
            if (confirm('Send booking receipt via email to ' + guestEmail + '?')) {
                // Show loading message
                const loadingMsg = 'Sending email to ' + guestEmail + '...';
                console.log(loadingMsg);
                
                // Send JSON request to email PDF
                fetch('php/email_booking_pdf.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        booking_id: bookingId,
                        hotel_id: <?php echo $hotel_id; ?>,
                        email: guestEmail
                    })
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        alert('✅ Email sent successfully to ' + guestEmail);
                        console.log('Email sent successfully:', data.message);
                    } else {
                        alert('❌ Failed to send email: ' + (data.message || 'Unknown error'));
                        console.error('Email sending failed:', data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('❌ Failed to send email. Please check your internet connection and try again.');
                });
            }
        }

        // Edit Booking Modal Functions
        function openEditBookingModal(bookingData) {
            try {
                console.log('Opening edit modal with data:', bookingData);
                
                // Check if bookingData is valid
                if (!bookingData || !bookingData.id) {
                    console.error('Invalid booking data:', bookingData);
                    alert('Error: Invalid booking data. Please try again.');
                    return;
                }
            
            // Populate form fields with booking data
            document.getElementById('editBookingId').value = bookingData.id;
            
            // Handle room numbers for multiple bookings
            let roomNumbers = bookingData.room_number || '';
            const note = bookingData.note || '';
            
            // Check if this is a multiple room booking by looking at the note field
            const multipleRoomIndicator = document.getElementById('editMultipleRoomIndicator');
            const roomCalculationInfo = document.getElementById('editRoomCalculationInfo');
            if (note.includes('Multiple booking - Rooms:')) {
                const match = note.match(/Multiple booking - Rooms:\s*([^|]+)/);
                if (match) {
                    roomNumbers = match[1].trim();
                    multipleRoomIndicator.classList.remove('hidden');
                    roomCalculationInfo.classList.remove('hidden');
                }
            } else {
                multipleRoomIndicator.classList.add('hidden');
                roomCalculationInfo.classList.add('hidden');
            }
            
            document.getElementById('editRoomNumber').value = roomNumbers;
            // Populate form fields with validation
            try {
                document.getElementById('editGuestName').value = bookingData.guest_name || '';
                document.getElementById('editPhone').value = bookingData.guest_contact || '';
                document.getElementById('editNidNumber').value = bookingData.nid_number || '';
                document.getElementById('editProfession').value = bookingData.profession || '';
                document.getElementById('editEmail').value = bookingData.email || '';
                document.getElementById('editNumGuests').value = bookingData.num_guests || 1;
                document.getElementById('editCheckinDate').value = bookingData.checkin_date || '';
                document.getElementById('editCheckinTime').value = bookingData.checkin_time || '';
                document.getElementById('editCheckoutDate').value = bookingData.checkout_date || '';
                document.getElementById('editCheckoutTime').value = bookingData.checkout_time || '11:00';
                document.getElementById('editBookingType').value = bookingData.booking_type || 'offline';
                document.getElementById('editStatus').value = bookingData.status || 'active';
                document.getElementById('editDiscountPercent').value = bookingData.discount || 0;
                document.getElementById('editPaidAmount').value = bookingData.paid || 0;
                document.getElementById('editAddress').value = bookingData.address || '';
                document.getElementById('editReference').value = bookingData.reference || '';
                
                console.log('Form fields populated successfully');
            } catch (error) {
                console.error('Error populating form fields:', error);
                alert('Error populating form fields. Please try again.');
                return;
            }
            // Extract guest notes from multiple booking format
            let guestNotes = bookingData.note || '';
            if (guestNotes.includes('| Guest Notes:')) {
                const parts = guestNotes.split('| Guest Notes:');
                guestNotes = parts[1] ? parts[1].trim() : '';
            }
            document.getElementById('editNote').value = guestNotes;
            
            // Set room information for total calculation
            window.editRoomPrice = bookingData.room_price || 0;
            window.editRoomNumbers = bookingData.room_numbers || [];
            window.editIsMultipleBooking = bookingData.is_multiple_booking || false;
            window.editRoomTotalPrice = bookingData.room_total_price || 0;
            
            // Populate meal data
            document.getElementById('editBreakfastPrice').value = bookingData.breakfast_price || 0;
            document.getElementById('editBreakfastQuantity').value = bookingData.breakfast_quantity || 0;
            document.getElementById('editBreakfastTotal').value = bookingData.breakfast_total || 0;
            document.getElementById('editLunchPrice').value = bookingData.lunch_price || 0;
            document.getElementById('editLunchQuantity').value = bookingData.lunch_quantity || 0;
            document.getElementById('editLunchTotal').value = bookingData.lunch_total || 0;
            document.getElementById('editDinnerPrice').value = bookingData.dinner_price || 0;
            document.getElementById('editDinnerQuantity').value = bookingData.dinner_quantity || 0;
            document.getElementById('editDinnerTotal').value = bookingData.dinner_total || 0;
            document.getElementById('editMealTotal').value = bookingData.meal_total || 0;
            
            // Set meal checkboxes
            document.getElementById('editBreakfastCheckbox').checked = (bookingData.breakfast_price > 0 || bookingData.breakfast_quantity > 0);
            document.getElementById('editLunchCheckbox').checked = (bookingData.lunch_price > 0 || bookingData.lunch_quantity > 0);
            document.getElementById('editDinnerCheckbox').checked = (bookingData.dinner_price > 0 || bookingData.dinner_quantity > 0);
            
            // Enable/disable meal fields based on checkboxes
            updateEditMealFields();
            
            // Recalculate total amount to include meals
            calculateEditTotalAmount();
            
            // Update calculation summary
            updateEditCalculationSummary();
            
            // Show modal
            document.getElementById('editBookingModal').classList.remove('hidden');
            console.log('Modal opened successfully');
            } catch (error) {
                console.error('Error in openEditBookingModal:', error);
                alert('Error opening edit modal: ' + error.message);
            }
        }
        
        function closeEditBookingModal() {
            document.getElementById('editBookingModal').classList.add('hidden');
        }
        
        function updateEditMealFields() {
            const breakfastEnabled = document.getElementById('editBreakfastCheckbox').checked;
            const lunchEnabled = document.getElementById('editLunchCheckbox').checked;
            const dinnerEnabled = document.getElementById('editDinnerCheckbox').checked;
            
            // Breakfast fields
            document.getElementById('editBreakfastPrice').disabled = !breakfastEnabled;
            document.getElementById('editBreakfastQuantity').disabled = !breakfastEnabled;
            if (!breakfastEnabled) {
                document.getElementById('editBreakfastPrice').classList.add('bg-gray-50');
                document.getElementById('editBreakfastQuantity').classList.add('bg-gray-50');
            } else {
                document.getElementById('editBreakfastPrice').classList.remove('bg-gray-50');
                document.getElementById('editBreakfastQuantity').classList.remove('bg-gray-50');
            }
            
            // Lunch fields
            document.getElementById('editLunchPrice').disabled = !lunchEnabled;
            document.getElementById('editLunchQuantity').disabled = !lunchEnabled;
            if (!lunchEnabled) {
                document.getElementById('editLunchPrice').classList.add('bg-gray-50');
                document.getElementById('editLunchQuantity').classList.add('bg-gray-50');
            } else {
                document.getElementById('editLunchPrice').classList.remove('bg-gray-50');
                document.getElementById('editLunchQuantity').classList.remove('bg-gray-50');
            }
            
            // Dinner fields
            document.getElementById('editDinnerPrice').disabled = !dinnerEnabled;
            document.getElementById('editDinnerQuantity').disabled = !dinnerEnabled;
            if (!dinnerEnabled) {
                document.getElementById('editDinnerPrice').classList.add('bg-gray-50');
                document.getElementById('editDinnerQuantity').classList.add('bg-gray-50');
            } else {
                document.getElementById('editDinnerPrice').classList.remove('bg-gray-50');
                document.getElementById('editDinnerQuantity').classList.remove('bg-gray-50');
            }
            
            calculateEditMealTotals();
        }
        
        function calculateEditMealTotals() {
            // Calculate individual meal totals
            const breakfastPrice = parseFloat(document.getElementById('editBreakfastPrice').value) || 0;
            const breakfastQuantity = parseInt(document.getElementById('editBreakfastQuantity').value) || 0;
            const breakfastTotal = breakfastPrice * breakfastQuantity;
            document.getElementById('editBreakfastTotal').value = breakfastTotal.toFixed(2);
            
            const lunchPrice = parseFloat(document.getElementById('editLunchPrice').value) || 0;
            const lunchQuantity = parseInt(document.getElementById('editLunchQuantity').value) || 0;
            const lunchTotal = lunchPrice * lunchQuantity;
            document.getElementById('editLunchTotal').value = lunchTotal.toFixed(2);
            
            const dinnerPrice = parseFloat(document.getElementById('editDinnerPrice').value) || 0;
            const dinnerQuantity = parseInt(document.getElementById('editDinnerQuantity').value) || 0;
            const dinnerTotal = dinnerPrice * dinnerQuantity;
            document.getElementById('editDinnerTotal').value = dinnerTotal.toFixed(2);
            
            // Calculate total meal amount
            const mealTotal = breakfastTotal + lunchTotal + dinnerTotal;
            document.getElementById('editMealTotal').value = mealTotal.toFixed(2);
            
            // Update total amount (which now includes meals)
            calculateEditTotalAmount();
        }
        
        function calculateEditTotalAmount() {
            const checkin = document.getElementById('editCheckinDate').value;
            const checkout = document.getElementById('editCheckoutDate').value;
            let roomTotal = 0;
            
            if (checkin && checkout) {
                const d1 = new Date(checkin);
                const d2 = new Date(checkout);
                const nights = Math.ceil((d2 - d1) / (1000 * 60 * 60 * 24));
                
                if (nights > 0) {
                    if (window.editIsMultipleBooking && window.editRoomNumbers.length > 0) {
                        // Multiple room booking - calculate total for all rooms
                        const roomTotalPrice = parseFloat(window.editRoomTotalPrice) || 0;
                        roomTotal = roomTotalPrice * nights;
                    } else {
                        // Single room booking
                        const roomPrice = parseFloat(window.editRoomPrice) || 0;
                        roomTotal = roomPrice * nights;
                    }
                }
            }
            
            // Calculate meal total
            const mealTotal = parseFloat(document.getElementById('editMealTotal').value) || 0;
            
            // Total Amount = Room Prices + Meal Prices
            const totalAmount = roomTotal + mealTotal;
            
            // Amount to be Paid = Total Amount - Discount
            const discount = parseFloat(document.getElementById('editDiscountPercent').value) || 0;
            const amountToBePaid = totalAmount - discount;
            
            document.getElementById('editTotalAmount').value = amountToBePaid.toFixed(2);
            
            // Recalculate due amount after total change
            calculateEditDueAmount();
        }
        
        function calculateEditDueAmount() {
            const total = parseFloat(document.getElementById('editTotalAmount').value) || 0;
            const paid = parseFloat(document.getElementById('editPaidAmount').value) || 0;
            
            // Due Amount = Amount to be Paid - Paid Amount
            const due = Math.max(0, total - paid);
            
            document.getElementById('editDueAmount').value = due.toFixed(2);
        }
        
        function updateEditCalculationSummary() {
            const checkin = document.getElementById('editCheckinDate').value;
            const checkout = document.getElementById('editCheckoutDate').value;
            const roomBreakdown = document.getElementById('editRoomBreakdown');
            
            if (checkin && checkout) {
                const d1 = new Date(checkin);
                const d2 = new Date(checkout);
                const nights = Math.ceil((d2 - d1) / (1000 * 60 * 60 * 24));
                
                if (nights > 0) {
                    let breakdownHtml = '';
                    
                    if (window.editIsMultipleBooking && window.editRoomNumbers && window.editRoomNumbers.length > 0) {
                        // Multiple room booking
                        const roomTotalPrice = parseFloat(window.editRoomTotalPrice) || 0;
                        const roomTotal = roomTotalPrice * nights;
                        
                        breakdownHtml = `
                            <div>• Multiple Rooms: ${window.editRoomNumbers.join(', ')}</div>
                            <div>• Total Room Price: BDT ${roomTotalPrice.toFixed(2)}</div>
                            <div>• Nights: ${nights}</div>
                            <div>• Room Total: BDT ${roomTotal.toFixed(2)}</div>
                        `;
                    } else {
                        // Single room booking
                        const roomPrice = parseFloat(window.editRoomPrice) || 0;
                        const roomTotal = roomPrice * nights;
                        
                        breakdownHtml = `
                            <div>• Room Price: BDT ${roomPrice.toFixed(2)}</div>
                            <div>• Nights: ${nights}</div>
                            <div>• Room Total: BDT ${roomTotal.toFixed(2)}</div>
                        `;
                    }
                    
                    roomBreakdown.innerHTML = breakdownHtml;
                } else {
                    roomBreakdown.innerHTML = '<div class="text-red-500">Invalid date range</div>';
                }
            } else {
                roomBreakdown.innerHTML = '<div class="text-gray-400">Select check-in and check-out dates</div>';
            }
        }
        
        // Add event listeners for edit modal
        document.addEventListener('DOMContentLoaded', function() {
            // Meal checkbox event listeners
            document.getElementById('editBreakfastCheckbox').addEventListener('change', updateEditMealFields);
            document.getElementById('editLunchCheckbox').addEventListener('change', updateEditMealFields);
            document.getElementById('editDinnerCheckbox').addEventListener('change', updateEditMealFields);
            
            // Meal price and quantity event listeners
            document.getElementById('editBreakfastPrice').addEventListener('input', calculateEditMealTotals);
            document.getElementById('editBreakfastQuantity').addEventListener('input', calculateEditMealTotals);
            document.getElementById('editLunchPrice').addEventListener('input', calculateEditMealTotals);
            document.getElementById('editLunchQuantity').addEventListener('input', calculateEditMealTotals);
            document.getElementById('editDinnerPrice').addEventListener('input', calculateEditMealTotals);
            document.getElementById('editDinnerQuantity').addEventListener('input', calculateEditMealTotals);
            
            // Date change event listeners for total recalculation
            document.getElementById('editCheckinDate').addEventListener('change', function() {
                calculateEditTotalAmount();
                updateEditCalculationSummary();
            });
            document.getElementById('editCheckoutDate').addEventListener('change', function() {
                calculateEditTotalAmount();
                updateEditCalculationSummary();
            });
            
            // Financial fields event listeners
            document.getElementById('editDiscountPercent').addEventListener('input', calculateEditTotalAmount);
            document.getElementById('editPaidAmount').addEventListener('input', calculateEditDueAmount);
        });

        // Show success message without auto-refresh
        <?php if ($message): ?>
        // Remove auto-refresh to prevent duplication issues
        // setTimeout(function() {
        //     location.reload();
        // }, 2000);
        <?php endif; ?>

        // Edit form validation function
        function validateEditForm() {
            console.log('Validating edit form...');
            
            const bookingId = document.getElementById('editBookingId').value;
            const guestName = document.getElementById('editGuestName').value;
            const phone = document.getElementById('editPhone').value;
            const checkinDate = document.getElementById('editCheckinDate').value;
            const checkoutDate = document.getElementById('editCheckoutDate').value;
            const nidNumber = document.getElementById('editNidNumber').value;
            const address = document.getElementById('editAddress').value;
            
            console.log('Form data:', {
                bookingId: bookingId,
                guestName: guestName,
                phone: phone,
                nidNumber: nidNumber,
                address: address,
                checkinDate: checkinDate,
                checkoutDate: checkoutDate
            });
            
            if (!bookingId) {
                alert('Booking ID is missing. Please try again.');
                return false;
            }
            
            if (!guestName.trim()) {
                alert('Guest name is required.');
                return false;
            }
            
            if (!phone.trim()) {
                alert('Phone number is required.');
                return false;
            }
            
            if (!checkinDate) {
                alert('Check-in date is required.');
                return false;
            }
            
            if (!checkoutDate) {
                alert('Check-out date is required.');
                return false;
            }
            
            if (checkinDate >= checkoutDate) {
                alert('Check-out date must be after check-in date.');
                return false;
            }
            
            console.log('Form validation passed, submitting...');
            return true;
        }

        // Date range validation function
        function validateDateRange() {
            const startDate = document.getElementById('start_date').value;
            const endDate = document.getElementById('end_date').value;
            
            if (!startDate || !endDate) {
                alert('Please select both start and end dates.');
                return false;
            }
            
            if (startDate > endDate) {
                alert('Start date cannot be after end date.');
                return false;
            }
            
            return true;
        }

        // Quick date range selection functions
        function setQuickDateRange(range) {
            const today = new Date();
            let startDate, endDate;
            
            switch(range) {
                case 'today':
                    startDate = today.toISOString().split('T')[0];
                    endDate = startDate;
                    break;
                case 'week':
                    const startOfWeek = new Date(today);
                    startOfWeek.setDate(today.getDate() - today.getDay());
                    startDate = startOfWeek.toISOString().split('T')[0];
                    endDate = today.toISOString().split('T')[0];
                    break;
                case 'month':
                    const startOfMonth = new Date(today.getFullYear(), today.getMonth(), 1);
                    startDate = startOfMonth.toISOString().split('T')[0];
                    endDate = today.toISOString().split('T')[0];
                    break;
                default:
                    return;
            }
            
            document.getElementById('start_date').value = startDate;
            document.getElementById('end_date').value = endDate;
        }

        // Enhanced search functionality
        function enhanceSearch() {
            const searchInput = document.querySelector('input[name="booking_search"]');
            const searchForm = document.querySelector('form');
            
            if (searchInput) {
                // Add search tips
                searchInput.addEventListener('focus', function() {
                    this.title = 'Search by: Phone (0160), NID (TEMP), Name (Saidul), Room (103), Reference (JOY)';
                });
                
                // Show search suggestions on input
                searchInput.addEventListener('input', function() {
                    const value = this.value.trim();
                    if (value.length >= 2) {
                        // Add visual feedback
                        this.style.borderColor = '#10B981'; // Green border for active search
                    } else {
                        this.style.borderColor = '#D1D5DB'; // Default border
                    }
                });
                
                // Clear search styling when empty
                searchInput.addEventListener('blur', function() {
                    if (!this.value.trim()) {
                        this.style.borderColor = '#D1D5DB';
                    }
                });
            }
            
            // Add search result counter
            const resultCount = document.querySelectorAll('tbody tr').length;
            if (resultCount > 0) {
                const header = document.querySelector('.text-xl.font-semibold');
                if (header) {
                    header.innerHTML = `All Bookings <span class="text-sm text-gray-500">(${resultCount} found)</span>`;
                }
            }
        }
        
        // Initialize enhanced search on page load
        document.addEventListener('DOMContentLoaded', function() {
            enhanceSearch();
        });
        
        // Multiple booking functions
        function openEditMultipleBookingModal(booking) {
            // Show a comprehensive edit modal for multiple bookings
            const modal = document.createElement('div');
            modal.className = 'fixed inset-0 bg-gray-600 bg-opacity-50 z-50 flex items-center justify-center';
            modal.innerHTML = `
                <div class="bg-white rounded-lg shadow-xl max-w-6xl w-full max-h-screen overflow-y-auto">
                    <div class="p-6 border-b border-gray-200">
                        <div class="flex items-center justify-between">
                            <h3 class="text-lg font-semibold text-gray-800">Edit Multiple Booking</h3>
                            <button onclick="this.closest('.fixed').remove()" class="text-gray-400 hover:text-gray-600">
                                <i class="fas fa-times text-xl"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="p-6">
                        <div class="mb-4">
                            <h4 class="font-semibold text-gray-800 mb-2">Current Booking Information:</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                                <p><strong>Guest:</strong> ${booking.guest_name}</p>
                                <p><strong>Phone:</strong> ${booking.guest_contact}</p>
                                <p><strong>Email:</strong> ${booking.email || 'N/A'}</p>
                                <p><strong>NID:</strong> ${booking.nid_number || 'N/A'}</p>
                                <p><strong>Check-in:</strong> ${booking.checkin_date}</p>
                                <p><strong>Check-out:</strong> ${booking.checkout_date}</p>
                                <p><strong>Rooms:</strong> ${booking.rooms.map(r => r.room_number).join(', ')}</p>
                                <p><strong>Total Amount:</strong> BDT ${parseFloat(booking.total_amount).toFixed(2)}</p>
                            </div>
                        </div>
                        
                        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
                            <h5 class="font-semibold text-yellow-800 mb-2">
                                <i class="fas fa-info-circle mr-2"></i>Multiple Booking Edit
                            </h5>
                            <p class="text-yellow-700 text-sm">
                                This is a multiple room booking. You can edit guest information, dates, payment details, and meal add-ons here.
                                All changes will apply to all rooms in this booking.
                            </p>
                        </div>
                        
                        <form id="multipleBookingEditForm">
                            <!-- Guest Information Section -->
                            <div class="mb-6">
                                <h5 class="font-semibold text-gray-800 mb-3 border-b pb-2">Guest Information</h5>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Guest Name *</label>
                                        <input type="text" id="multiGuestName" value="${booking.guest_name}" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Phone *</label>
                                        <input type="tel" id="multiPhone" value="${booking.guest_contact}" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Email</label>
                                        <input type="email" id="multiEmail" value="${booking.email || ''}" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">NID Number</label>
                                        <input type="text" id="multiNidNumber" value="${booking.nid_number || ''}" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Profession</label>
                                        <input type="text" id="multiProfession" value="${booking.profession || ''}" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Number of Guests</label>
                                        <input type="number" id="multiNumGuests" value="${booking.num_guests || 1}" min="1" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                    </div>
                                    <div class="md:col-span-2">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Address</label>
                                        <textarea id="multiAddress" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg">${booking.address || ''}</textarea>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Stay Information Section -->
                            <div class="mb-6">
                                <h5 class="font-semibold text-gray-800 mb-3 border-b pb-2">Stay Information</h5>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Check-in Date *</label>
                                        <input type="date" id="multiCheckinDate" value="${booking.checkin_date}" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Check-out Date *</label>
                                        <input type="date" id="multiCheckoutDate" value="${booking.checkout_date}" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Check-in Time</label>
                                        <input type="time" id="multiCheckinTime" value="${booking.checkin_time || '11:00'}" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Check-out Time</label>
                                        <input type="time" id="multiCheckoutTime" value="${booking.checkout_time || '10:50'}" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Booking Type</label>
                                        <select id="multiBookingType" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                            <option value="offline" ${booking.booking_type === 'offline' ? 'selected' : ''}>Offline</option>
                                            <option value="online" ${booking.booking_type === 'online' ? 'selected' : ''}>Online</option>
                                            <option value="phone" ${booking.booking_type === 'phone' ? 'selected' : ''}>Phone</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                                        <select id="multiStatus" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                            <option value="active" ${booking.status === 'active' ? 'selected' : ''}>Active</option>
                                            <option value="cancelled" ${booking.status === 'cancelled' ? 'selected' : ''}>Cancelled</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Meal Add-ons Section -->
                            <div class="mb-6">
                                <h5 class="font-semibold text-gray-800 mb-3 border-b pb-2">Meal Add-ons</h5>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <div class="border border-gray-200 rounded-lg p-4">
                                        <div class="flex items-center mb-3">
                                            <input type="checkbox" id="multiBreakfastCheckbox" ${(booking.breakfast_quantity > 0 || booking.breakfast_price > 0) ? 'checked' : ''} class="mr-2">
                                            <label for="multiBreakfastCheckbox" class="font-medium text-gray-700">Breakfast</label>
                                        </div>
                                        <div class="space-y-2">
                                            <div>
                                                <label class="block text-xs text-gray-600 mb-1">Price (BDT)</label>
                                                <input type="number" id="multiBreakfastPrice" value="${booking.breakfast_price || 0}" min="0" step="0.01" class="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                                            </div>
                                            <div>
                                                <label class="block text-xs text-gray-600 mb-1">Quantity</label>
                                                <input type="number" id="multiBreakfastQuantity" value="${booking.breakfast_quantity || 0}" min="0" class="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                                            </div>
                                            <div>
                                                <label class="block text-xs text-gray-600 mb-1">Total</label>
                                                <input type="number" id="multiBreakfastTotal" value="${booking.breakfast_total || 0}" readonly class="w-full px-2 py-1 border border-gray-300 rounded text-sm bg-gray-50">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="border border-gray-200 rounded-lg p-4">
                                        <div class="flex items-center mb-3">
                                            <input type="checkbox" id="multiLunchCheckbox" ${(booking.lunch_quantity > 0 || booking.lunch_price > 0) ? 'checked' : ''} class="mr-2">
                                            <label for="multiLunchCheckbox" class="font-medium text-gray-700">Lunch</label>
                                        </div>
                                        <div class="space-y-2">
                                            <div>
                                                <label class="block text-xs text-gray-600 mb-1">Price (BDT)</label>
                                                <input type="number" id="multiLunchPrice" value="${booking.lunch_price || 0}" min="0" step="0.01" class="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                                            </div>
                                            <div>
                                                <label class="block text-xs text-gray-600 mb-1">Quantity</label>
                                                <input type="number" id="multiLunchQuantity" value="${booking.lunch_quantity || 0}" min="0" class="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                                            </div>
                                            <div>
                                                <label class="block text-xs text-gray-600 mb-1">Total</label>
                                                <input type="number" id="multiLunchTotal" value="${booking.lunch_total || 0}" readonly class="w-full px-2 py-1 border border-gray-300 rounded text-sm bg-gray-50">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="border border-gray-200 rounded-lg p-4">
                                        <div class="flex items-center mb-3">
                                            <input type="checkbox" id="multiDinnerCheckbox" ${(booking.dinner_quantity > 0 || booking.dinner_price > 0) ? 'checked' : ''} class="mr-2">
                                            <label for="multiDinnerCheckbox" class="font-medium text-gray-700">Dinner</label>
                                        </div>
                                        <div class="space-y-2">
                                            <div>
                                                <label class="block text-xs text-gray-600 mb-1">Price (BDT)</label>
                                                <input type="number" id="multiDinnerPrice" value="${booking.dinner_price || 0}" min="0" step="0.01" class="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                                            </div>
                                            <div>
                                                <label class="block text-xs text-gray-600 mb-1">Quantity</label>
                                                <input type="number" id="multiDinnerQuantity" value="${booking.dinner_quantity || 0}" min="0" class="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                                            </div>
                                            <div>
                                                <label class="block text-xs text-gray-600 mb-1">Total</label>
                                                <input type="number" id="multiDinnerTotal" value="${booking.dinner_total || 0}" readonly class="w-full px-2 py-1 border border-gray-300 rounded text-sm bg-gray-50">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-4">
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Total Meal Add-ons (BDT)</label>
                                    <input type="number" id="multiMealTotal" value="${booking.meal_total || 0}" readonly class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50">
                                </div>
                            </div>
                            
                            <!-- Payment Information Section -->
                            <div class="mb-6">
                                <h5 class="font-semibold text-gray-800 mb-3 border-b pb-2">Payment Information</h5>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Total Amount (BDT)</label>
                                        <input type="number" id="multiTotalAmount" value="${booking.total_amount || 0}" data-original-total="${booking.total_amount - (booking.meal_total || 0) + (booking.discount || 0)}" readonly class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50 font-semibold">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Discount Amount (BDT)</label>
                                        <input type="number" id="multiDiscount" value="${booking.discount || 0}" min="0" step="0.01" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Paid Amount (BDT)</label>
                                        <input type="number" id="multiPaidAmount" value="${booking.paid || 0}" min="0" step="0.01" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Due Amount (BDT)</label>
                                        <input type="number" id="multiDueAmount" value="${booking.due || 0}" readonly class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50">
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Additional Information Section -->
                            <div class="mb-6">
                                <h5 class="font-semibold text-gray-800 mb-3 border-b pb-2">Additional Information</h5>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Reference</label>
                                        <input type="text" id="multiReference" value="${booking.reference || ''}" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                    </div>
                                    <div class="md:col-span-2">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Note</label>
                                        <textarea id="multiNote" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-lg">${booking.note || ''}</textarea>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="flex justify-end space-x-3">
                                <button type="button" onclick="this.closest('.fixed').remove()" class="px-4 py-2 text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">
                                    Cancel
                                </button>
                                <button type="button" onclick="updateMultipleBooking('${booking.booking_ids.join(',')}')" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                                    Update Booking
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
            
            // Add event listeners for meal calculations
            setupMultipleBookingEventListeners();
        }
        
        function setupMultipleBookingEventListeners() {
            // Meal checkbox event listeners
            document.getElementById('multiBreakfastCheckbox').addEventListener('change', updateMultipleMealFields);
            document.getElementById('multiLunchCheckbox').addEventListener('change', updateMultipleMealFields);
            document.getElementById('multiDinnerCheckbox').addEventListener('change', updateMultipleMealFields);
            
            // Meal price and quantity event listeners
            document.getElementById('multiBreakfastPrice').addEventListener('input', calculateMultipleMealTotals);
            document.getElementById('multiBreakfastQuantity').addEventListener('input', calculateMultipleMealTotals);
            document.getElementById('multiLunchPrice').addEventListener('input', calculateMultipleMealTotals);
            document.getElementById('multiLunchQuantity').addEventListener('input', calculateMultipleMealTotals);
            document.getElementById('multiDinnerPrice').addEventListener('input', calculateMultipleMealTotals);
            document.getElementById('multiDinnerQuantity').addEventListener('input', calculateMultipleMealTotals);
            
            // Payment field event listeners
            document.getElementById('multiDiscount').addEventListener('input', calculateMultipleTotal);
            document.getElementById('multiPaidAmount').addEventListener('input', calculateMultipleDue);
            
            // Initialize calculations
            updateMultipleMealFields();
            calculateMultipleMealTotals();
        }
        
        function updateMultipleMealFields() {
            const breakfastEnabled = document.getElementById('multiBreakfastCheckbox').checked;
            const lunchEnabled = document.getElementById('multiLunchCheckbox').checked;
            const dinnerEnabled = document.getElementById('multiDinnerCheckbox').checked;
            
            // Breakfast fields
            document.getElementById('multiBreakfastPrice').disabled = !breakfastEnabled;
            document.getElementById('multiBreakfastQuantity').disabled = !breakfastEnabled;
            if (!breakfastEnabled) {
                document.getElementById('multiBreakfastPrice').classList.add('bg-gray-50');
                document.getElementById('multiBreakfastQuantity').classList.add('bg-gray-50');
            } else {
                document.getElementById('multiBreakfastPrice').classList.remove('bg-gray-50');
                document.getElementById('multiBreakfastQuantity').classList.remove('bg-gray-50');
            }
            
            // Lunch fields
            document.getElementById('multiLunchPrice').disabled = !lunchEnabled;
            document.getElementById('multiLunchQuantity').disabled = !lunchEnabled;
            if (!lunchEnabled) {
                document.getElementById('multiLunchPrice').classList.add('bg-gray-50');
                document.getElementById('multiLunchQuantity').classList.add('bg-gray-50');
            } else {
                document.getElementById('multiLunchPrice').classList.remove('bg-gray-50');
                document.getElementById('multiLunchQuantity').classList.remove('bg-gray-50');
            }
            
            // Dinner fields
            document.getElementById('multiDinnerPrice').disabled = !dinnerEnabled;
            document.getElementById('multiDinnerQuantity').disabled = !dinnerEnabled;
            if (!dinnerEnabled) {
                document.getElementById('multiDinnerPrice').classList.add('bg-gray-50');
                document.getElementById('multiDinnerQuantity').classList.add('bg-gray-50');
            } else {
                document.getElementById('multiDinnerPrice').classList.remove('bg-gray-50');
                document.getElementById('multiDinnerQuantity').classList.remove('bg-gray-50');
            }
            
            calculateMultipleMealTotals();
        }
        
        function calculateMultipleMealTotals() {
            // Calculate individual meal totals
            const breakfastPrice = parseFloat(document.getElementById('multiBreakfastPrice').value) || 0;
            const breakfastQuantity = parseInt(document.getElementById('multiBreakfastQuantity').value) || 0;
            const breakfastTotal = breakfastPrice * breakfastQuantity;
            document.getElementById('multiBreakfastTotal').value = breakfastTotal.toFixed(2);
            
            const lunchPrice = parseFloat(document.getElementById('multiLunchPrice').value) || 0;
            const lunchQuantity = parseInt(document.getElementById('multiLunchQuantity').value) || 0;
            const lunchTotal = lunchPrice * lunchQuantity;
            document.getElementById('multiLunchTotal').value = lunchTotal.toFixed(2);
            
            const dinnerPrice = parseFloat(document.getElementById('multiDinnerPrice').value) || 0;
            const dinnerQuantity = parseInt(document.getElementById('multiDinnerQuantity').value) || 0;
            const dinnerTotal = dinnerPrice * dinnerQuantity;
            document.getElementById('multiDinnerTotal').value = dinnerTotal.toFixed(2);
            
            // Calculate total meal amount
            const mealTotal = breakfastTotal + lunchTotal + dinnerTotal;
            document.getElementById('multiMealTotal').value = mealTotal.toFixed(2);
            
            // Update total amount
            calculateMultipleTotal();
        }
        
        function calculateMultipleTotal() {
            // Get the original room total (without meals and discount)
            const originalTotal = parseFloat(document.getElementById('multiTotalAmount').getAttribute('data-original-total') || '0');
            const mealTotal = parseFloat(document.getElementById('multiMealTotal').value) || 0;
            const discount = parseFloat(document.getElementById('multiDiscount').value) || 0;
            
            // Calculate new total: Original room total + meal total - discount
            const totalAmount = originalTotal + mealTotal - discount;
            document.getElementById('multiTotalAmount').value = totalAmount.toFixed(2);
            
            // Recalculate due amount
            calculateMultipleDue();
        }
        
        function calculateMultipleDue() {
            const total = parseFloat(document.getElementById('multiTotalAmount').value) || 0;
            const paid = parseFloat(document.getElementById('multiPaidAmount').value) || 0;
            const due = Math.max(0, total - paid);
            document.getElementById('multiDueAmount').value = due.toFixed(2);
        }

        function downloadMultipleReceipt(bookingIds) {
            const url = `php/download_booking_receipt.php?booking_ids=${bookingIds}&hotel_id=<?php echo $hotel_id; ?>`;
            window.open(url, '_blank');
        }
        
        function sendMultipleEmailReceipt(bookingIds) {
            // Get the guest email from the booking row
            const bookingRow = document.querySelector(`tr[data-booking-ids="${bookingIds}"]`);
            const guestEmail = bookingRow ? bookingRow.getAttribute('data-guest-email') : '';
            
            if (!guestEmail) {
                alert('Guest email not found. Please add email address to the booking first.');
                return;
            }
            
            if (confirm('Send booking receipt via email to ' + guestEmail + '?')) {
                // Show loading message
                const loadingMsg = 'Sending email to ' + guestEmail + '...';
                console.log(loadingMsg);
                
                fetch('php/email_booking_pdf.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        booking_ids: bookingIds.split(','),
                        email: guestEmail,
                        hotel_id: <?php echo $hotel_id; ?>
                    })
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        alert('✅ Email sent successfully to ' + guestEmail);
                        console.log('Email sent successfully:', data.message);
                    } else {
                        alert('❌ Failed to send email: ' + (data.message || 'Unknown error'));
                        console.error('Email sending failed:', data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('❌ Failed to send email. Please check your internet connection and try again.');
                });
            }
        }

        function updateMultipleBooking(bookingIds) {
            const guestName = document.getElementById('multiGuestName').value;
            const phone = document.getElementById('multiPhone').value;
            const email = document.getElementById('multiEmail').value;
            const nidNumber = document.getElementById('multiNidNumber').value;
            const profession = document.getElementById('multiProfession').value;
            const numGuests = document.getElementById('multiNumGuests').value;
            const address = document.getElementById('multiAddress').value;
            const checkinDate = document.getElementById('multiCheckinDate').value;
            const checkoutDate = document.getElementById('multiCheckoutDate').value;
            const checkinTime = document.getElementById('multiCheckinTime').value;
            const checkoutTime = document.getElementById('multiCheckoutTime').value;
            const bookingType = document.getElementById('multiBookingType').value;
            const status = document.getElementById('multiStatus').value;
            const reference = document.getElementById('multiReference').value;
            const note = document.getElementById('multiNote').value;
            
            // Meal add-ons
            const breakfastPrice = parseFloat(document.getElementById('multiBreakfastPrice').value) || 0;
            const breakfastQuantity = parseInt(document.getElementById('multiBreakfastQuantity').value) || 0;
            const breakfastTotal = parseFloat(document.getElementById('multiBreakfastTotal').value) || 0;
            const lunchPrice = parseFloat(document.getElementById('multiLunchPrice').value) || 0;
            const lunchQuantity = parseInt(document.getElementById('multiLunchQuantity').value) || 0;
            const lunchTotal = parseFloat(document.getElementById('multiLunchTotal').value) || 0;
            const dinnerPrice = parseFloat(document.getElementById('multiDinnerPrice').value) || 0;
            const dinnerQuantity = parseInt(document.getElementById('multiDinnerQuantity').value) || 0;
            const dinnerTotal = parseFloat(document.getElementById('multiDinnerTotal').value) || 0;
            const mealTotal = parseFloat(document.getElementById('multiMealTotal').value) || 0;
            
            // Payment information
            const totalAmount = parseFloat(document.getElementById('multiTotalAmount').value) || 0;
            const discount = parseFloat(document.getElementById('multiDiscount').value) || 0;
            const paidAmount = parseFloat(document.getElementById('multiPaidAmount').value) || 0;
            const dueAmount = parseFloat(document.getElementById('multiDueAmount').value) || 0;
            
            if (!guestName || !phone || !checkinDate || !checkoutDate) {
                alert('Please fill in all required fields.');
                return;
            }
            
            // Send update request
            fetch('php/update_multiple_booking.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    booking_ids: bookingIds.split(','),
                    guest_name: guestName,
                    phone: phone,
                    email: email,
                    nid_number: nidNumber,
                    profession: profession,
                    num_guests: numGuests,
                    address: address,
                    checkin_date: checkinDate,
                    checkout_date: checkoutDate,
                    checkin_time: checkinTime,
                    checkout_time: checkoutTime,
                    booking_type: bookingType,
                    status: status,
                    reference: reference,
                    note: note,
                    breakfast_price: breakfastPrice,
                    breakfast_quantity: breakfastQuantity,
                    breakfast_total: breakfastTotal,
                    lunch_price: lunchPrice,
                    lunch_quantity: lunchQuantity,
                    lunch_total: lunchTotal,
                    dinner_price: dinnerPrice,
                    dinner_quantity: dinnerQuantity,
                    dinner_total: dinnerTotal,
                    meal_total: mealTotal,
                    total_amount: totalAmount,
                    discount: discount,
                    paid: paidAmount,
                    due: dueAmount,
                    hotel_id: <?php echo $hotel_id; ?>
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Multiple booking updated successfully!');
                    location.reload();
                } else {
                    alert('Failed to update booking: ' + data.message);
                }
            })
            .catch(error => {
                alert('Error updating booking: ' + error.message);
            });
        }
    </script>
</body>
</html> 