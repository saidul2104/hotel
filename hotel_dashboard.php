<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
require_once 'database/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Get hotel_id from URL (admin) or from manager's user record
$hotel_id = 0;
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

if (!$hotel) {
    echo '<h2 class="text-red-600">Hotel not found.</h2>';
    exit();
}

// Stats
$today = date('Y-m-d');
$month = date('Y-m');
$year = date('Y');

// Get check-ins today
$rooms_table = "rooms_hotel_{$hotel_id}";
$bookings_table = "bookings_hotel_{$hotel_id}";
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM `$bookings_table` b JOIN `$rooms_table` r ON b.room_id = r.id WHERE DATE(b.checkin_date) = ? AND b.status = 'active'");
$stmt->execute([$today]);
$checkins_today = $stmt->fetch()['count'];

// Get check-outs today
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM `$bookings_table` b JOIN `$rooms_table` r ON b.room_id = r.id WHERE DATE(b.checkout_date) = ? AND b.status = 'active'");
$stmt->execute([$today]);
$checkouts_today = $stmt->fetch()['count'];

// Get daily revenue
// Daily revenue: sum total_amount for bookings that started today (check-in date)
$stmt = $pdo->prepare("SELECT SUM(b.total_amount) as revenue FROM `$bookings_table` b JOIN `$rooms_table` r ON b.room_id = r.id WHERE b.status = 'active' AND DATE(b.checkin_date) = ?");
$stmt->execute([$today]);
$daily_revenue = $stmt->fetch()['revenue'] ?? 0;

// Get monthly revenue
// Monthly revenue: sum total_amount for bookings that started in the current month (check-in date)
$stmt = $pdo->prepare("SELECT SUM(b.total_amount) as revenue FROM `$bookings_table` b JOIN `$rooms_table` r ON b.room_id = r.id WHERE b.status = 'active' AND DATE_FORMAT(b.checkin_date, '%Y-%m') = ?");
$stmt->execute([$month]);
$monthly_revenue = $stmt->fetch()['revenue'] ?? 0;

// Get yearly revenue
$stmt = $pdo->prepare("SELECT SUM(b.total_amount) as revenue FROM `$bookings_table` b JOIN `$rooms_table` r ON b.room_id = r.id WHERE b.status = 'active' AND YEAR(b.checkin_date) = ?");
$stmt->execute([$year]);
$yearly_revenue = $stmt->fetch()['revenue'] ?? 0;

// Get recent bookings - handle both single and multiple room bookings
$stmt = $pdo->prepare("
    SELECT b.*, r.room_number, r.price as room_price 
    FROM `$bookings_table` b 
    JOIN `$rooms_table` r ON b.room_id = r.id 
    ORDER BY b.created_at DESC
");
$stmt->execute();
$all_recent_bookings = $stmt->fetchAll();

// Process bookings to handle multiple room bookings from note field
$processed_recent_bookings = [];
foreach ($all_recent_bookings as $booking) {
    $note = $booking['note'] ?? '';
    
    // Check if this is a multiple room booking by looking at the note field
    if (preg_match('/Multiple booking - Rooms:\s*([^|]+)/', $note, $matches)) {
        // Multiple room booking - extract room numbers from note
        $room_numbers_from_note = array_map('trim', explode(',', $matches[1]));
        
        // Create a single booking entry with all room numbers
        $processed_booking = [
            'id' => $booking['id'],
            'guest_name' => $booking['guest_name'],
            'guest_contact' => $booking['guest_contact'],
            'nid_number' => $booking['nid_number'],
            'profession' => $booking['profession'],
            'email' => $booking['email'],
            'num_guests' => $booking['num_guests'],
            'reference' => $booking['reference'],
            'note' => $booking['note'],
            'checkin_date' => $booking['checkin_date'],
            'checkout_date' => $booking['checkout_date'],
            'checkin_time' => $booking['checkin_time'],
            'checkout_time' => $booking['checkout_time'],
            'booking_type' => $booking['booking_type'],
            'status' => $booking['status'],
            'created_at' => $booking['created_at'],
            'breakfast_price' => $booking['breakfast_price'],
            'breakfast_quantity' => $booking['breakfast_quantity'],
            'breakfast_total' => $booking['breakfast_total'],
            'lunch_price' => $booking['lunch_price'],
            'lunch_quantity' => $booking['lunch_quantity'],
            'lunch_total' => $booking['lunch_total'],
            'dinner_price' => $booking['dinner_price'],
            'dinner_quantity' => $booking['dinner_quantity'],
            'dinner_total' => $booking['dinner_total'],
            'meal_total' => $booking['meal_total'],
            'total_amount' => $booking['total_amount'],
            'discount' => $booking['discount'],
            'paid' => $booking['paid'],
            'due' => $booking['due'],
            'room_numbers' => $room_numbers_from_note,
            'is_multiple' => true,
            'room_count' => count($room_numbers_from_note)
        ];
        
        $processed_recent_bookings[] = $processed_booking;
    } else {
        // Single room booking - add as is
        $booking['room_numbers'] = [$booking['room_number']];
        $booking['is_multiple'] = false;
        $booking['room_count'] = 1;
        $processed_recent_bookings[] = $booking;
    }
}

// Group bookings by guest (same guest name, phone, checkin/checkout dates) - only for actual multiple records
$grouped_recent_bookings = [];
foreach ($processed_recent_bookings as $booking) {
    $key = $booking['guest_name'] . '|' . $booking['guest_contact'] . '|' . $booking['checkin_date'] . '|' . $booking['checkout_date'];
    
    if (!isset($grouped_recent_bookings[$key])) {
        $grouped_recent_bookings[$key] = [
            'guest_name' => $booking['guest_name'],
            'guest_contact' => $booking['guest_contact'],
            'nid_number' => $booking['nid_number'],
            'profession' => $booking['profession'],
            'email' => $booking['email'],
            'num_guests' => $booking['num_guests'],
            'reference' => $booking['reference'],
            'note' => $booking['note'],
            'checkin_date' => $booking['checkin_date'],
            'checkout_date' => $booking['checkout_date'],
            'checkin_time' => $booking['checkin_time'],
            'checkout_time' => $booking['checkout_time'],
            'booking_type' => $booking['booking_type'],
            'status' => $booking['status'],
            'created_at' => $booking['created_at'],
            'breakfast_price' => $booking['breakfast_price'],
            'breakfast_quantity' => $booking['breakfast_quantity'],
            'breakfast_total' => $booking['breakfast_total'],
            'lunch_price' => $booking['lunch_price'],
            'lunch_quantity' => $booking['lunch_quantity'],
            'lunch_total' => $booking['lunch_total'],
            'dinner_price' => $booking['dinner_price'],
            'dinner_quantity' => $booking['dinner_quantity'],
            'dinner_total' => $booking['dinner_total'],
            'meal_total' => $booking['meal_total'],
            'total_amount' => $booking['total_amount'],
            'discount' => $booking['discount'],
            'paid' => $booking['paid'],
            'due' => $booking['due'],
            'room_numbers' => $booking['room_numbers'],
            'is_multiple' => $booking['is_multiple'],
            'room_count' => $booking['room_count'],
            'booking_ids' => [$booking['id']]
        ];
    } else {
        // Merge room numbers for same guest
        $grouped_recent_bookings[$key]['room_numbers'] = array_merge($grouped_recent_bookings[$key]['room_numbers'], $booking['room_numbers']);
        $grouped_recent_bookings[$key]['room_count'] = count($grouped_recent_bookings[$key]['room_numbers']);
        $grouped_recent_bookings[$key]['is_multiple'] = $grouped_recent_bookings[$key]['room_count'] > 1;
        $grouped_recent_bookings[$key]['booking_ids'][] = $booking['id'];
    }
}

$recent_bookings = array_values($grouped_recent_bookings);
// Limit to 10 most recent bookings
$recent_bookings = array_slice($recent_bookings, 0, 10);

// Determine which year to show (from GET or default to current year)
$selected_year = isset($_GET['revenue_year']) ? intval($_GET['revenue_year']) : intval($year);
// Get monthly revenue for the selected year
$monthly_revenue_data = [];
for ($m = 1; $m <= 12; $m++) {
    $month_str = sprintf('%04d-%02d', $selected_year, $m);
    $stmt = $pdo->prepare("SELECT SUM(b.total_amount) as revenue FROM `$bookings_table` b JOIN `$rooms_table` r ON b.room_id = r.id WHERE b.status = 'active' AND DATE_FORMAT(b.checkin_date, '%Y-%m') = ?");
    $stmt->execute([$month_str]);
    $monthly_revenue_data[] = (float)($stmt->fetch()['revenue'] ?? 0);
}

// Monthly Revenue Chart - Get selected month from GET or default to current month
$selected_month = isset($_GET['revenue_month']) ? $_GET['revenue_month'] : date('Y-m');
$month_year = DateTime::createFromFormat('Y-m', $selected_month);
$month_name = $month_year->format('F Y');
$days_in_month = $month_year->format('t');
$month_start_date = $selected_month . '-01';
$month_end_date = $selected_month . '-' . $days_in_month;

// Get daily revenue data for the selected month
$daily_revenue_data = [];
$daily_labels = [];
$current_date = new DateTime($month_start_date);
$end_date = new DateTime($month_end_date);

while ($current_date <= $end_date) {
    $date_str = $current_date->format('Y-m-d');
    $stmt = $pdo->prepare("SELECT SUM(b.total_amount) as revenue FROM `$bookings_table` b JOIN `$rooms_table` r ON b.room_id = r.id WHERE b.status = 'active' AND DATE(b.checkin_date) = ?");
    $stmt->execute([$date_str]);
    $daily_revenue_data[] = (float)($stmt->fetch()['revenue'] ?? 0);
    $daily_labels[] = $current_date->format('d'); // Just show day number
    $current_date->add(new DateInterval('P1D'));
}

// Calculate total revenue for the selected month
$total_monthly_revenue = array_sum($daily_revenue_data);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($hotel['name']); ?> - Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-gray-50">
    <div class="flex min-h-screen">
        <!-- Sidebar -->
        <div class="w-64 bg-gradient-to-b from-indigo-500 to-purple-600 text-white shadow-lg fixed h-full overflow-y-auto">
            <div class="flex items-center justify-center h-16 p-4">
                <?php if (!empty($hotel['logo'])): ?>
                    <img src="<?php echo htmlspecialchars($hotel['logo']); ?>" alt="<?php echo htmlspecialchars($hotel['name']); ?> Logo" class="h-10 w-auto max-w-16 object-contain">
                <?php else: ?>
                    <i class="fas fa-hotel text-2xl"></i>
                <?php endif; ?>
                <span class="ml-3 text-xl font-bold"><?php echo htmlspecialchars($hotel['name']); ?></span>
            </div>
            <nav class="mt-8">
                
                <div class="px-4 mb-4">
                <p class="text-white opacity-70 text-sm">Welcome Manager</p>
            </div>
                <a href="hotel_dashboard.php?hotel_id=<?php echo $hotel_id; ?>" class="block px-6 py-3 bg-white bg-opacity-20 border-r-4 border-white">
                    <i class="fas fa-tachometer-alt mr-3"></i>Dashboard
                </a>
                 <a href="calendar.php?hotel_id=<?php echo $hotel_id; ?>" class="block px-6 py-3 hover:bg-white hover:bg-opacity-20 transition-colors">
                    <i class="fas fa-calendar-alt mr-3"></i>Calendar
                </a>
                
                <a href="multiple_booking.php?hotel_id=<?php echo $hotel_id; ?>" class="block px-6 py-3 hover:bg-white hover:bg-opacity-20 transition-colors">
                    <i class="fas fa-bed mr-3"></i>Multiple Booking
                </a>
                
                 <a href="manage_bookings.php?hotel_id=<?php echo $hotel_id; ?>" class="block px-6 py-3 hover:bg-white hover:bg-opacity-20 transition-colors">
                    <i class="fas fa-list mr-3"></i>Manage Bookings
                </a>
                
                <?php if ($_SESSION['user_role'] === 'admin'): ?>
                <a href="manage_rooms.php?hotel_id=<?php echo $hotel_id; ?>" class="block px-6 py-3 hover:bg-white hover:bg-opacity-20 transition-colors">
                    <i class="fas fa-bed mr-3"></i>Manage Rooms
                </a>
                <?php endif; ?>
               
                <a href="pricing.php?hotel_id=<?php echo $hotel_id; ?>" class="block px-6 py-3 hover:bg-white hover:bg-opacity-20 transition-colors">
                    <i class="fas fa-tags mr-3"></i>Pricing
                </a>
               
                <a href="logout.php" class="block px-6 py-3 mt-8 hover:bg-white hover:bg-opacity-20 transition-colors">
                    <i class="fas fa-sign-out-alt mr-3"></i>Logout
                </a>
            </nav>
        </div>
        
        <!-- Main Content -->
        <div class="flex-1 p-8 ml-64">
            <?php if ($_SESSION['user_role'] === 'admin'): ?>
                <div class="mb-4">
                    <a href="dashboard.php" class="text-indigo-600 hover:text-indigo-800 font-medium">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Admin Dashboard
                    </a>
                </div>
            <?php endif; ?>
            <h1 class="text-3xl font-bold mb-2 text-gray-800">Dashboard - <?php echo htmlspecialchars($hotel['name']); ?></h1>
            <p class="text-gray-600 mb-6">Address: <?php echo htmlspecialchars($hotel['address']); ?></p>
            
            
            <!-- Guest Search Bar -->
            <form method="post" action="" class="mb-6 flex flex-col md:flex-row items-center gap-4 bg-white p-4 rounded-lg shadow">
                <input type="hidden" name="hotel_id" value="<?php echo $hotel_id; ?>">
                <input type="text" name="guest_search" placeholder="Search by Phone or NID" value="" class="border border-gray-300 rounded px-4 py-2 w-full md:w-64 focus:outline-none focus:ring-2 focus:ring-green-500" required>
                <button type="submit" class="bg-green-600 hover:bg-green-700 text-white font-semibold px-6 py-2 rounded-lg shadow transition duration-200">
                    <i class="fas fa-search mr-2"></i>Search Guest
                </button>
            </form>
            <?php
            // Only show search results if the form was submitted via POST
            if (
                $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guest_search']) && trim($_POST['guest_search']) !== ''
            ) {
                $search = trim($_POST['guest_search']);
                $bookings_table = "bookings_hotel_{$hotel_id}";
                // Only select guests who have at least one booking in this hotel
                $stmt = $pdo->prepare('
                    SELECT g.name, g.phone, g.nid, g.profession, g.email, g.no_of_guests, b.checkin_date, b.checkout_date, b.total_amount
                    FROM guests g
                    JOIN ' . $bookings_table . ' b ON g.name = b.guest_name AND g.phone = b.guest_contact
                    WHERE (g.phone = ? OR g.nid = ?)
                ');
                $stmt->execute([$search, $search]);
                $guests_found = $stmt->fetchAll();
                echo '<div class="bg-white rounded-lg shadow-md p-4 mb-6">';
                if ($guests_found && count($guests_found) > 0) {
                    echo '<h2 class="text-lg font-semibold mb-2 text-gray-800">Guest Search Results</h2>';
                    echo '<table class="w-full mb-2"><thead><tr>';
                    echo '<th class="px-4 py-2">Name</th><th class="px-4 py-2">Phone</th><th class="px-4 py-2">NID</th><th class="px-4 py-2">Profession</th><th class="px-4 py-2">Email</th><th class="px-4 py-2">No. of Guests</th><th class="px-4 py-2">Check-in</th><th class="px-4 py-2">Check-out</th><th class="px-4 py-2">Total</th>';
                    echo '</tr></thead><tbody>';
                    foreach ($guests_found as $guest) {
                        echo '<tr class="border-b">';
                        echo '<td class="px-4 py-2">' . htmlspecialchars($guest['name'] ?? '') . '</td>';
                        echo '<td class="px-4 py-2">' . htmlspecialchars($guest['phone'] ?? '') . '</td>';
                        echo '<td class="px-4 py-2">' . htmlspecialchars($guest['nid'] ?? '') . '</td>';
                        echo '<td class="px-4 py-2">' . htmlspecialchars($guest['profession'] ?? '') . '</td>';
                        echo '<td class="px-4 py-2">' . htmlspecialchars($guest['email'] ?? '') . '</td>';
                        echo '<td class="px-4 py-2">' . htmlspecialchars($guest['no_of_guests'] ?? '') . '</td>';
                        echo '<td class="px-4 py-2">' . htmlspecialchars($guest['checkin_date'] ?? '') . '</td>';
                        echo '<td class="px-4 py-2">' . htmlspecialchars($guest['checkout_date'] ?? '') . '</td>';
                        echo '<td class="px-4 py-2">৳' . number_format($guest['total_amount'] ?? 0, 2) . '</td>';
                        echo '</tr>';
                    }
                    echo '</tbody></table>';
                } else {
                    echo '<div class="text-red-600 font-medium">No guest found with that phone or NID.</div>';
                }
                echo '</div>';
            }
            ?>
            <div class="mb-6">
                <a href="calendar.php?hotel_id=<?php echo $hotel_id; ?>" class="inline-block bg-green-600 hover:bg-green-700 text-white font-semibold px-6 py-2 rounded-lg shadow transition duration-200">
                    <i class="fas fa-calendar-plus mr-2"></i>Quick Booking
                </a>
            </div>
            
            <!-- Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="bg-gradient-to-br from-indigo-500 to-purple-600 rounded-lg p-6 text-white">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-white opacity-80 text-sm">Today's Check-ins</p>
                            <p class="text-3xl font-bold"><?php echo $checkins_today; ?></p>
                            <p class="text-xs opacity-70"><?php echo format_display_date(date('Y-m-d')); ?></p>
                        </div>
                        <i class="fas fa-sign-in-alt text-4xl opacity-50"></i>
                    </div>
                </div>
                
                <div class="bg-gradient-to-br from-indigo-500 to-purple-600 rounded-lg p-6 text-white">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-white opacity-80 text-sm">Today's Check-outs</p>
                            <p class="text-3xl font-bold"><?php echo $checkouts_today; ?></p>
                            <p class="text-xs opacity-70"><?php echo format_display_date(date('Y-m-d')); ?></p>
                        </div>
                        <i class="fas fa-sign-out-alt text-4xl opacity-50"></i>
                    </div>
                </div>
                
                <div class="bg-gradient-to-br from-indigo-500 to-purple-600 rounded-lg p-6 text-white">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-white opacity-80 text-sm">Daily Revenue</p>
                            <p class="text-3xl font-bold">৳<?php echo number_format($daily_revenue, 2); ?></p>
                        </div>
                        <i class="fas fa-money-bill-wave text-4xl opacity-50"></i>
                    </div>
                </div>
                
                <div class="bg-gradient-to-br from-indigo-500 to-purple-600 rounded-lg p-6 text-white">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-white opacity-80 text-sm">Monthly Revenue</p>
                            <p class="text-3xl font-bold">৳<?php echo number_format($monthly_revenue, 2); ?></p>
                        </div>
                        <i class="fas fa-calendar-alt text-4xl opacity-50"></i>
                    </div>
                </div>
            </div>
            
            <!-- Recent Bookings -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h2 class="text-xl font-semibold text-gray-800 mb-4">Recent Bookings</h2>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2">Booking ID</th>
                                <th class="px-4 py-2">Rooms</th>
                                <th class="px-4 py-2">Guest Name</th>
                                <th class="px-4 py-2">Phone</th>
                                <th class="px-4 py-2">NID</th>
                                <th class="px-4 py-2">Profession</th>
                                <th class="px-4 py-2">Email</th>
                                <th class="px-4 py-2">Check-in</th>
                                <th class="px-4 py-2">Check-out</th>
                                <th class="px-4 py-2">Total</th>
                                <th class="px-4 py-2">Status</th>
                                <th class="px-4 py-2">Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($recent_bookings)): ?>
                                <?php foreach ($recent_bookings as $booking): ?>
                                <tr class="border-b">
                                    <td class="px-4 py-2">
                                        <?php if ($booking['is_multiple']): ?>
                                            <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded text-xs font-medium">Multiple</span><br>
                                        <?php endif; ?>
                                        <?php echo $booking['booking_ids'][0]; ?>
                                    </td>
                                    <td class="px-4 py-2">
                                        <?php echo htmlspecialchars(implode(', ', $booking['room_numbers'])); ?>
                                    </td>
                                    <td class="px-4 py-2"><?php echo htmlspecialchars($booking['guest_name'] ?? ''); ?></td>
                                    <td class="px-4 py-2"><?php echo htmlspecialchars($booking['guest_contact'] ?? ''); ?></td>
                                    <td class="px-4 py-2"><?php echo htmlspecialchars($booking['nid_number'] ?? ''); ?></td>
                                    <td class="px-4 py-2"><?php echo htmlspecialchars($booking['profession'] ?? ''); ?></td>
                                    <td class="px-4 py-2"><?php echo htmlspecialchars($booking['email'] ?? ''); ?></td>
                                    <td class="px-4 py-2"><?php echo htmlspecialchars($booking['checkin_date'] ?? ''); ?></td>
                                    <td class="px-4 py-2"><?php echo htmlspecialchars($booking['checkout_date'] ?? ''); ?></td>
                                    <td class="px-4 py-2">BDT <?php echo number_format($booking['total_amount'] ?? 0, 2); ?></td>
                                    <td class="px-4 py-2"><?php echo ucfirst($booking['status'] ?? ''); ?></td>
                                    <td class="px-4 py-2">
                                        <?php 
                                        $note = trim($booking['note'] ?? '');
                                        if (!empty($note)) {
                                            echo htmlspecialchars($note);
                                        } else {
                                            echo '<span class="text-gray-400">-</span>';
                                        }
                                        ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="12" class="px-4 py-2 text-center text-gray-500">No recent bookings</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow-md p-6 mt-8 border border-green-200" style="min-height:320px;">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h2 class="text-xl font-semibold text-gray-800">Monthly Revenue Analysis - <?php echo $month_name; ?></h2>
                        <p class="text-sm text-gray-600 mt-1">Total Revenue: <span class="font-semibold text-green-600">৳<?php echo number_format($total_monthly_revenue, 2); ?></span></p>
                    </div>
                    <div class="flex items-center space-x-4">
                        <form method="get" action="" class="flex items-center space-x-2">
                            <input type="hidden" name="hotel_id" value="<?php echo $hotel_id; ?>">
                            <?php if (isset($_GET['revenue_year'])): ?>
                                <input type="hidden" name="revenue_year" value="<?php echo $_GET['revenue_year']; ?>">
                            <?php endif; ?>
                            <label class="text-sm text-gray-600">Select Month:</label>
                            <select name="revenue_month" onchange="this.form.submit()" class="border rounded px-3 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                                <?php 
                                // Generate month options from 2020 to 2030
                                $current_year = date('Y');
                                $start_year = 2020;
                                $end_year = 2030;
                                
                                // Generate months for all years from 2020 to 2030
                                for ($year = $start_year; $year <= $end_year; $year++) {
                                    for ($m = 1; $m <= 12; $m++) {
                                        $month_value = sprintf('%04d-%02d', $year, $m);
                                        $month_label = date('F Y', strtotime($month_value . '-01'));
                                        $selected = ($selected_month === $month_value) ? 'selected' : '';
                                        echo "<option value='{$month_value}' {$selected}>{$month_label}</option>";
                                    }
                                }
                                ?>
                            </select>
                        </form>
                        <a href="php/download_monthly_revenue_pdf.php?hotel_id=<?php echo $hotel_id; ?>&revenue_month=<?php echo $selected_month; ?>" class="bg-green-600 hover:bg-green-700 text-white font-semibold px-4 py-2 rounded-lg shadow transition duration-200 text-sm">
                            <i class="fas fa-download mr-2"></i>Download PDF Report
                        </a>
                    </div>
                </div>
                <canvas id="monthlyRevenueChart" height="80"></canvas>
                <div class="mt-4 text-xs text-gray-400">Debug: <?php echo htmlspecialchars(json_encode($daily_revenue_data)); ?></div>
            </div>
            <!-- Yearly Revenue Graph -->
            <div class="bg-white rounded-lg shadow-md p-6 mt-8 border border-indigo-200" style="min-height:320px;">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-xl font-semibold text-gray-800">Yearly Revenue (<?php echo $selected_year; ?>)</h2>
                    <form method="get" action="" class="flex items-center space-x-2">
                        <input type="hidden" name="hotel_id" value="<?php echo $hotel_id; ?>">
                        <select name="revenue_year" onchange="this.form.submit()" class="border rounded px-2 py-1 text-sm">
                            <?php for ($y = intval(date('Y')); $y <= intval(date('Y')) + 5; $y++): ?>
                                <option value="<?php echo $y; ?>" <?php if ($selected_year == $y) echo 'selected'; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </form>
                </div>
                <canvas id="revenueChart" height="80"></canvas>
                <div class="mt-4 text-xs text-gray-400">Debug: <?php echo htmlspecialchars(json_encode($monthly_revenue_data)); ?></div>
            </div>

            <!-- Monthly Revenue Chart -->
            
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@1.1.0"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0"></script>
    <script>
        // Yearly Revenue Chart
        const ctx = document.getElementById('revenueChart').getContext('2d');
        const revenueChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                datasets: [{
                    label: 'Revenue (৳)',
                    data: <?php echo json_encode($monthly_revenue_data); ?>,
                    backgroundColor: 'rgba(99, 102, 241, 0.7)',
                    borderColor: 'rgba(99, 102, 241, 1)',
                    borderWidth: 2,
                    borderRadius: 6,
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false },
                    title: { display: false },
                    annotation: {
                        annotations: {
                            xAxisLine: {
                                type: 'line',
                                yMin: 0,
                                yMax: 0,
                                borderColor: 'red',
                                borderWidth: 2,
                                label: {
                                    enabled: false
                                }
                            }
                        }
                    },
                    datalabels: {
                        anchor: 'end',
                        align: 'end',
                        color: '#333',
                        font: { weight: 'bold', size: 12 },
                        formatter: function(value) {
                            return value > 0 ? '৳' + value.toLocaleString() : '';
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { callback: value => '৳' + value }
                    }
                }
            },
            plugins: [ChartDataLabels]
        });

        // Monthly Revenue Chart
        const monthlyCtx = document.getElementById('monthlyRevenueChart').getContext('2d');
        const monthlyRevenueChart = new Chart(monthlyCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($daily_labels); ?>,
                datasets: [{
                    label: 'Daily Revenue (৳)',
                    data: <?php echo json_encode($daily_revenue_data); ?>,
                    backgroundColor: 'rgba(34, 197, 94, 0.2)',
                    borderColor: 'rgba(34, 197, 94, 1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: 'rgba(34, 197, 94, 1)',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false },
                    title: { display: false },
                    datalabels: {
                        anchor: 'end',
                        align: 'top',
                        color: '#333',
                        font: { weight: 'bold', size: 10 },
                        formatter: function(value) {
                            return value > 0 ? '৳' + value.toLocaleString() : '';
                        },
                        display: function(context) {
                            return context.parsed.y > 0;
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            maxRotation: 45,
                            minRotation: 45
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.1)'
                        },
                        ticks: { 
                            callback: value => '৳' + value.toLocaleString()
                        }
                    }
                },
                interaction: {
                    intersect: false,
                    mode: 'index'
                },
                elements: {
                    point: {
                        hoverRadius: 8
                    }
                }
            },
            plugins: [ChartDataLabels]
        });



    </script>
</body>
</html> 