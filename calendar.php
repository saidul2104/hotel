<?php
session_start();
require_once 'database/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Set $user for sidebar and other references
$user = [
    'username' => $_SESSION['user_name'],
    'role' => $_SESSION['user_role']
];

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

// Fetch hotel info
$stmt = $pdo->prepare("SELECT * FROM hotels WHERE id = ?");
$stmt->execute([$hotel_id]);
$hotel = $stmt->fetch();

// Get current month and year, or from URL parameters
$month = isset($_GET['month']) ? intval($_GET['month']) : date('n');
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y'); // Default to current year

// Get rooms for this hotel with enhanced category data from room_categories table
$rooms_table = "rooms_hotel_{$hotel_id}";
$stmt = $pdo->prepare("
    SELECT DISTINCT r.id, r.room_number, r.category, r.price, r.status, r.description, rc.name as category_name, rc.description as category_description, rc.price as category_price
    FROM `$rooms_table` r 
    LEFT JOIN room_categories rc ON r.category = rc.name AND rc.hotel_id = ? 
    WHERE r.status != 'maintenance'
    ORDER BY rc.price ASC, r.room_number
");
$stmt->execute([$hotel_id]);
$rooms = $stmt->fetchAll();

// Get maintenance rooms separately
$stmt = $pdo->prepare("
    SELECT r.*, rc.name as category_name, rc.description as category_description, rc.price as category_price
    FROM `$rooms_table` r 
    LEFT JOIN room_categories rc ON r.category = rc.name AND rc.hotel_id = ? 
    WHERE r.status = 'maintenance'
    ORDER BY rc.price ASC, r.room_number
");
$stmt->execute([$hotel_id]);
$maintenance_rooms = $stmt->fetchAll();

// Group rooms by category dynamically with enhanced category data
$rooms_by_cat = [];
$categories = [];
$category_data = [];
$seen_room_numbers = []; // Track seen room numbers to avoid duplicates

foreach ($rooms as $room) {
    $category = $room['category'] ?: 'Other Rooms';
    $category_name = $room['category_name'] ?: $room['category'] ?: 'Other Rooms';
    
    // Skip if we've already seen this room number
    if (in_array($room['room_number'], $seen_room_numbers)) {
        continue;
    }
    
    $seen_room_numbers[] = $room['room_number'];
    
    if (!isset($rooms_by_cat[$category])) {
        $rooms_by_cat[$category] = [];
        $categories[] = $category;
        $category_data[$category] = [
            'name' => $category_name,
            'description' => $room['category_description'] ?: '',
            'price' => $room['category_price'] ?: 0
        ];
    }
    $rooms_by_cat[$category][] = $room;
}

// Sort categories by price (from room_categories table)
usort($categories, function($a, $b) use ($category_data) {
    $price_a = $category_data[$a]['price'] ?? 0;
    $price_b = $category_data[$b]['price'] ?? 0;
    return $price_a <=> $price_b;
});

// Get bookings for the month for this hotel with enhanced data
$bookings_table = "bookings_hotel_{$hotel_id}";
$first_day = date('Y-m-01', mktime(0,0,0,$month,1,$year));
$last_day = date('Y-m-t', mktime(0,0,0,$month,1,$year));

// Enhanced booking query to get all necessary guest data
// Only get current and future bookings, not expired ones
// Apply 11 AM checkout rule: rooms become available after 11 AM on checkout day
$today = date('Y-m-d');
$current_time = date('H:i:s');
$stmt = $pdo->prepare("
    SELECT b.*, r.room_number, r.category
    FROM `$bookings_table` b 
    JOIN `$rooms_table` r ON b.room_id = r.id 
    WHERE b.status = 'active' 
    AND (
        -- Future bookings
        b.checkout_date > ? OR
        -- Today's bookings that haven't checked out yet (before 11 AM)
        (b.checkout_date = ? AND b.checkout_time > ?)
    )
    AND b.checkin_date <= ?
    ORDER BY b.checkin_date, r.room_number
");
$stmt->execute([$today, $today, $current_time, $last_day]);
$bookings = $stmt->fetchAll();

// Process bookings to handle multiple room bookings
$processed_bookings = [];
foreach ($bookings as $booking) {
    // Check if this is a multiple room booking
    if (strpos($booking['note'] ?? '', 'Multiple booking - Rooms:') !== false) {
        // Extract room numbers from note
        if (preg_match('/Multiple booking - Rooms:\s*([^|]+)/', $booking['note'], $matches)) {
            $room_numbers = array_map('trim', explode(',', $matches[1]));
            
            // Create a booking entry for each room
            foreach ($room_numbers as $room_number) {
                // Get room details for this room number
                $stmt = $pdo->prepare("SELECT id, room_number, category FROM `$rooms_table` WHERE room_number = ?");
                $stmt->execute([$room_number]);
                $room = $stmt->fetch();
                
                if ($room) {
                    // Create a copy of the booking for this room
                    $room_booking = $booking;
                    $room_booking['room_id'] = $room['id'];
                    $room_booking['room_number'] = $room['room_number'];
                    $room_booking['category'] = $room['category'];
                    $room_booking['is_multiple_booking'] = true;
                    $room_booking['all_room_numbers'] = $room_numbers;
                    
                    $processed_bookings[] = $room_booking;
                }
            }
        }
    } else {
        // Single room booking - add as is
        $booking['is_multiple_booking'] = false;
        $processed_bookings[] = $booking;
    }
}

// Use processed bookings instead of original bookings
$bookings = $processed_bookings;

// Function to generate different colors for different guests
function getBookingColor($guest_name) {
    $colors = [
        'bg-red-500 text-white',
        'bg-blue-500 text-white',
        'bg-green-500 text-white',
        'bg-purple-500 text-white',
        'bg-pink-500 text-white',
        'bg-indigo-500 text-white',
        'bg-yellow-500 text-black',
        'bg-orange-500 text-white',
        'bg-teal-500 text-white',
        'bg-cyan-500 text-white',
        'bg-emerald-500 text-white',
        'bg-rose-500 text-white',
        'bg-violet-500 text-white',
        'bg-amber-500 text-black',
        'bg-lime-500 text-black',
        'bg-sky-500 text-white',
        'bg-fuchsia-500 text-white',
        'bg-slate-500 text-white',
        'bg-gray-500 text-white',
        'bg-zinc-500 text-white'
    ];
    
    // Use guest name to consistently assign the same color to the same guest
    $color_index = abs(crc32($guest_name)) % count($colors);
    return $colors[$color_index];
}

// Function to get booking color CSS class
function getBookingColorClass($guest_name) {
    $colors = [
        'booking-red',
        'booking-blue', 
        'booking-green',
        'booking-purple',
        'booking-pink',
        'booking-indigo',
        'booking-yellow',
        'booking-orange',
        'booking-teal',
        'booking-cyan',
        'booking-emerald',
        'booking-rose',
        'booking-violet',
        'booking-amber',
        'booking-lime',
        'booking-sky',
        'booking-fuchsia',
        'booking-slate',
        'booking-gray',
        'booking-zinc'
    ];
    
    // Use guest name to consistently assign the same color to the same guest
    $color_index = abs(crc32($guest_name)) % count($colors);
    return $colors[$color_index];
}

// Function to display guest name with multiple booking indicator
function displayGuestName($booking) {
    $name = htmlspecialchars($booking['guest_name']);
    if (isset($booking['is_multiple_booking']) && $booking['is_multiple_booking']) {
        $name .= ' <span class="text-xs bg-blue-100 text-blue-800 px-1 rounded">Multi</span>';
    }
    return $name;
}

// Function to generate comprehensive tooltip for multi-day bookings
function generateBookingTooltip($booking, $current_date = null, $is_checkout = false) {
    $tooltip = "Guest: " . htmlspecialchars($booking['guest_name']) . "\n";
    $tooltip .= "Phone: " . htmlspecialchars($booking['guest_contact']) . "\n";
    
    // Add multiple room booking information
    if (isset($booking['is_multiple_booking']) && $booking['is_multiple_booking']) {
        $tooltip .= "Rooms: " . implode(', ', $booking['all_room_numbers']) . "\n";
    } else {
        $tooltip .= "Room: " . htmlspecialchars($booking['room_number']) . "\n";
    }
    
    // Add booking period information
    $checkin_date = new DateTime($booking['checkin_date']);
    $checkout_date = new DateTime($booking['checkout_date']);
    $nights = $checkin_date->diff($checkout_date)->days;
    
    $tooltip .= "Check-in: " . $checkin_date->format('M j, Y') . " at " . ($booking['checkin_time'] ?? '11:00 AM') . "\n";
    $tooltip .= "Check-out: " . $checkout_date->format('M j, Y') . " at " . ($booking['checkout_time'] ?? '10:50 AM') . "\n";
    $tooltip .= "Duration: " . $nights . " night" . ($nights != 1 ? 's' : '') . "\n";
    
    // Add current date context if provided
    
    // Add detailed meal information - enhanced detection
    $meal_details = [];
    
    // Check for breakfast - multiple conditions to ensure we catch all cases
    if (($booking['breakfast_enabled'] && $booking['breakfast_total'] > 0) || 
        ($booking['breakfast_quantity'] > 0) || 
        ($booking['breakfast_price'] > 0 && $booking['breakfast_quantity'] > 0)) {
        $meal_details[] = "Breakfast: " . ($booking['breakfast_quantity'] ?? 0) . "x ৳" . number_format($booking['breakfast_price'] ?? 0, 2);
    }
    
    // Check for lunch - multiple conditions to ensure we catch all cases
    if (($booking['lunch_enabled'] && $booking['lunch_total'] > 0) || 
        ($booking['lunch_quantity'] > 0) || 
        ($booking['lunch_price'] > 0 && $booking['lunch_quantity'] > 0)) {
        $meal_details[] = "Lunch: " . ($booking['lunch_quantity'] ?? 0) . "x ৳" . number_format($booking['lunch_price'] ?? 0, 2);
    }
    
    // Check for dinner - multiple conditions to ensure we catch all cases
    if (($booking['dinner_enabled'] && $booking['dinner_total'] > 0) || 
        ($booking['dinner_quantity'] > 0) || 
        ($booking['dinner_price'] > 0 && $booking['dinner_quantity'] > 0)) {
        $meal_details[] = "Dinner: " . ($booking['dinner_quantity'] ?? 0) . "x ৳" . number_format($booking['dinner_price'] ?? 0, 2);
    }
    
    // Also check for legacy meal display field as fallback
    if (empty($meal_details) && !empty($booking['meals_display'])) {
        $tooltip .= "Meals: " . htmlspecialchars($booking['meals_display']) . "\n";
    } elseif (!empty($meal_details)) {
        $tooltip .= "Meals: " . implode(", ", $meal_details) . "\n";
    }
    
    // Add guest notes
    if ($booking['note']) {
        $tooltip .= "Notes: " . htmlspecialchars($booking['note']) . "\n";
    }
    
    // Add additional guest information
   
    
    // Add booking type
   
    return $tooltip;
}

// Create enhanced booking map for horizontal booking bars
$booking_map = [];
$booking_spans = []; // Track booking spans for horizontal display
$current_date = new DateTime();
$current_date->setTime(0, 0, 0);

foreach ($bookings as $booking) {
    $checkin = new DateTime($booking['checkin_date']);
    $checkout = new DateTime($booking['checkout_date']);
    $room_id = $booking['room_id'];
    
    // Calculate nights
    $nights = $checkin->diff($checkout)->days;
    
    // Add meal information - enhanced detection
    $meals = [];
    if (($booking['breakfast_enabled'] && $booking['breakfast_total'] > 0) || 
        ($booking['breakfast_quantity'] > 0) || 
        ($booking['breakfast_price'] > 0 && $booking['breakfast_quantity'] > 0)) {
        $meals[] = 'BF';
    }
    if (($booking['lunch_enabled'] && $booking['lunch_total'] > 0) || 
        ($booking['lunch_quantity'] > 0) || 
        ($booking['lunch_price'] > 0 && $booking['lunch_quantity'] > 0)) {
        $meals[] = 'Lunch';
    }
    if (($booking['dinner_enabled'] && $booking['dinner_total'] > 0) || 
        ($booking['dinner_quantity'] > 0) || 
        ($booking['dinner_price'] > 0 && $booking['dinner_quantity'] > 0)) {
        $meals[] = 'Dinner';
    }
    
    $booking['meals_display'] = implode(', ', $meals);
    $booking['nights'] = $nights;
    
    // Store booking span information for horizontal display
    $booking_spans[$room_id][] = [
        'booking' => $booking,
        'start_date' => $checkin->format('Y-m-d'),
        'end_date' => $checkout->format('Y-m-d'),
        'start_day' => $checkin->format('j'),
        'end_day' => $checkout->format('j'),
        'nights' => $nights,
        'checkin_month' => $checkin->format('n'),
        'checkout_month' => $checkout->format('n')
    ];
    
    // Also store in booking_map for individual day lookups
    for ($date = clone $checkin; $date < $checkout; $date->add(new DateInterval('P1D'))) {
        $date_str = $date->format('Y-m-d');
        if (!isset($booking_map[$date_str])) {
            $booking_map[$date_str] = [];
        }
        $booking_map[$date_str][$room_id] = $booking;
    }
    
    // Handle checkout day - available after 10:50 AM
    $checkout_date_str = $checkout->format('Y-m-d');
    if (!isset($booking_map[$checkout_date_str])) {
        $booking_map[$checkout_date_str] = [];
    }
    $booking_map[$checkout_date_str][$room_id] = array_merge($booking, ['checkout_day' => true]);
}



// Calculate calendar display dates
$today = new DateTime();
$today->setTime(0, 0, 0);

// Always show full month, but mark past dates as expired
$start_day = 1;
$days_in_month = date('t', mktime(0, 0, 0, $month, 1, $year));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hotel - Calendar View</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .sidebar {
            background: linear-gradient(180deg, #667eea 0%, #764ba2 100%);
        }
        .calendar-cell {
            transition: all 0.3s ease;
            width: 140px;
            height: 120px;
            position: relative;
            padding: 4px;
            vertical-align: top;
            box-sizing: border-box;
        }
        .calendar-cell:hover {
            filter: brightness(0.95);
            z-index: 10;
        }
        
        /* Ensure all booking elements can show tooltips */
        .merged-booking-bar,
        .booked-portion,
        .booking-bar,
        .booking-bar-red,
        .booking-bar-blue,
        .booking-bar-green,
        .booking-bar-purple,
        .booking-bar-pink,
        .booking-bar-indigo,
        .booking-bar-yellow,
        .booking-bar-orange,
        .booking-bar-teal,
        .booking-bar-cyan,
        .booking-bar-emerald,
        .booking-bar-rose,
        .booking-bar-violet,
        .booking-bar-amber,
        .booking-bar-lime,
        .booking-bar-sky,
        .booking-bar-fuchsia,
        .booking-bar-slate,
        .booking-bar-gray,
        .booking-bar-zinc,
        .booking-middle {
            position: relative;
            cursor: pointer;
        }
        
        /* Smooth tooltip transition */
        .calendar-cell[data-tooltip]:hover::after,
        .merged-booking-bar[data-tooltip]:hover::after,
        .booked-portion[data-tooltip]:hover::after,
        .booking-bar[data-tooltip]:hover::after,
        .booking-bar-red[data-tooltip]:hover::after,
        .booking-bar-blue[data-tooltip]:hover::after,
        .booking-bar-green[data-tooltip]:hover::after,
        .booking-bar-purple[data-tooltip]:hover::after,
        .booking-bar-pink[data-tooltip]:hover::after,
        .booking-bar-indigo[data-tooltip]:hover::after,
        .booking-bar-yellow[data-tooltip]:hover::after,
        .booking-bar-orange[data-tooltip]:hover::after,
        .booking-bar-teal[data-tooltip]:hover::after,
        .booking-bar-cyan[data-tooltip]:hover::after,
        .booking-bar-emerald[data-tooltip]:hover::after,
        .booking-bar-rose[data-tooltip]:hover::after,
        .booking-bar-violet[data-tooltip]:hover::after,
        .booking-bar-amber[data-tooltip]:hover::after,
        .booking-bar-lime[data-tooltip]:hover::after,
        .booking-bar-sky[data-tooltip]:hover::after,
        .booking-bar-fuchsia[data-tooltip]:hover::after,
        .booking-bar-slate[data-tooltip]:hover::after,
        .booking-bar-gray[data-tooltip]:hover::after,
        .booking-bar-zinc[data-tooltip]:hover::after,
        .booking-middle[data-tooltip]:hover::after {
            animation: tooltipFadeIn 0.2s ease-out;
        }
        
        @keyframes tooltipFadeIn {
            from {
                opacity: 0;
                transform: translateX(-50%) translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateX(-50%) translateY(0);
            }
        }
        
        /* Ensure tooltips don't get cut off at edges */
        .calendar-cell[data-tooltip]:hover::after,
        .merged-booking-bar[data-tooltip]:hover::after,
        .booked-portion[data-tooltip]:hover::after,
        .booking-bar[data-tooltip]:hover::after,
        .booking-bar-red[data-tooltip]:hover::after,
        .booking-bar-blue[data-tooltip]:hover::after,
        .booking-bar-green[data-tooltip]:hover::after,
        .booking-bar-purple[data-tooltip]:hover::after,
        .booking-bar-pink[data-tooltip]:hover::after,
        .booking-bar-indigo[data-tooltip]:hover::after,
        .booking-bar-yellow[data-tooltip]:hover::after,
        .booking-bar-orange[data-tooltip]:hover::after,
        .booking-bar-teal[data-tooltip]:hover::after,
        .booking-bar-cyan[data-tooltip]:hover::after,
        .booking-bar-emerald[data-tooltip]:hover::after,
        .booking-bar-rose[data-tooltip]:hover::after,
        .booking-bar-violet[data-tooltip]:hover::after,
        .booking-bar-amber[data-tooltip]:hover::after,
        .booking-bar-lime[data-tooltip]:hover::after,
        .booking-bar-sky[data-tooltip]:hover::after,
        .booking-bar-fuchsia[data-tooltip]:hover::after,
        .booking-bar-slate[data-tooltip]:hover::after,
        .booking-bar-gray[data-tooltip]:hover::after,
        .booking-bar-zinc[data-tooltip]:hover::after,
        .booking-middle[data-tooltip]:hover::after {
            /* Ensure tooltip stays within viewport */
            max-width: min(350px, calc(100vw - 40px));
            word-break: break-word;
        }
        
        /* Add hover effect to indicate tooltip availability */
        .merged-booking-bar:hover,
        .booked-portion:hover,
        .booking-bar:hover,
        .booking-bar-red:hover,
        .booking-bar-blue:hover,
        .booking-bar-green:hover,
        .booking-bar-purple:hover,
        .booking-bar-pink:hover,
        .booking-bar-indigo:hover,
        .booking-bar-yellow:hover,
        .booking-bar-orange:hover,
        .booking-bar-teal:hover,
        .booking-bar-cyan:hover,
        .booking-bar-emerald:hover,
        .booking-bar-rose:hover,
        .booking-bar-violet:hover,
        .booking-bar-amber:hover,
        .booking-bar-lime:hover,
        .booking-bar-sky:hover,
        .booking-bar-fuchsia:hover,
        .booking-bar-slate:hover,
        .booking-bar-gray:hover,
        .booking-bar-zinc:hover,
        .booking-middle:hover {
            transform: scale(1.02);
            transition: transform 0.1s ease;
        }
        .booked {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            font-size: 10px;
            line-height: 1.2;
        }
        .booked .text-xs {
            color: white;
            text-shadow: 0 1px 2px rgba(0,0,0,0.3);
        }
        .multi-day-booking {
            border-right: 2px solid #dc2626;
        }
        .multi-day-booking:last-child {
            border-right: none;
        }
        .available {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }
        .expired {
            background: linear-gradient(135deg, #fde68a 0%, #fbbf24 100%);
            color: #92400e;
        }

        .booking-bar-red {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            min-height: 110px;
            border-radius: 8px;
            box-shadow: 0 3px 6px rgba(0,0,0,0.15);
            transition: all 0.3s ease;
            border: 2px solid rgba(255,255,255,0.4);
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 8px 6px;
            position: relative;
            overflow: hidden;
            cursor: default;
        }
        
        /* Booking color classes */
        .booking-red {
            background: linear-gradient(135deg, #ef4444, #dc2626) !important;
            color: white !important;
        }
        
        .booking-blue {
            background: linear-gradient(135deg, #3b82f6, #2563eb) !important;
            color: white !important;
        }
        
        .booking-green {
            background: linear-gradient(135deg, #10b981, #059669) !important;
            color: white !important;
        }
        
        .booking-purple {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed) !important;
            color: white !important;
        }
        
        .booking-pink {
            background: linear-gradient(135deg, #ec4899, #db2777) !important;
            color: white !important;
        }
        
        .booking-indigo {
            background: linear-gradient(135deg, #6366f1, #4f46e5) !important;
            color: white !important;
        }
        
        .booking-yellow {
            background: linear-gradient(135deg, #eab308, #ca8a04) !important;
            color: black !important;
        }
        
        .booking-orange {
            background: linear-gradient(135deg, #f97316, #ea580c) !important;
            color: white !important;
        }
        
        .booking-teal {
            background: linear-gradient(135deg, #14b8a6, #0d9488) !important;
            color: white !important;
        }
        
        .booking-cyan {
            background: linear-gradient(135deg, #06b6d4, #0891b2) !important;
            color: white !important;
        }
        
        .booking-emerald {
            background: linear-gradient(135deg, #10b981, #047857) !important;
            color: white !important;
        }
        
        .booking-rose {
            background: linear-gradient(135deg, #f43f5e, #e11d48) !important;
            color: white !important;
        }
        
        .booking-violet {
            background: linear-gradient(135deg, #8b5cf6, #6d28d9) !important;
            color: white !important;
        }
        
        .booking-amber {
            background: linear-gradient(135deg, #f59e0b, #d97706) !important;
            color: black !important;
        }
        
        .booking-lime {
            background: linear-gradient(135deg, #84cc16, #65a30d) !important;
            color: black !important;
        }
        
        .booking-sky {
            background: linear-gradient(135deg, #0ea5e9, #0284c7) !important;
            color: white !important;
        }
        
        .booking-fuchsia {
            background: linear-gradient(135deg, #d946ef, #c026d3) !important;
            color: white !important;
        }
        
        .booking-slate {
            background: linear-gradient(135deg, #64748b, #475569) !important;
            color: white !important;
        }
        
        .booking-gray {
            background: linear-gradient(135deg, #6b7280, #4b5563) !important;
            color: white !important;
        }
        
        .booking-zinc {
            background: linear-gradient(135deg, #71717a, #52525b) !important;
            color: white !important;
        }
        
        /* Merged booking bar styles for continuous booking spans */
        .merged-booking-bar {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            min-height: 110px;
            box-shadow: 0 3px 6px rgba(0,0,0,0.15);
            transition: all 0.3s ease;
            border: 2px solid rgba(255,255,255,0.4);
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 8px 6px;
            position: relative;
            overflow: hidden;
            cursor: default;
        }
        
        /* First day of merged booking */
        .merged-booking-bar.booking-start {
            border-radius: 8px 0 0 8px;
            border-right: 1px solid rgba(255,255,255,0.6);
        }
        
        /* Middle days of merged booking */
        .merged-booking-bar.booking-middle {
            border-radius: 0;
            border-left: 1px solid rgba(255,255,255,0.6);
            border-right: 1px solid rgba(255,255,255,0.6);
        }
        
        /* Last day of merged booking */
        .merged-booking-bar.booking-end {
            border-radius: 0 8px 8px 0;
            border-left: 1px solid rgba(255,255,255,0.6);
        }
        
        /* Single day booking */
        .merged-booking-bar.booking-single {
            border-radius: 8px;
        }
        .booking-bar-red .guest-name {
            font-weight: 700;
            font-size: 12px;
            line-height: 1.2;
            margin-bottom: 3px;
            text-shadow: 0 1px 2px rgba(0,0,0,0.3);
        }
        .booking-bar-red .guest-contact {
            font-size: 10px;
            line-height: 1.1;
            margin-bottom: 2px;
            opacity: 0.95;
        }
        .booking-bar-red .meal-plan {
            font-size: 9px;
            line-height: 1.1;
            margin-bottom: 2px;
            opacity: 0.9;
            font-style: italic;
        }
        .booking-bar-red .guest-notes {
            font-size: 8px;
            line-height: 1.1;
            opacity: 0.85;
            font-style: italic;
        }
        .checkin-day {
            position: relative;
            overflow: hidden;
        }
        .checkin-day .available-portion-left {
            width: 50%;
            height: 100%;
            position: absolute;
            left: 0;
            top: 0;
            background: linear-gradient(135deg, #10b981, #059669);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            color: white;
            font-weight: bold;
            font-size: 10px;
            cursor: pointer;
            transition: all 0.2s ease;
            border-radius: 4px 0 0 4px;
        }
        .checkin-day .available-portion-left:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        .checkin-day .available-portion-left .label {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 2px;
        }
        .checkin-day .available-portion-left .text {
            font-size: 8px;
            text-align: center;
        }
        .checkin-day .booking-portion-right {
            width: 50%;
            height: 100%;
            position: absolute;
            right: 0;
            top: 0;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            color: white;
            font-size: 9px;
            padding: 2px;
            border-radius: 0 4px 4px 0;
        }
        
        /* Merged booking portion styles */
        .checkin-day .booking-portion-right.merged-booking-bar.booking-start {
            border-radius: 0 8px 0 0;
            border-right: 1px solid rgba(255,255,255,0.6);
        }
        
        /* Invisible two-portion system styles */
        .calendar-cell {
            position: relative;
        }
        
        .day-portions {
            position: relative;
            width: 100%;
            height: 100%;
            min-height: 110px;
        }
        
        .left-portion {
            position: absolute;
            left: 0;
            top: 0;
            width: 50%;
            height: 100%;
            border-right: 1px solid rgba(255,255,255,0.3);
        }
        
        .right-portion {
            position: absolute;
            right: 0;
            top: 0;
            width: 50%;
            height: 100%;
        }
        
        /* Available portions */
        .available-portion {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            cursor: pointer;
            transition: all 0.2s ease;
            height: 100%;
            font-weight: bold;
            font-size: 10px;
        }
        
        .available-portion:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        
        .available-portion .label {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 2px;
        }
        
        .available-portion .text {
            font-size: 8px;
            text-align: center;
        }
        
        /* Booked portions */
        .booked-portion {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            height: 100%;
            font-size: 9px;
            padding: 2px;
        }
        
        .booked-portion .guest-name {
            font-weight: 700;
            font-size: 10px;
            line-height: 1.2;
            margin-bottom: 2px;
            text-shadow: 0 1px 2px rgba(0,0,0,0.3);
        }
        
        .booked-portion .guest-contact {
            font-size: 8px;
            line-height: 1.1;
            margin-bottom: 1px;
            opacity: 0.95;
        }
        
        .booked-portion .meal-plan {
            font-size: 7px;
            line-height: 1.1;
            opacity: 0.9;
            font-style: italic;
        }
        
        /* Merged booking connections */
        .booked-portion.booking-start {
            border-radius: 0 8px 0 0;
            border-right: 1px solid rgba(255,255,255,0.6);
        }
        
        .booked-portion.booking-middle {
            border-radius: 0;
            border-left: 1px solid rgba(255,255,255,0.6);
            border-right: 1px solid rgba(255,255,255,0.6);
        }
        
        .booked-portion.booking-end {
            border-radius: 0 0 0 8px;
            border-left: 1px solid rgba(255,255,255,0.6);
        }
        
        .booked-portion.booking-single {
            border-radius: 8px;
        }
        .checkout-day {
            position: relative;
            overflow: hidden;
        }
        .checkout-day .booking-portion-left {
            width: 50%;
            height: 100%;
            position: absolute;
            left: 0;
            top: 0;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            color: white;
            font-size: 9px;
            padding: 2px;
            border-radius: 4px 0 0 4px;
        }
        
        /* Merged booking portion styles for checkout */
        .checkout-day .booking-portion-left.merged-booking-bar.booking-end {
            border-radius: 0 0 0 8px;
            border-left: 1px solid rgba(255,255,255,0.6);
        }
        .checkout-day .available-portion-right {
            width: 50%;
            height: 100%;
            position: absolute;
            right: 0;
            top: 0;
            background: linear-gradient(135deg, #10b981, #059669);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            color: white;
            font-weight: bold;
            font-size: 10px;
            cursor: pointer;
            transition: all 0.2s ease;
            border-radius: 0 4px 4px 0;
        }
        .checkout-day .available-portion-right:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        .checkout-day .available-portion-right .label {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 2px;
        }
        .checkout-day .available-portion-right .text {
            font-size: 8px;
            text-align: center;
        }
        


        .category-rooms {
            transition: all 0.3s ease;
        }
        .category-rooms.collapsed {
            display: none;
        }
        .room-row {
            transition: all 0.3s ease;
        }
        .room-row:hover {
            background-color: #f8fafc;
        }
        /* Enhanced tooltip styles */
        .calendar-cell[title]:hover::after {
            content: attr(title);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0, 0, 0, 0.9);
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            white-space: pre-line;
            z-index: 1000;
            max-width: 300px;
            word-wrap: break-word;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .calendar-cell[title]:hover::before {
            content: '';
        }
        
        /* Enhanced calendar cell styles for horizontal booking bars */
        .calendar-cell {
            min-height: 100px;
            padding: 2px;
            position: relative;
            border: 1px solid #e5e7eb;
            vertical-align: top;
        }
        
        /* Horizontal booking bar styles */
        .booking-bar {
            min-height: 90px;
            border-radius: 8px;
            box-shadow: 0 3px 6px rgba(0,0,0,0.15);
            transition: all 0.3s ease;
            border: 2px solid rgba(255,255,255,0.4);
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 8px 6px;
            position: relative;
            overflow: hidden;
            cursor: default; /* Not clickable */
        }
        
        .booking-bar:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.2);
            z-index: 20;
        }
        
        .booking-bar .guest-name {
            font-weight: 700;
            font-size: 12px;
            line-height: 1.2;
            margin-bottom: 3px;
            text-shadow: 0 1px 2px rgba(0,0,0,0.3);
        }
        
        .booking-bar .guest-contact {
            font-size: 10px;
            line-height: 1.1;
            margin-bottom: 2px;
            opacity: 0.95;
        }
        
        .booking-bar .meal-plan {
            font-size: 9px;
            line-height: 1.1;
            margin-bottom: 2px;
            opacity: 0.9;
            font-style: italic;
        }
        
        .booking-bar .guest-notes {
            font-size: 8px;
            line-height: 1.1;
            opacity: 0.85;
            font-style: italic;
        }
        

        
        /* Available room styles */
        .available-room {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            border-radius: 6px;
            transition: all 0.2s ease;
            cursor: pointer;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            height: 110px;
            font-weight: bold;
            font-size: 16px;
        }
        
        .available-room:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            transform: scale(1.05);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        
        .available-room .label {
            font-size: 24px;
            font-weight: 900;
        }
        
        .available-room .text {
            font-size: 10px;
            opacity: 0.9;
            margin-top: 2px;
        }
        
        /* Past date styles */
        .past-date {
            background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
            color: #6b7280;
            border-radius: 6px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            height: 110px;
            font-size: 12px;
        }
        
        /* Enhanced tooltip styles */
        .calendar-cell[data-tooltip]:hover::after,
        .merged-booking-bar[data-tooltip]:hover::after,
        .booked-portion[data-tooltip]:hover::after,
        .booking-bar[data-tooltip]:hover::after,
        .booking-bar-red[data-tooltip]:hover::after,
        .booking-bar-blue[data-tooltip]:hover::after,
        .booking-bar-green[data-tooltip]:hover::after,
        .booking-bar-purple[data-tooltip]:hover::after,
        .booking-bar-pink[data-tooltip]:hover::after,
        .booking-bar-indigo[data-tooltip]:hover::after,
        .booking-bar-yellow[data-tooltip]:hover::after,
        .booking-bar-orange[data-tooltip]:hover::after,
        .booking-bar-teal[data-tooltip]:hover::after,
        .booking-bar-cyan[data-tooltip]:hover::after,
        .booking-bar-emerald[data-tooltip]:hover::after,
        .booking-bar-rose[data-tooltip]:hover::after,
        .booking-bar-violet[data-tooltip]:hover::after,
        .booking-bar-amber[data-tooltip]:hover::after,
        .booking-bar-lime[data-tooltip]:hover::after,
        .booking-bar-sky[data-tooltip]:hover::after,
        .booking-bar-fuchsia[data-tooltip]:hover::after,
        .booking-bar-slate[data-tooltip]:hover::after,
        .booking-bar-gray[data-tooltip]:hover::after,
        .booking-bar-zinc[data-tooltip]:hover::after,
        .booking-middle[data-tooltip]:hover::after {
            content: attr(data-tooltip);
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0, 0, 0, 0.95);
            color: white;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 11px;
            line-height: 1.4;
            white-space: pre-line;
            z-index: 1000;
            max-width: 350px;
            min-width: 250px;
            word-wrap: break-word;
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(255,255,255,0.1);
            backdrop-filter: blur(5px);
            margin-top: 8px;
        }
        
        .calendar-cell[data-tooltip]:hover::before,
        .merged-booking-bar[data-tooltip]:hover::before,
        .booked-portion[data-tooltip]:hover::before,
        .booking-bar[data-tooltip]:hover::before,
        .booking-bar-red[data-tooltip]:hover::before,
        .booking-bar-blue[data-tooltip]:hover::before,
        .booking-bar-green[data-tooltip]:hover::before,
        .booking-bar-purple[data-tooltip]:hover::before,
        .booking-bar-pink[data-tooltip]:hover::before,
        .booking-bar-indigo[data-tooltip]:hover::before,
        .booking-bar-yellow[data-tooltip]:hover::before,
        .booking-bar-orange[data-tooltip]:hover::before,
        .booking-bar-teal[data-tooltip]:hover::before,
        .booking-bar-cyan[data-tooltip]:hover::before,
        .booking-bar-emerald[data-tooltip]:hover::before,
        .booking-bar-rose[data-tooltip]:hover::before,
        .booking-bar-violet[data-tooltip]:hover::before,
        .booking-bar-amber[data-tooltip]:hover::before,
        .booking-bar-lime[data-tooltip]:hover::before,
        .booking-bar-sky[data-tooltip]:hover::before,
        .booking-bar-fuchsia[data-tooltip]:hover::before,
        .booking-bar-slate[data-tooltip]:hover::before,
        .booking-bar-gray[data-tooltip]:hover::before,
        .booking-bar-zinc[data-tooltip]:hover::before,
        .booking-middle[data-tooltip]:hover::before {
            content: '';
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            border: 6px solid transparent;
            border-bottom-color: rgba(0, 0, 0, 0.95);
            z-index: 1001;
            margin-top: 2px;
        }
        
        /* Fixed table layout for consistent cell sizing */
        .calendar-table {
            table-layout: fixed;
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        
        .calendar-table th,
        .calendar-table td {
            width: 140px;
            max-width: 140px;
            min-width: 140px;
        }
        
        /* Fixed room column (first column) - yellow background */
        .calendar-table th:first-child,
        .calendar-table td:first-child {
            width: 200px;
            max-width: 200px;
            min-width: 200px;
            position: sticky;
            left: 0;
            z-index: 10;
            background-color: #fef3c7; /* Light yellow background */
            border-right: 2px solid #f59e0b; /* Amber border */
        }
        
        /* Ensure room column header stays on top - yellow background */
        .calendar-table thead th:first-child {
            z-index: 20;
            background-color: #fde68a; /* Slightly darker yellow for header */
        }
        
        /* Sticky header for date row - absolutely fixed */
        .calendar-table thead {
            position: sticky;
            top: 0;
            z-index: 30;
            background-color: #f9fafb;
            transform: translateZ(0); /* Force hardware acceleration */
            will-change: transform; /* Optimize for animations */
        }
        
        /* Ensure sticky header has proper background and no movement */
        .calendar-table thead th {
            background-color: #f9fafb !important;
            border-bottom: 2px solid #e5e7eb;
            position: sticky;
            top: 0;
            z-index: 30;
        }
        
        /* Add shadow effect to sticky header */
        .calendar-table thead::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(to bottom, rgba(0,0,0,0.1), transparent);
            pointer-events: none;
            z-index: 29;
        }
        
        /* Ensure the table container doesn't cause movement */
        .calendar-table {
            position: relative;
        }
        
        /* Prevent any movement in the sticky header */
        .calendar-table thead tr {
            position: sticky;
            top: 0;
            z-index: 30;
            background-color: #f9fafb;
        }
        
        /* Additional fixes to prevent header movement */
        .calendar-table thead,
        .calendar-table thead tr,
        .calendar-table thead th {
            position: sticky !important;
            top: 0 !important;
            z-index: 30 !important;
        }
        
        /* Ensure the scroll container doesn't interfere */
        .overflow-y-auto {
            position: relative;
            transform: translateZ(0);
        }
        
        /* Category header row - proper styling to prevent overlap */
        .calendar-table .category-header-row {
            background-color:rgb(250, 250, 250); /* Light yellow background for category headers */
        }
        
        /* Category header left cell - ensure proper visibility */
        .calendar-table .category-left-cell {
            position: sticky;
            left: 0;
            z-index: 15;
            background-color:rgb(250, 250, 250) !important; /* Light yellow background */
            border-right: 2px solid #f59e0b; /* Amber border */
        }
        
        /* Category header right cell - clean background */
        .calendar-table .category-right-cell {
            background-color:rgb(255, 255, 255); /* Light yellow background */
        }
        
        /* Add shadow effect to fixed column */
        .calendar-table th:first-child::after,
        .calendar-table td:first-child::after {
            content: '';
            position: absolute;
            top: 0;
            right: -5px;
            width: 5px;
            height: 100%;
            background: linear-gradient(to right, rgba(0,0,0,0.1), transparent);
            pointer-events: none;
        }
        
        /* Ensure proper background for sticky elements */
        .calendar-table th:first-child,
        .calendar-table td:first-child {
            background-color: white !important;
        }
        
        .calendar-table thead th:first-child {
            background-color: #f9fafb !important;
        }
        
        .calendar-table tr.bg-gray-100 td:first-child {
            background-color: #f3f4f6 !important;
        }
        

        
        /* Responsive design */
        @media (max-width: 768px) {
            .calendar-table th,
            .calendar-table td {
                width: 120px;
                max-width: 120px;
                min-width: 120px;
            }
            
            .calendar-table th:first-child,
            .calendar-table td:first-child {
                width: 150px;
                max-width: 150px;
                min-width: 150px;
                position: sticky;
                left: 0;
                z-index: 10;
                background-color: white;
                border-right: 2px solid #e5e7eb;
            }
            
            .calendar-table thead th:first-child {
                z-index: 20;
                background-color: #f9fafb;
            }
            
            .calendar-table tr.bg-gray-100 td {
                position: sticky;
                left: 0;
                z-index: 15;
                background-color: #f3f4f6;
            }
            
            /* Add shadow effect to fixed column on mobile */
            .calendar-table th:first-child::after,
            .calendar-table td:first-child::after {
                content: '';
                position: absolute;
                top: 0;
                right: -3px;
                width: 3px;
                height: 100%;
                background: linear-gradient(to right, rgba(0,0,0,0.1), transparent);
                pointer-events: none;
            }
            
            /* Ensure proper background for sticky elements on mobile - yellow theme */
            .calendar-table th:first-child,
            .calendar-table td:first-child {
                background-color: #fef3c7 !important; /* Light yellow background */
            }
            
            .calendar-table thead th:first-child {
                background-color: #fde68a !important; /* Slightly darker yellow for header */
            }
            
            .calendar-table .category-left-cell {
                background-color: #fef3c7 !important; /* Light yellow background */
                border-right: 2px solid #f59e0b; /* Amber border */
            }
            
            .calendar-table .category-right-cell {
                background-color: #fef3c7 !important; /* Light yellow background */
            }
            
            /* Sticky header for mobile - absolutely fixed */
            .calendar-table thead {
                position: sticky;
                top: 0;
                z-index: 30;
                background-color: #f9fafb;
                transform: translateZ(0); /* Force hardware acceleration */
                will-change: transform; /* Optimize for animations */
            }
            
            .calendar-table thead th {
                background-color: #f9fafb !important;
                border-bottom: 2px solid #e5e7eb;
                position: sticky;
                top: 0;
                z-index: 30;
            }
            
            .calendar-table thead::after {
                content: '';
                position: absolute;
                bottom: -3px;
                left: 0;
                right: 0;
                height: 3px;
                background: linear-gradient(to bottom, rgba(0,0,0,0.1), transparent);
                pointer-events: none;
                z-index: 29;
            }
            
            /* Prevent any movement in the sticky header on mobile */
            .calendar-table thead tr {
                position: sticky;
                top: 0;
                z-index: 30;
                background-color: #f9fafb;
            }
            

            
            .calendar-cell {
                height: 80px;
                padding: 1px;
            }
            
            .booking-bar {
                min-height: 70px;
                padding: 4px 3px;
            }
            
            .merged-booking-bar {
                min-height: 70px;
            }
            
            .day-portions {
                min-height: 70px;
            }
            
            .booking-bar .guest-name {
                font-size: 10px;
            }
            
            .booking-bar .guest-contact {
                font-size: 8px;
            }
            
            .booking-bar .meal-plan {
                font-size: 7px;
            }
            
            .booking-bar .guest-notes {
                font-size: 6px;
            }
            
            .available-room {
                height: 70px;
            }
            

            
            .past-date {
                height: 70px;
            }
            
            .available-room .label {
                font-size: 14px;
            }
            
            .available-room .text {
                font-size: 8px;
            }
        }
        
        /* Colorful day headers styling */
        .calendar-table th {
            transition: all 0.3s ease;
            position: relative;
        }
        
        .calendar-table th:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        /* Day header color variations */
        .calendar-table th[class*="bg-red-100"] {
            background: linear-gradient(135deg, #fef2f2 0%, #fecaca 100%);
        }
        
        .calendar-table th[class*="bg-blue-100"] {
            background: linear-gradient(135deg, #eff6ff 0%, #bfdbfe 100%);
        }
        
        .calendar-table th[class*="bg-green-100"] {
            background: linear-gradient(135deg, #f0fdf4 0%, #bbf7d0 100%);
        }
        
        .calendar-table th[class*="bg-purple-100"] {
            background: linear-gradient(135deg, #faf5ff 0%, #c4b5fd 100%);
        }
        
        .calendar-table th[class*="bg-orange-100"] {
            background: linear-gradient(135deg, #fff7ed 0%, #fed7aa 100%);
        }
        
        .calendar-table th[class*="bg-pink-100"] {
            background: linear-gradient(135deg, #fdf2f8 0%, #f9a8d4 100%);
        }
        
        .calendar-table th[class*="bg-indigo-100"] {
            background: linear-gradient(135deg, #eef2ff 0%, #a5b4fc 100%);
        }
        
        /* Today's special styling */
        .calendar-table th[class*="bg-yellow-200"] {
            background: linear-gradient(135deg, #fefce8 0%, #fde047 100%);
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.8; }
        }
        
        /* Alternating row backgrounds for better readability */
        .room-row:nth-child(even) {
            background-color: #f8fafc;
        }
        
        .room-row:hover {
            background-color: #f1f5f9;
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Sidebar -->
    <div class="fixed inset-y-0 left-0 w-64 sidebar shadow-lg">
        <div class="flex items-center justify-center h-16 bg-white bg-opacity-10 p-4">
            <div class="flex items-center space-x-3">
                <?php if (!empty($hotel['logo'])): ?>
                    <img src="<?php echo htmlspecialchars($hotel['logo']); ?>" alt="<?php echo htmlspecialchars($hotel['name']); ?> Logo" class="h-10 w-auto max-w-16 object-contain">
                <?php else: ?>
                    <i class="fas fa-hotel text-2xl text-white"></i>
                <?php endif; ?>
                <h1 class="text-xl font-bold text-white"><?php echo htmlspecialchars($hotel['name']); ?></h1>
            </div>
        </div>
        
        <nav class="mt-8">
            <div class="px-4 mb-4">
                <p class="text-white opacity-70 text-sm">Welcome, <?php echo htmlspecialchars($user['username']); ?></p>
                <p class="text-white opacity-50 text-xs"><?php echo ucfirst($user['role']); ?></p>
            </div>
            
            <a href="hotel_dashboard.php?hotel_id=<?php echo $hotel_id; ?>" class="flex items-center px-6 py-3 text-white hover:bg-white hover:bg-opacity-20 transition-colors">
                <i class="fas fa-tachometer-alt mr-3"></i>
                Dashboard
            </a>
            
            <a href="calendar.php?hotel_id=<?php echo $hotel_id; ?>" class="flex items-center px-6 py-3 text-white bg-white bg-opacity-20 border-r-4 border-white">
                <i class="fas fa-calendar-alt mr-3"></i>
                Calendar View
            </a>
            
            <a href="multiple_booking.php?hotel_id=<?php echo $hotel_id; ?>" class="flex items-center px-6 py-3 text-white hover:bg-white hover:bg-opacity-20 transition-colors">
                <i class="fas fa-bed mr-3"></i>
                Multiple Booking
            </a>
         
        
                
                <a href="manage_bookings.php?hotel_id=<?php echo $hotel_id; ?>" class="flex items-center px-6 py-3 text-white hover:bg-white hover:bg-opacity-20 transition-colors">
                <i class="fas fa-tags mr-3"></i>Manage Booking
            </a>
                
                
            <a href="pricing.php?hotel_id=<?php echo $hotel_id; ?>" class="flex items-center px-6 py-3 text-white hover:bg-white hover:bg-opacity-20 transition-colors">
                <i class="fas fa-tags mr-3"></i>
                Pricing
            </a>
            <!-- Removed Search Bookings link -->
            <?php if ($user['role'] === 'admin'): ?>
            <div class="mt-8 border-t border-white border-opacity-20 pt-4">
                <p class="px-6 text-white opacity-70 text-sm mb-2">Admin Panel</p>
                <a href="manage_rooms.php?hotel_id=<?php echo $hotel_id; ?>" class="flex items-center px-6 py-3 text-white hover:bg-white hover:bg-opacity-20 transition-colors">
                    <i class="fas fa-bed mr-3"></i>
                    Manage Rooms
                </a>
                <a href="manage_bookings.php?hotel_id=<?php echo $hotel_id; ?>" class="flex items-center px-6 py-3 text-white hover:bg-white hover:bg-opacity-20 transition-colors">
                    <i class="fas fa-list mr-3"></i>
                    Manage Bookings
                </a>
            </div>
            <?php endif; ?>
            
            <div class="absolute bottom-4 left-0 right-0 px-6">
                <a href="logout.php" class="flex items-center px-4 py-2 text-white hover:bg-white hover:bg-opacity-20 rounded-lg transition-colors">
                    <i class="fas fa-sign-out-alt mr-3"></i>
                    Logout
                </a>
            </div>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="ml-64 p-8">
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-800 mb-2">Calendar View</h1>
            <p class="text-gray-600">View and manage room bookings</p>
        </div>

        <!-- Navigation -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <a href="?hotel_id=<?php echo $hotel_id; ?>&month=<?php echo $month-1; ?>&year=<?php echo $year; ?>" 
                       class="px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                    <h2 class="text-xl font-semibold text-gray-800">
                        <?php echo date('F Y', mktime(0, 0, 0, $month, 1, $year)); ?> (<?php echo format_display_date(date('Y-m-d', mktime(0, 0, 0, $month, 1, $year))); ?>)
                    </h2>
                    <a href="?hotel_id=<?php echo $hotel_id; ?>&month=<?php echo $month+1; ?>&year=<?php echo $year; ?>" 
                       class="px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </div>
                
                <div class="flex items-center space-x-4">
                    <div class="flex items-center space-x-2">
                        <div class="w-4 h-4 bg-green-500 rounded"></div>
                        <span class="text-sm text-gray-600">Available</span>
                    </div>
                    <div class="flex items-center space-x-2">
                        <div class="w-4 h-4 bg-red-500 rounded"></div>
                        <span class="text-sm text-gray-600">Booked</span>
                    </div>

                    <button onclick="toggleColorLegend()" class="px-3 py-1 bg-blue-100 hover:bg-blue-200 rounded text-sm text-blue-700 transition-colors">
                        <i class="fas fa-palette mr-1"></i>Color Legend
                    </button>
                </div>
            </div>
        </div>

        <!-- Calendar Matrix Table -->
        <div class="bg-white rounded-lg shadow-md">
            <div class="overflow-x-auto">
                <div class="overflow-y-auto" style="max-height: 70vh; position: relative;">
                    <table class="calendar-table">
                <thead class="bg-gray-50">

                    <!-- Header row: Room and Day names and dates -->
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Room</th>
                        <?php
                        for ($day = $start_day; $day <= $days_in_month; $day++) {
                            $date = date('Y-m-d', mktime(0, 0, 0, $month, $day, $year));
                            $is_today = $date === date('Y-m-d');
                            $day_name = date('D', strtotime($date));
                            $day_number = $day;
                            $is_weekend = in_array(date('N', strtotime($date)), [6, 7]);
                            
                            // Colorful day headers based on day of week
                            $day_colors = [
                                'Sun' => 'bg-red-100 text-red-800 border-red-200',
                                'Mon' => 'bg-blue-100 text-blue-800 border-blue-200',
                                'Tue' => 'bg-green-100 text-green-800 border-green-200',
                                'Wed' => 'bg-purple-100 text-purple-800 border-purple-200',
                                'Thu' => 'bg-orange-100 text-orange-800 border-orange-200',
                                'Fri' => 'bg-pink-100 text-pink-800 border-pink-200',
                                'Sat' => 'bg-indigo-100 text-indigo-800 border-indigo-200'
                            ];
                            
                            $color_class = $day_colors[$day_name] ?? 'bg-gray-100 text-gray-800 border-gray-200';
                            
                            // Add special styling for today
                            if ($is_today) {
                                $color_class = 'bg-yellow-200 text-yellow-900 border-yellow-300 shadow-md';
                            }
                            
                            echo '<th class="px-2 py-2 text-center text-xs font-medium uppercase tracking-wider border-2 ' . $color_class . '">';
                            echo '<div class="text-sm font-bold">' . $day_name . ' ' . $day_number . '</div>';
                            echo '</th>';
                        }
                        ?>
                    </tr>


                </thead>
                <tbody>
                    <?php foreach ($categories as $category): ?>
                        <!-- Category Header -->
                        <tr class="category-header-row">
                            <td class="px-4 py-2 category-left-cell">
                                <div class="flex items-center justify-between">
                                    <h3 class="text-lg font-bold text-gray-900">
                                        <?php echo htmlspecialchars($category_data[$category]['name']); ?>
                                        <?php if ($category_data[$category]['price'] > 0): ?>
                                            <span class="text-base font-semibold text-amber-700">(BDT <?php echo number_format($category_data[$category]['price']); ?>)</span>
                                        <?php endif; ?>
                                    </h3>
                                    <button onclick="toggleCategory('<?php echo $category; ?>')" class="text-gray-600 hover:text-gray-800">
                                        <i class="fas fa-chevron-down" id="icon-<?php echo $category; ?>"></i>
                                    </button>
                                </div>
                            </td>
                            <td colspan="<?php echo ($days_in_month - $start_day + 1); ?>" class="px-4 py-2 category-right-cell">
                                <div class="flex items-center justify-end">
                                    <button onclick="toggleCategory('<?php echo $category; ?>')" class="text-gray-600 hover:text-gray-800">
                                        <i class="fas fa-chevron-down" id="icon-<?php echo $category; ?>"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        
                        <!-- Rooms in this category -->
                        <tbody id="category-<?php echo $category; ?>" class="category-rooms">
                            <?php foreach ($rooms_by_cat[$category] as $room): ?>
                            <tr class="room-row">
                                <td class="px-4 py-2 whitespace-nowrap">
                                    <div class="font-bold text-gray-900 text-lg"><?php echo htmlspecialchars($room['room_number']); ?></div>
                                  
                                </td>
                                <?php
                                // Render horizontal booking bars for this room
                                $room_id = $room['id'];
                                $current_day = $start_day;
                                
                                // Get all bookings for this room in the current month
                                $room_bookings = isset($booking_spans[$room_id]) ? $booking_spans[$room_id] : [];
                                
                                // Sort bookings by start date
                                usort($room_bookings, function($a, $b) {
                                    return strcmp($a['start_date'], $b['start_date']);
                                });
                                
                                // Process each day in the month with invisible two-portion system
                                while ($current_day <= $days_in_month) {
                                    $date = date('Y-m-d', mktime(0, 0, 0, $month, $current_day, $year));
                                    $is_past = $date < date('Y-m-d');
                                    
                                    // Find booking activity for left and right portions
                                    $left_portion_booking = null;
                                    $right_portion_booking = null;
                                    $left_portion_type = 'available';
                                    $right_portion_type = 'available';
                                    
                                    // Check for bookings that affect this day
                                    foreach ($room_bookings as $span) {
                                        $span_start_date = new DateTime($span['start_date']);
                                        $span_end_date = new DateTime($span['end_date']);
                                        $current_date = new DateTime($date);
                                        
                                        // Check if this day is part of this booking span
                                        if ($span_start_date->format('Y-m-d') === $current_date->format('Y-m-d')) {
                                            // Check-in day: right portion is booked (after 11 AM)
                                            if ($right_portion_type === 'available') {
                                                $right_portion_booking = $span['booking'];
                                                $right_portion_type = 'booked';
                                            }
                                        } elseif ($span_end_date->format('Y-m-d') === $current_date->format('Y-m-d')) {
                                            // Check-out day: left portion is booked (until 10:50 AM)
                                            if ($left_portion_type === 'available') {
                                                $left_portion_booking = $span['booking'];
                                                $left_portion_type = 'booked';
                                            }
                                        } elseif ($span_start_date < $current_date && $span_end_date > $current_date) {
                                            // Full booking day: both portions are booked
                                            if ($left_portion_type === 'available') {
                                                $left_portion_booking = $span['booking'];
                                                $left_portion_type = 'booked';
                                            }
                                            if ($right_portion_type === 'available') {
                                                $right_portion_booking = $span['booking'];
                                                $right_portion_type = 'booked';
                                            }
                                        }
                                    }
                                    
                                    // Note: Removed special case logic to allow split portions for consecutive bookings
                                    // Now when different guests have check-out and check-in on the same day,
                                    // it will show as split portions (left: check-out, right: check-in)
                                    
                                    // Check for single-day bookings
                                    foreach ($room_bookings as $span) {
                                        $span_start_date = new DateTime($span['start_date']);
                                        $span_end_date = new DateTime($span['end_date']);
                                        $current_date = new DateTime($date);
                                        
                                        if ($span_start_date->format('Y-m-d') === $current_date->format('Y-m-d') && 
                                            $span_end_date->format('Y-m-d') === $current_date->format('Y-m-d')) {
                                            // Single day booking: both portions are booked (check-in and check-out on same day)
                                            if ($left_portion_type === 'available') {
                                                $left_portion_booking = $span['booking'];
                                                $left_portion_type = 'booked';
                                            }
                                            if ($right_portion_type === 'available') {
                                                $right_portion_booking = $span['booking'];
                                                $right_portion_type = 'booked';
                                            }
                                        }
                                    }
                                    
                                    if ($is_past) {
                                        // Past date: show expired for free rooms
                                        if ($left_portion_type === 'available' && $right_portion_type === 'available') {
                                            // Free room on past date: show expired
                                            $day_name = date('D', strtotime($date));
                                            $day_number = date('j', strtotime($date));
                                            echo '<td class="calendar-cell px-0 py-1">';
                                            echo '<div class="expired-room">';
                                            echo '<div class="text-xs text-gray-500 mb-1">' . $day_name . ' ' . $day_number . '</div>';
                                            echo '<div class="text-2xl font-bold text-red-600">E</div>';
                                            echo '<div class="text-xs text-red-700">Expired</div>';
                                            echo '</div>';
                                            echo '</td>';
                                        } else {
                                            // Past date with booking: show booking as normal
                                            if ($left_portion_type === 'booked' && $right_portion_type === 'booked' && 
                                                $left_portion_booking['id'] === $right_portion_booking['id']) {
                                                // Full booking day: show as single merged booking bar (same guest)
                                                $booking = $left_portion_booking;
                                                $booking_color_class = getBookingColorClass($booking['guest_name']);
                                                $tooltip = "Guest: " . htmlspecialchars($booking['guest_name']) . "\n";
                                                $tooltip .= "Phone: " . htmlspecialchars($booking['guest_contact']) . "\n";
                                                $tooltip .= "Date: " . $date . " (Past)\n";
                                                if ($booking['meals_display']) {
                                                    $tooltip .= "Meals: " . htmlspecialchars($booking['meals_display']) . "\n";
                                                }
                                                if ($booking['note']) {
                                                    $tooltip .= "Notes: " . htmlspecialchars($booking['note']);
                                                }
                                                
                                                $day_name = date('D', strtotime($date));
                                                $day_number = date('j', strtotime($date));
                                                echo '<td class="calendar-cell px-0 py-1">';
                                                // Enhanced tooltip with complete booking information
                                                $enhanced_tooltip = generateBookingTooltip($booking, $date);
                                                
                                                echo '<div class="merged-booking-bar booking-middle ' . $booking_color_class . '" data-tooltip="' . htmlspecialchars($enhanced_tooltip) . '">';
                                                echo '<div class="text-xs text-gray-500 mb-1">' . $day_name . ' ' . $day_number . '</div>';
                                                echo '<div class="guest-name">' . displayGuestName($booking) . '</div>';
                                                echo '<div class="guest-contact">' . htmlspecialchars($booking['guest_contact']) . '</div>';
                                                echo '</div>';
                                                echo '</td>';
                                            } else {
                                                // Show split portions for past date
                                                $day_name = date('D', strtotime($date));
                                                $day_number = date('j', strtotime($date));
                                                echo '<td class="calendar-cell px-0 py-1">';
                                                echo '<div class="day-portions">';
                                                echo '<div class="text-xs text-gray-500 mb-1 text-center">' . $day_name . ' ' . $day_number . '</div>';
                                                
                                                // Left portion
                                                echo '<div class="left-portion">';
                                                if ($left_portion_type === 'available') {
                                                    echo '<div class="available-room">';
                                                    echo '<div class="text-lg font-bold text-green-600">A</div>';
                                                    echo '<div class="text-xs text-green-700">Until 10:50 AM</div>';
                                                    echo '</div>';
                                                } else {
                                                    // Left portion is booked
                                                    $booking = $left_portion_booking;
                                                    $booking_color_class = getBookingColorClass($booking['guest_name']);
                                                    $tooltip = generateBookingTooltip($booking, $date, true);
                                                    
                                                    echo '<div class="booked-portion booking-end ' . $booking_color_class . '" data-tooltip="' . htmlspecialchars($tooltip) . '">';
                                                    echo '<div class="guest-name">' . displayGuestName($booking) . '</div>';
                                                    echo '<div class="guest-contact">Until 10:50 AM</div>';
                                                    if ($booking['meals_display']) {
                                                        echo '<div class="meal-plan">' . htmlspecialchars($booking['meals_display']) . '</div>';
                                                    }
                                                    echo '</div>';
                                                }
                                                echo '</div>';
                                                
                                                // Right portion
                                                echo '<div class="right-portion">';
                                                if ($right_portion_type === 'available') {
                                                    echo '<div class="available-room">';
                                                    echo '<div class="text-lg font-bold text-green-600">A</div>';
                                                    echo '<div class="text-xs text-green-700">After 11 AM</div>';
                                                    echo '</div>';
                                                } else {
                                                    // Right portion is booked
                                                    $booking = $right_portion_booking;
                                                    $booking_color_class = getBookingColorClass($booking['guest_name']);
                                                    $tooltip = generateBookingTooltip($booking, $date);
                                                    
                                                    echo '<div class="booked-portion booking-start ' . $booking_color_class . '" data-tooltip="' . htmlspecialchars($tooltip) . '">';
                                                    echo '<div class="guest-name">' . displayGuestName($booking) . '</div>';
                                                    echo '<div class="guest-contact">' . htmlspecialchars($booking['guest_contact']) . '</div>';
                                                    echo '</div>';
                                                }
                                                echo '</div>';
                                                
                                                echo '</div>'; // End day-portions
                                                echo '</td>';
                                            }
                                        }
                                        $current_day++;
                                    } else {
                                        // Current or future date: show two portions or full booking
                                        if ($left_portion_type === 'booked' && $right_portion_type === 'booked' && 
                                            $left_portion_booking['id'] === $right_portion_booking['id']) {
                                            // Full booking day: show as single merged booking bar (same guest)
                                            $booking = $left_portion_booking;
                                            $booking_color_class = getBookingColorClass($booking['guest_name']);
                                            $tooltip = generateBookingTooltip($booking, $date);
                                            
                                            $day_name = date('D', strtotime($date));
                                            $day_number = date('j', strtotime($date));
                                            echo '<td class="calendar-cell px-0 py-1">';
                                            echo '<div class="merged-booking-bar booking-middle ' . $booking_color_class . '" data-tooltip="' . htmlspecialchars($tooltip) . '">';
                                            echo '<div class="text-xs text-gray-500 mb-1">' . $day_name . ' ' . $day_number . '</div>';
                                            echo '<div class="guest-name">' . displayGuestName($booking) . '</div>';
                                            echo '<div class="guest-contact">' . htmlspecialchars($booking['guest_contact']) . '</div>';
                                            echo '</div>';
                                            echo '</td>';
                                        } else {
                                            // Show split portions (either one available, or both booked by different guests)
                                            // Show two separate portions
                                            $day_name = date('D', strtotime($date));
                                            $day_number = date('j', strtotime($date));
                                            echo '<td class="calendar-cell px-0 py-1">';
                                            echo '<div class="day-portions">';
                                            echo '<div class="text-xs text-gray-500 mb-1 text-center">' . $day_name . ' ' . $day_number . '</div>';
                                            
                                            // Left portion
                                            echo '<div class="left-portion">';
                                            if ($left_portion_type === 'available') {
                                                echo '<div class="available-portion" onclick="bookRoom(' . $room['id'] . ', \'' . $date . '\', \'' . addslashes($room['room_number']) . '\')">';
                                                echo '<div class="label">A</div>';
                                                echo '<div class="text">Until 10:50 AM</div>';
                                        echo '</div>';
                                                                                    } else {
                                            // Left portion is booked
                                            $booking = $left_portion_booking;
                                            $booking_color_class = getBookingColorClass($booking['guest_name']);
                                            $tooltip = generateBookingTooltip($booking, $date, true);
                                            
                                            echo '<div class="booked-portion booking-end ' . $booking_color_class . '" data-tooltip="' . htmlspecialchars($tooltip) . '">';
                                            echo '<div class="guest-name">' . displayGuestName($booking) . '</div>';
                                            echo '<div class="guest-contact">Until 10:50 AM</div>';
                                            echo '</div>';
                                        }
                                            echo '</div>';
                                            
                                            // Right portion
                                            echo '<div class="right-portion">';
                                            if ($right_portion_type === 'available') {
                                                echo '<div class="available-portion" onclick="bookRoom(' . $room['id'] . ', \'' . $date . '\', \'' . addslashes($room['room_number']) . '\')">';
                                                echo '<div class="label">A</div>';
                                                echo '<div class="text">After 11 AM</div>';
                                                echo '</div>';
                                            } else {
                                            // Right portion is booked
                                            $booking = $right_portion_booking;
                                            $booking_color_class = getBookingColorClass($booking['guest_name']);
                                            $tooltip = generateBookingTooltip($booking, $date);
                                            
                                            echo '<div class="booked-portion booking-start ' . $booking_color_class . '" data-tooltip="' . htmlspecialchars($tooltip) . '">';
                                            echo '<div class="guest-name">' . displayGuestName($booking) . '</div>';
                                            echo '<div class="guest-contact">' . htmlspecialchars($booking['guest_contact']) . '</div>';
                                            echo '</div>';
                                        }
                                            echo '</div>';
                                            
                                            echo '</div>'; // End day-portions
                                                echo '</td>';
                                            }
                                            $current_day++;
                                        }
                                }
                                ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    <?php endforeach; ?>
                </tbody>
            </table>
                </div>
            </div>
        </div>

        <!-- Maintenance Rooms Section -->
        <?php if (!empty($maintenance_rooms)): ?>
        <div class="mt-8 bg-white p-6 rounded-lg shadow">
            <h2 class="text-xl font-semibold mb-4 text-gray-800 flex items-center">
                <i class="fas fa-tools mr-2 text-red-600"></i>
                Maintenance Rooms
            </h2>
            <div class="overflow-x-auto">
                <table class="w-full border-collapse border border-gray-300">
                    <thead class="bg-gray-800 text-white">
                        <tr>
                            <th class="border border-gray-300 px-4 py-2 text-left">Room Number</th>
                            <th class="border border-gray-300 px-4 py-2 text-left">Category</th>
                            <th class="border border-gray-300 px-4 py-2 text-left">Description</th>
                            <th class="border border-gray-300 px-4 py-2 text-left">Price (BDT)</th>
                            <th class="border border-gray-300 px-4 py-2 text-left">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($maintenance_rooms as $room): ?>
                            <tr class="bg-black text-white hover:bg-gray-900">
                                <td class="border border-gray-600 px-4 py-2 font-bold"><?php echo htmlspecialchars($room['room_number']); ?></td>
                                <td class="border border-gray-600 px-4 py-2"><?php echo htmlspecialchars($room['category']); ?></td>
                                <td class="border border-gray-600 px-4 py-2"><?php echo htmlspecialchars($room['description'] ?? ''); ?></td>
                                <td class="border border-gray-600 px-4 py-2">৳<?php echo number_format($room['price'], 2); ?></td>
                                <td class="border border-gray-600 px-4 py-2">
                                    <span class="px-2 py-1 text-xs rounded bg-red-600 text-white">
                                        <i class="fas fa-tools mr-1"></i>Maintenance
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

    <!-- Booking Modal -->
    <div id="bookingModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-screen overflow-y-auto">
                <div class="p-6 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-800">New Booking</h3>
                        <button onclick="closeBookingModal()" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>
                </div>
                
                <form id="bookingForm" class="p-6">
                    <input type="hidden" id="roomId" name="room_id">
                    <input type="hidden" id="checkinDate" name="checkin_date">
                    <input type="hidden" name="hotel_id" value="<?php echo $hotel_id; ?>">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Room Number</label>
                            <input type="text" id="roomNumber" readonly class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50">
                        </div>
                        <div class="md:col-span-2">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Check-in Date</label>
                                    <input type="date" id="checkinDateInput" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Check-in Time</label>
                                    <input type="time" name="checkin_time" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                </div>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Check-out Date</label>
                                    <input type="date" name="checkout_date" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                    <div id="availabilityStatus" class="mt-2 text-sm"></div>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Check-out Time</label>
                                    <input type="time" name="checkout_time" value="11:00" readonly class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50">
                                    <small class="text-gray-500">Fixed at 11:00 AM</small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Meal Add-ons Section -->
                        <div class="md:col-span-2">
                            <h4 class="text-lg font-medium text-gray-800 mb-4">Meal Add-ons (Optional)</h4>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <!-- Breakfast -->
                                <div class="border border-gray-200 rounded-lg p-4">
                                    <div class="flex items-center mb-3">
                                        <input type="checkbox" id="breakfast_checkbox" name="breakfast_enabled" class="mr-2">
                                        <label for="breakfast_checkbox" class="font-medium text-gray-700">Breakfast</label>
                                    </div>
                                    <div class="space-y-2">
                                        <div>
                                            <label class="block text-sm text-gray-600">Price (BDT) - Optional</label>
                                            <input type="number" id="breakfast_price" name="breakfast_price" min="0" step="0.01" value="0" placeholder="0.00 (Free)" class="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                                        </div>
                                        <div>
                                            <label class="block text-sm text-gray-600">Quantity</label>
                                            <input type="number" id="breakfast_quantity" name="breakfast_quantity" min="0" value="0" class="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                                        </div>
                                        <div class="bg-gray-50 rounded p-2">
                                            <small class="text-gray-600 font-medium">
                                                <span id="breakfast_status" class="text-blue-600">Not selected</span>
                                                <br>
                                                <span id="breakfast_total" class="text-green-600">BDT 0.00</span>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Lunch -->
                                <div class="border border-gray-200 rounded-lg p-4">
                                    <div class="flex items-center mb-3">
                                        <input type="checkbox" id="lunch_checkbox" name="lunch_enabled" class="mr-2">
                                        <label for="lunch_checkbox" class="font-medium text-gray-700">Lunch</label>
                                    </div>
                                    <div class="space-y-2">
                                        <div>
                                            <label class="block text-sm text-gray-600">Price (BDT) - Optional</label>
                                            <input type="number" id="lunch_price" name="lunch_price" min="0" step="0.01" value="0" placeholder="0.00 (Free)" class="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                                        </div>
                                        <div>
                                            <label class="block text-sm text-gray-600">Quantity</label>
                                            <input type="number" id="lunch_quantity" name="lunch_quantity" min="0" value="0" class="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                                        </div>
                                        <div class="bg-gray-50 rounded p-2">
                                            <small class="text-gray-600 font-medium">
                                                <span id="lunch_status" class="text-blue-600">Not selected</span>
                                                <br>
                                                <span id="lunch_total" class="text-green-600">BDT 0.00</span>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Dinner -->
                                <div class="border border-gray-200 rounded-lg p-4">
                                    <div class="flex items-center mb-3">
                                        <input type="checkbox" id="dinner_checkbox" name="dinner_enabled" class="mr-2">
                                        <label for="dinner_checkbox" class="font-medium text-gray-700">Dinner</label>
                                    </div>
                                    <div class="space-y-2">
                                        <div>
                                            <label class="block text-sm text-gray-600">Price (BDT) - Optional</label>
                                            <input type="number" id="dinner_price" name="dinner_price" min="0" step="0.01" value="0" placeholder="0.00 (Free)" class="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                                        </div>
                                        <div>
                                            <label class="block text-sm text-gray-600">Quantity</label>
                                            <input type="number" id="dinner_quantity" name="dinner_quantity" min="0" value="0" class="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                                        </div>
                                        <div class="bg-gray-50 rounded p-2">
                                            <small class="text-gray-600 font-medium">
                                                <span id="dinner_status" class="text-blue-600">Not selected</span>
                                                <br>
                                                <span id="dinner_total" class="text-green-600">BDT 0.00</span>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-4">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Total Meal Add-ons</label>
                                <input type="text" id="meal_total" name="meal_total" readonly class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50" value="BDT 0.00">
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Total Amount (BDT)</label>
                            <input type="number" id="totalAmount" name="total_amount" readonly class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Discount Amount (BDT)</label>
                            <input type="number" id="discountPercent" name="discount" min="0" step="0.01" value="0" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Paid Amount (BDT)</label>
                            <input type="number" id="paidAmount" name="paid" min="0" step="0.01" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Due Amount (BDT)</label>
                            <input type="number" id="dueAmount" name="due" readonly class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Number of Guests</label>
                            <input type="number" name="num_guests" min="1" max="10" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        </div>
                    </div>
                    
                    <div class="mt-6 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Guest Name</label>
                            <input type="text" name="guest_name" id="guest_name" placeholder="Search Guest Details with Phone Number" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            <div id="guest_search_result" class="mt-2 text-sm"></div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Reference (Optional)</label>
                                <input type="text" name="reference" placeholder="Reference number or code" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Note (Optional)</label>
                                <textarea name="note" placeholder="Additional notes or special requests" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></textarea>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">NID Number</label>
                                <input type="text" name="nid_number" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Profession</label>
                                <input type="text" name="profession" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Email</label>
                                <input type="email" name="email" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Phone Number</label>
                                <div class="flex space-x-2">
                                    <input type="tel" name="phone" required class="flex-1 px-3 py-2 border border-gray-300 rounded-lg">
                                    <button type="button" onclick="searchGuest()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                                        <i class="fas fa-search"></i> Search
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Address</label>
                            <input type="text" name="address" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        </div>
                        
                        <div class="mt-4">
                          <label class="block text-sm font-medium text-gray-700 mb-2">Booking Type</label>
                          <select name="booking_type" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            <option value="online">Online</option>
                            <option value="offline">Offline</option>
                          </select>
                        </div>
                    </div>
                    
                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" onclick="closeBookingModal()" class="px-4 py-2 text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">
                            Cancel
                        </button>
                        <button type="submit" class="px-6 py-2 bg-gray-400 text-white rounded-lg cursor-not-allowed transition-colors" disabled>
                            Confirm Booking
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Color Legend Modal -->
    <div id="colorLegendModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
      <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-4xl w-full max-h-screen overflow-y-auto">
          <div class="p-6 border-b border-gray-200 flex items-center justify-between">
            <h3 class="text-lg font-semibold text-gray-800">Booking Color Legend</h3>
            <button onclick="closeColorLegend()" class="text-gray-400 hover:text-gray-600">
              <i class="fas fa-times text-xl"></i>
            </button>
          </div>
          <div class="p-6" id="colorLegendContent">
            <!-- Color legend will be injected here -->
          </div>
          <div class="p-6 flex justify-end space-x-3 border-t">
            <button type="button" onclick="closeColorLegend()" class="px-4 py-2 text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">Close</button>
          </div>
            </div>
        </div>
    </div>

    <!-- Booking Summary Modal -->
    <div id="bookingSummaryModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
      <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-lg w-full max-h-screen overflow-y-auto">
          <div class="p-6 border-b border-gray-200 flex items-center justify-between">
            <h3 class="text-lg font-semibold text-gray-800">Booking Summary</h3>
            <button onclick="closeBookingSummaryModal()" class="text-gray-400 hover:text-gray-600">
              <i class="fas fa-times text-xl"></i>
            </button>
          </div>
          <div class="p-6" id="bookingSummaryContent">
            <!-- Booking details will be injected here -->
          </div>
          <div class="p-6 flex justify-end space-x-3 border-t">
            <button type="button" onclick="closeBookingSummaryModal()" class="px-4 py-2 text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">Close</button>
              </div>
        </div>
      </div>
    </div>

    <script>
        // Make sure roomsData is available for price lookup
        window.roomsData = <?php echo json_encode($rooms); ?>;
        
        // Category toggle functionality
        function toggleCategory(category) {
            const categoryBody = document.getElementById('category-' + category);
            const icon = document.getElementById('icon-' + category);
            
            if (categoryBody.classList.contains('collapsed')) {
                categoryBody.classList.remove('collapsed');
                icon.className = 'fas fa-chevron-down';
            } else {
                categoryBody.classList.add('collapsed');
                icon.className = 'fas fa-chevron-right';
            }
        }
        
        // Initialize all categories as expanded
        document.addEventListener('DOMContentLoaded', function() {
            const categories = <?php echo json_encode($categories); ?>;
            categories.forEach(function(category) {
                const categoryBody = document.getElementById('category-' + category);
                if (categoryBody) {
                    categoryBody.classList.remove('collapsed');
                }
            });
            
            // Add keyboard navigation for arrow keys
            document.addEventListener('keydown', function(e) {
                if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
                    e.preventDefault();
                    
                    const currentUrl = new URL(window.location.href);
                    const currentMonth = parseInt(currentUrl.searchParams.get('month')) || <?php echo date('n'); ?>;
                    const currentYear = parseInt(currentUrl.searchParams.get('year')) || <?php echo date('Y'); ?>;
                    
                    let newMonth = currentMonth;
                    let newYear = currentYear;
                    
                    if (e.key === 'ArrowLeft') {
                        // Previous month
                        newMonth--;
                        if (newMonth < 1) {
                            newMonth = 12;
                            newYear--;
                        }
                    } else if (e.key === 'ArrowRight') {
                        // Next month
                        newMonth++;
                        if (newMonth > 12) {
                            newMonth = 1;
                            newYear++;
                        }
                    }
                    
                    // Update URL and navigate
                    currentUrl.searchParams.set('month', newMonth);
                    currentUrl.searchParams.set('year', newYear);
                    window.location.href = currentUrl.toString();
                }
            });
        });
        // Calculate meal totals for individual meals with status display
        function calculateMealTotal(mealType) {
            const price = parseFloat(document.getElementById(mealType + '_price').value) || 0;
            const quantity = parseInt(document.getElementById(mealType + '_quantity').value) || 0;
            const total = price * quantity;
            
            // Update status and total displays
            const statusElement = document.getElementById(mealType + '_status');
            const totalElement = document.getElementById(mealType + '_total');
            
            if (quantity > 0) {
                if (price > 0) {
                    statusElement.textContent = `${quantity}x ${mealType.charAt(0).toUpperCase() + mealType.slice(1)} (৳${price.toFixed(2)} each)`;
                    statusElement.className = 'text-green-600';
                } else {
                    statusElement.textContent = `${quantity}x ${mealType.charAt(0).toUpperCase() + mealType.slice(1)} (FREE)`;
                    statusElement.className = 'text-blue-600';
                }
                totalElement.textContent = price > 0 ? `BDT ${total.toFixed(2)}` : 'FREE';
            } else {
                statusElement.textContent = 'Not selected';
                statusElement.className = 'text-gray-500';
                totalElement.textContent = 'BDT 0.00';
            }
            
            return total;
        }

        // Calculate total meal add-ons
        function calculateTotalMeals() {
            const breakfastTotal = calculateMealTotal('breakfast');
            const lunchTotal = calculateMealTotal('lunch');
            const dinnerTotal = calculateMealTotal('dinner');
            const mealTotal = breakfastTotal + lunchTotal + dinnerTotal;
            
            // Update total meal display
            const mealTotalElement = document.getElementById('meal_total');
            if (mealTotalElement) {
                mealTotalElement.value = mealTotal > 0 ? `BDT ${mealTotal.toFixed(2)}` : 'FREE';
            }
            
            return mealTotal;
        }

        // Calculate total amount and due based on check-in/out, price, discount, paid, and meals
        function checkAvailability() {
            const roomId = document.getElementById('roomId').value;
            const checkinDate = document.getElementById('checkinDateInput').value;
            const checkoutDate = document.querySelector('input[name="checkout_date"]').value;
            
            if (!roomId || !checkinDate || !checkoutDate) {
                return;
            }
            
            // Show loading indicator
            const availabilityStatus = document.getElementById('availabilityStatus');
            if (availabilityStatus) {
                availabilityStatus.innerHTML = '<div class="text-blue-600"><i class="fas fa-spinner fa-spin"></i> Checking availability...</div>';
                availabilityStatus.className = 'mt-2 text-sm';
            }
            
            fetch('check_availability.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    room_id: roomId,
                    checkin_date: checkinDate,
                    checkout_date: checkoutDate,
                    hotel_id: <?php echo $hotel_id; ?>
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (data.available) {
                        if (availabilityStatus) {
                            availabilityStatus.innerHTML = '<div class="text-green-600"><i class="fas fa-check-circle"></i> Room is available for the selected dates</div>';
                            availabilityStatus.className = 'mt-2 text-sm';
                        }
                        // Enable submit button
                        const submitBtn = document.querySelector('button[type="submit"]');
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.classList.remove('bg-gray-400', 'cursor-not-allowed');
                            submitBtn.classList.add('bg-blue-600', 'hover:bg-blue-700');
                        }
                    } else {
                        if (availabilityStatus) {
                            let conflictMessage = '<div class="text-red-600"><i class="fas fa-times-circle"></i> Room is not available for the selected dates</div>';
                            if (data.conflicts && data.conflicts.length > 0) {
                                conflictMessage += '<div class="mt-2 text-xs text-gray-600">Conflicting bookings:</div>';
                                data.conflicts.forEach(conflict => {
                                    conflictMessage += `<div class="text-xs text-gray-600">• ${conflict.guest_name}: ${conflict.checkin_date} to ${conflict.checkout_date}</div>`;
                                });
                            }
                            availabilityStatus.innerHTML = conflictMessage;
                            availabilityStatus.className = 'mt-2 text-sm';
                        }
                        // Disable submit button
                        const submitBtn = document.querySelector('button[type="submit"]');
                        if (submitBtn) {
                            submitBtn.disabled = true;
                            submitBtn.classList.remove('bg-blue-600', 'hover:bg-blue-700');
                            submitBtn.classList.add('bg-gray-400', 'cursor-not-allowed');
                        }
                    }
                } else {
                    if (availabilityStatus) {
                        availabilityStatus.innerHTML = '<div class="text-red-600"><i class="fas fa-exclamation-triangle"></i> Error checking availability</div>';
                        availabilityStatus.className = 'mt-2 text-sm';
                    }
                }
            })
            .catch(error => {
                console.error('Error checking availability:', error);
                if (availabilityStatus) {
                    availabilityStatus.innerHTML = '<div class="text-red-600"><i class="fas fa-exclamation-triangle"></i> Error checking availability</div>';
                    availabilityStatus.className = 'mt-2 text-sm';
                }
            });
        }

        function calculateTotalAndDue() {
            const checkin = document.getElementById('checkinDateInput').value;
            const checkout = document.querySelector('input[name="checkout_date"]').value;
            const discount = parseFloat(document.getElementById('discountPercent').value) || 0;
            const paid = parseFloat(document.getElementById('paidAmount').value) || 0;
            const roomPrice = window.currentRoomPrice || 0;
            let roomTotal = 0;
            
            if (checkin && checkout && roomPrice) {
                const d1 = new Date(checkin);
                const d2 = new Date(checkout);
                const nights = (d2 - d1) / (1000 * 60 * 60 * 24);
                if (nights > 0) {
                    roomTotal = roomPrice * nights;
                }
            }
            
            // Calculate meal total
            const mealTotal = calculateTotalMeals();
            
            // Total Amount = Room Prices + Meal Prices
            const totalAmount = roomTotal + mealTotal;
            
            // Amount to be Paid = Total Amount - Discount
            const finalTotal = totalAmount - discount;
            
            document.getElementById('totalAmount').value = finalTotal.toFixed(2);
            
            // Calculate due
            if (finalTotal > 0) {
                let due = finalTotal - paid;
                if (due < 0) due = 0;
                document.getElementById('dueAmount').value = due.toFixed(2);
            } else {
                document.getElementById('dueAmount').value = '';
            }
        }
        document.getElementById('checkinDateInput').addEventListener('change', function() {
            calculateTotalAndDue();
            checkAvailability();
        });
        
        document.querySelector('input[name="checkout_date"]').addEventListener('change', function() {
            calculateTotalAndDue();
            checkAvailability();
        });
        document.getElementById('discountPercent').addEventListener('input', calculateTotalAndDue);
        document.getElementById('paidAmount').addEventListener('input', calculateTotalAndDue);

        // Add event listeners for meal fields
        ['breakfast', 'lunch', 'dinner'].forEach(mealType => {
            document.getElementById(mealType + '_price').addEventListener('input', function() {
                calculateTotalAndDue();
                calculateTotalMeals();
            });
            document.getElementById(mealType + '_quantity').addEventListener('input', function() {
                calculateTotalAndDue();
                calculateTotalMeals();
            });
            
            // Handle checkbox changes
            document.getElementById(mealType + '_checkbox').addEventListener('change', function() {
                const priceInput = document.getElementById(mealType + '_price');
                const quantityInput = document.getElementById(mealType + '_quantity');
                
                if (this.checked) {
                    priceInput.disabled = false;
                    quantityInput.disabled = false;
                    priceInput.classList.remove('bg-gray-50');
                    quantityInput.classList.remove('bg-gray-50');
                } else {
                    priceInput.disabled = true;
                    quantityInput.disabled = true;
                    priceInput.classList.add('bg-gray-50');
                    quantityInput.classList.add('bg-gray-50');
                    priceInput.value = '0';
                    quantityInput.value = '0';
                    calculateTotalAndDue();
                    calculateTotalMeals();
                }
            });
        });

        function bookRoom(roomId, date, roomNumber) {
            document.getElementById('roomId').value = roomId;
            document.getElementById('checkinDate').value = date;
            document.getElementById('checkinDateInput').value = date;
            document.getElementById('roomNumber').value = roomNumber;
            document.getElementById('bookingModal').classList.remove('hidden');
            // Fetch room price from roomsData
            const room = window.roomsData ? window.roomsData.find(r => r.id == roomId) : null;
            window.currentRoomPrice = room ? parseFloat(room.price) : 0;
            
            // Initialize meal fields as disabled
            ['breakfast', 'lunch', 'dinner'].forEach(mealType => {
                const priceInput = document.getElementById(mealType + '_price');
                const quantityInput = document.getElementById(mealType + '_quantity');
                const checkbox = document.getElementById(mealType + '_checkbox');
                
                checkbox.checked = false;
                priceInput.disabled = true;
                quantityInput.disabled = true;
                priceInput.classList.add('bg-gray-50');
                quantityInput.classList.add('bg-gray-50');
                priceInput.value = '0';
                quantityInput.value = '0';
            });
            
            calculateTotalAndDue(); // Call calculateTotalAndDue to set initial values
            
            // Initialize meal status displays
            calculateTotalMeals();
            
            // Set default checkout date to next day and check availability
            const checkinDate = new Date(date);
            const checkoutDate = new Date(checkinDate);
            checkoutDate.setDate(checkoutDate.getDate() + 1);
            document.querySelector('input[name="checkout_date"]').value = checkoutDate.toISOString().split('T')[0];
            
            // Check availability after a short delay to ensure DOM is ready
            setTimeout(() => {
                checkAvailability();
            }, 100);
        }
        
        // Guest search functionality
        function searchGuest() {
            const phoneInput = document.querySelector('input[name="phone"]');
            const guestNameInput = document.getElementById('guest_name');
            const resultDiv = document.getElementById('guest_search_result');
            
            if (!phoneInput.value.trim()) {
                resultDiv.innerHTML = '<span class="text-red-500">Please enter a phone number first</span>';
                return;
            }
            
            const formData = new FormData();
            formData.append('phone', phoneInput.value.trim());
            
            resultDiv.innerHTML = '<span class="text-blue-500">Searching...</span>';
            
            fetch('php/search_guest.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.guest) {
                    // Auto-fill the form with guest details
                    guestNameInput.value = data.guest.name;
                    document.querySelector('input[name="nid_number"]').value = data.guest.nid || '';
                    document.querySelector('input[name="profession"]').value = data.guest.profession || '';
                    document.querySelector('input[name="email"]').value = data.guest.email || '';
                    document.querySelector('input[name="address"]').value = data.guest.address || '';
                    document.querySelector('input[name="num_guests"]').value = data.guest.no_of_guests || 1;
                    
                    // Debug: Log the guest data to console
                    console.log('Guest data received:', data.guest);
                    console.log('Address value:', data.guest.address);
                    
                    // Check if address field exists in DOM
                    const addressField = document.querySelector('input[name="address"]');
                    console.log('Address field found:', addressField);
                    if (addressField) {
                        console.log('Setting address value to:', data.guest.address);
                        addressField.value = data.guest.address || '';
                        console.log('Address field value after setting:', addressField.value);
                    } else {
                        console.error('Address field not found in DOM');
                    }
                    
                    resultDiv.innerHTML = '<span class="text-green-500">✓ Guest details loaded successfully!</span>';
                } else {
                    resultDiv.innerHTML = '<span class="text-orange-500">No existing guest found with this phone number. You can proceed with new guest details.</span>';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                resultDiv.innerHTML = '<span class="text-red-500">Error searching for guest. Please try again.</span>';
            });
        }
        
        function closeBookingModal() {
            document.getElementById('bookingModal').classList.add('hidden');
        }
        
        document.getElementById('bookingForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch('process_booking.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.text().then(text => {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('Invalid JSON response:', text);
                        throw new Error('Invalid response format from server');
                    }
                });
            })
            .then(data => {
                if (data.success) {
                    closeBookingModal();
                    showBookingSummary(data);
                    // Reload the page to update the calendar after 10 seconds
                    setTimeout(() => {
                        window.location.reload();
                    }, 10000);
                } else {
                    if (data.error_type === 'room_unavailable') {
                        alert('⚠️ ' + data.message + '\n\nPlease select different dates or choose another room.');
                    } else {
                        alert('Error: ' + data.message);
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('⚠️ Server error: ' + error.message + '\n\nPlease try again or contact support if the problem persists.');
            });
        });
        
        // Close modal when clicking outside
        document.getElementById('bookingModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeBookingModal();
            }
        });

        function showBookingSummary(data) {
            const modal = document.getElementById('bookingSummaryModal');
            const content = document.getElementById('bookingSummaryContent');
            content.innerHTML = `
              <div class="mb-4">
                <div class="font-bold text-lg text-indigo-700 mb-2">Booking #${data.booking_id}</div>
                <div><span class="font-semibold">Guest:</span> ${data.guest_name}</div>
                <div><span class="font-semibold">Room:</span> ${data.room_number} (${data.category})</div>
                <div><span class="font-semibold">Check-in:</span> ${data.checkin_date} ${data.checkin_time}</div>
                <div><span class="font-semibold">Check-out:</span> ${data.checkout_date} ${data.checkout_time}</div>
                <div><span class="font-semibold">Nights:</span> ${data.nights}</div>
                <div><span class="font-semibold">Total:</span> ৳${data.total.toFixed(2)}</div>
                <div><span class="font-semibold">Paid:</span> ৳${data.paid.toFixed(2)}</div>
                <div><span class="font-semibold">Due:</span> ৳${data.due.toFixed(2)}</div>
                <div><span class="font-semibold">Discount:</span> ${data.discount}%</div>
                <div><span class="font-semibold">Phone:</span> ${data.phone}</div>
                <div><span class="font-semibold">Email:</span> <span id="guestEmail">${data.email}</span></div>
                <div><span class="font-semibold">Address:</span> ${data.address}</div>
                ${data.reference ? `<div><span class="font-semibold">Reference:</span> ${data.reference}</div>` : ''}
                ${data.note ? `<div><span class="font-semibold">Note:</span> ${data.note}</div>` : ''}
                ${data.meal_total > 0 ? `
                  <div class="mt-3 p-3 bg-gray-50 rounded-lg">
                    <div class="font-semibold text-gray-800 mb-2">Meal Add-ons:</div>
                    ${data.breakfast_total > 0 ? `<div>Breakfast: ৳${data.breakfast_total.toFixed(2)}</div>` : ''}
                    ${data.lunch_total > 0 ? `<div>Lunch: ৳${data.lunch_total.toFixed(2)}</div>` : ''}
                    ${data.dinner_total > 0 ? `<div>Dinner: ৳${data.dinner_total.toFixed(2)}</div>` : ''}
                    <div class="font-semibold mt-2">Total Meals: ৳${data.meal_total.toFixed(2)}</div>
                  </div>
                ` : ''}
              </div>
              <div class="mt-4 text-right flex gap-2 justify-end">
                <a href="php/generate_booking_pdf.php?booking_id=${data.booking_id}&hotel_id=${window.currentHotelId}" target="_blank" id="generatePdfBtn" class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">Generate PDF</a>
                <button type="button" onclick="downloadPdf(${data.booking_id}, ${window.currentHotelId})" class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">Download PDF</button>
                <button type="button" id="emailPdfBtn" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">Email PDF</button>
              </div>
              <div id="emailPdfMsg" class="mt-2 text-sm"></div>
              <div id="autoCloseTimer" class="mt-2 text-center text-sm text-orange-600 font-medium">Auto-close in <span id="countdown">10</span> seconds</div>
            `;
            modal.classList.remove('hidden');
            
            // Auto-close timer (10 seconds) with countdown
            let timeLeft = 10;
            const countdownElement = document.getElementById('countdown');
            
            const updateCountdown = () => {
                countdownElement.textContent = timeLeft;
                if (timeLeft <= 0) {
                    closeBookingSummaryModal();
                    return;
                }
                timeLeft--;
                setTimeout(updateCountdown, 1000);
            };
            
            updateCountdown();
            
            let autoCloseTimer = setTimeout(() => {
                closeBookingSummaryModal();
            }, 10000);
            
            // Clear timer when modal is manually closed
            const clearAutoClose = () => {
                clearTimeout(autoCloseTimer);
            };
            
            // Close modal when clicking outside
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    clearAutoClose();
                    closeBookingSummaryModal();
                }
            });
            
            // Close modal when pressing Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    clearAutoClose();
                    closeBookingSummaryModal();
                }
            });
            
            document.getElementById('emailPdfBtn').onclick = function() {
                const bookingId = data.booking_id;
                const hotelId = window.currentHotelId;
                const guestEmail = data.email;
                const msgDiv = document.getElementById('emailPdfMsg');
                msgDiv.textContent = 'Sending email...';
                fetch('php/email_booking_pdf.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ booking_id: bookingId, hotel_id: hotelId, email: guestEmail })
                })
                .then(res => res.json())
                .then(res => {
                    if (res.success) {
                        msgDiv.textContent = 'PDF emailed successfully!';
                        msgDiv.className = 'mt-2 text-green-600 text-sm';
                    } else {
                        msgDiv.textContent = 'Failed to email PDF: ' + (res.message || 'Unknown error');
                        msgDiv.className = 'mt-2 text-red-600 text-sm';
                    }
                })
                .catch(() => {
                    msgDiv.textContent = 'Failed to email PDF (network error)';
                    msgDiv.className = 'mt-2 text-red-600 text-sm';
                });
            };
        }
        window.currentHotelId = <?php echo json_encode($hotel_id); ?>;
        
        function downloadPdf(bookingId, hotelId) {
            console.log('Downloading PDF for booking:', bookingId, 'hotel:', hotelId);
            const url = `php/generate_booking_pdf.php?booking_id=${bookingId}&hotel_id=${hotelId}`;
            console.log('PDF URL:', url);
            
            // Create a temporary link and trigger download
            const link = document.createElement('a');
            link.href = url;
            link.target = '_blank';
            link.download = `booking_${bookingId}.pdf`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
        
        function closeBookingSummaryModal() {
            const modal = document.getElementById('bookingSummaryModal');
            modal.classList.add('hidden');
            
            // Clear any existing auto-close timer
            if (window.autoCloseTimer) {
                clearTimeout(window.autoCloseTimer);
                window.autoCloseTimer = null;
            }
            
            // Remove event listeners to prevent memory leaks
            const modalClone = modal.cloneNode(true);
            modal.parentNode.replaceChild(modalClone, modal);
        }
        
        function toggleColorLegend() {
            const modal = document.getElementById('colorLegendModal');
            const content = document.getElementById('colorLegendContent');
            
            // Get current bookings data from PHP
            const bookingsData = <?php echo json_encode($bookings); ?>;
            
            // Create color legend content
            let legendHTML = '<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">';
            
            // Get unique guests
            const uniqueGuests = {};
            bookingsData.forEach(booking => {
                if (!uniqueGuests[booking.guest_name]) {
                    uniqueGuests[booking.guest_name] = booking;
                }
            });
            
            // Function to get color class (same as PHP)
            function getBookingColorClass(guestName) {
                const colors = [
                    'booking-red', 'booking-blue', 'booking-green', 'booking-purple', 'booking-pink',
                    'booking-indigo', 'booking-yellow', 'booking-orange', 'booking-teal', 'booking-cyan',
                    'booking-emerald', 'booking-rose', 'booking-violet', 'booking-amber', 'booking-lime',
                    'booking-sky', 'booking-fuchsia', 'booking-slate', 'booking-gray', 'booking-zinc'
                ];
                const colorIndex = Math.abs(guestName.split('').reduce((a, b) => a + b.charCodeAt(0), 0)) % colors.length;
                return colors[colorIndex];
            }
            
            // Create legend items
            Object.values(uniqueGuests).forEach(booking => {
                const colorClass = getBookingColorClass(booking.guest_name);
                const checkin = new Date(booking.checkin_date);
                const checkout = new Date(booking.checkout_date);
                
                legendHTML += `
                    <div class="flex items-center space-x-3 p-3 border border-gray-200 rounded-lg">
                        <div class="w-8 h-8 rounded ${colorClass}"></div>
                        <div class="flex-1">
                            <div class="font-semibold text-gray-800">${booking.guest_name}</div>
                            <div class="text-sm text-gray-600">Room ${booking.room_number}</div>
                            <div class="text-xs text-gray-500">${checkin.toLocaleDateString()} - ${checkout.toLocaleDateString()}</div>
                        </div>
                    </div>
                `;
            });
            
            legendHTML += '</div>';
            
            if (Object.keys(uniqueGuests).length === 0) {
                legendHTML = '<p class="text-gray-500 text-center py-8">No active bookings found for this month.</p>';
            }
            
            content.innerHTML = legendHTML;
            modal.classList.remove('hidden');
        }
        
        function closeColorLegend() {
            document.getElementById('colorLegendModal').classList.add('hidden');
        }
        
        document.getElementById('generatePdfBtn').onclick = function() {
            // Open the PDF in a new tab
            const bookingId = document.getElementById('bookingSummaryContent').querySelector('.font-bold').textContent.match(/#(\d+)/)[1];
            window.open('php/generate_booking_pdf.php?booking_id=' + bookingId + '&hotel_id=' + window.currentHotelId, '_blank');
            closeBookingSummaryModal();
            location.reload();
        };
    </script>
</body>
</html>