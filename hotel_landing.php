<?php
session_start();
require_once 'database/config.php';

if (!isset($_GET['id'])) {
    header('Location: index.php');
    exit();
}

$hotel_id = (int)$_GET['id'];

// Fetch hotel details
$stmt = $conn->prepare('SELECT * FROM hotels WHERE id = ?');
$stmt->bind_param('i', $hotel_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Location: index.php');
    exit();
}

$hotel = $result->fetch_assoc();

// Fetch rooms for this hotel
$stmt = $conn->prepare('SELECT * FROM rooms WHERE hotel_id = ? ORDER BY room_no');
$stmt->bind_param('i', $hotel_id);
$stmt->execute();
$rooms = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch bookings for this hotel
$stmt = $conn->prepare('
    SELECT b.*, r.room_no, g.name as guest_name 
    FROM bookings b 
    JOIN rooms r ON b.room_id = r.id 
    JOIN guests g ON b.guest_id = g.id 
    WHERE r.hotel_id = ? AND b.status != "cancelled"
    ORDER BY b.checkin
');
$stmt->bind_param('i', $hotel_id);
$stmt->execute();
$bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get current month dates
$current_month = date('Y-m');
$first_day = date('Y-m-01');
$last_day = date('Y-m-t');
$days_in_month = date('t');
$first_day_of_week = date('w', strtotime($first_day));

// Create calendar data
$calendar = [];
$current_date = $first_day;

for ($i = 0; $i < $days_in_month; $i++) {
    $date = date('Y-m-d', strtotime($current_date . ' +' . $i . ' days'));
    $day_bookings = array_filter($bookings, function($booking) use ($date) {
        return $booking['checkin'] <= $date && $booking['checkout'] > $date;
    });
    
    $calendar[$date] = [
        'date' => $date,
        'day' => date('j', strtotime($date)),
        'booked_rooms' => count($day_bookings),
        'total_rooms' => count($rooms),
        'available_rooms' => count($rooms) - count($day_bookings)
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($hotel['name']); ?> - Book Your Stay</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-50">
    <!-- Header -->
    <header class="bg-white shadow-lg border-b">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center">
                    <a href="index.php" class="mr-4 text-gray-600 hover:text-gray-900">
                        <i class="fas fa-arrow-left"></i>
                    </a>
                    <img src="assets/images/logo.png" alt="Logo" class="w-10 h-10 mr-3">
                    <h1 class="text-2xl font-bold text-gray-900"><?php echo htmlspecialchars($hotel['name']); ?></h1>
                </div>
                <nav class="flex items-center space-x-4">
                    <a href="search.php" class="text-gray-600 hover:text-gray-900">
                        <i class="fas fa-search mr-2"></i>Search Bookings
                    </a>
                    <a href="pricing.php" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg font-medium transition duration-200">
                        <i class="fas fa-tags mr-2"></i>Price Check
                    </a>
                    <a href="login.php" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg font-medium transition duration-200">
                        <i class="fas fa-sign-in-alt mr-2"></i>Login
                    </a>
                </nav>
            </div>
        </div>
    </header>

    <!-- Hotel Info Banner -->
    <section class="bg-gradient-to-r from-indigo-600 to-purple-600 text-white py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center">
                <h2 class="text-4xl font-bold mb-4"><?php echo htmlspecialchars($hotel['name']); ?></h2>
                <p class="text-xl mb-2"><?php echo htmlspecialchars($hotel['address']); ?></p>
                <p class="text-lg opacity-90"><?php echo htmlspecialchars($hotel['description']); ?></p>
            </div>
        </div>
    </section>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Calendar Section -->
            <div class="lg:col-span-2">
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-2xl font-bold text-gray-900">Room Availability Calendar</h3>
                        <div class="flex space-x-2">
                            <button onclick="previousMonth()" class="p-2 text-gray-600 hover:text-gray-900">
                                <i class="fas fa-chevron-left"></i>
                            </button>
                            <span class="text-lg font-semibold text-gray-900" id="currentMonth">
                                <?php echo date('F Y'); ?>
                            </span>
                            <button onclick="nextMonth()" class="p-2 text-gray-600 hover:text-gray-900">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Calendar Grid -->
                    <div class="grid grid-cols-7 gap-1 mb-4">
                        <div class="p-2 text-center font-semibold text-gray-600">Sun</div>
                        <div class="p-2 text-center font-semibold text-gray-600">Mon</div>
                        <div class="p-2 text-center font-semibold text-gray-600">Tue</div>
                        <div class="p-2 text-center font-semibold text-gray-600">Wed</div>
                        <div class="p-2 text-center font-semibold text-gray-600">Thu</div>
                        <div class="p-2 text-center font-semibold text-gray-600">Fri</div>
                        <div class="p-2 text-center font-semibold text-gray-600">Sat</div>
                    </div>

                    <div class="grid grid-cols-7 gap-1" id="calendarGrid">
                        <?php
                        // Add empty cells for days before the first day of the month
                        for ($i = 0; $i < $first_day_of_week; $i++) {
                            echo '<div class="p-2 text-center text-gray-400"></div>';
                        }

                        // Add calendar days
                        foreach ($calendar as $date => $day_data) {
                            $is_today = $date === date('Y-m-d');
                            $is_available = $day_data['available_rooms'] > 0;
                            $availability_percentage = ($day_data['available_rooms'] / $day_data['total_rooms']) * 100;
                            
                            $bg_class = 'bg-gray-100';
                            if ($availability_percentage >= 75) {
                                $bg_class = 'bg-green-100 hover:bg-green-200';
                            } elseif ($availability_percentage >= 50) {
                                $bg_class = 'bg-yellow-100 hover:bg-yellow-200';
                            } elseif ($availability_percentage >= 25) {
                                $bg_class = 'bg-orange-100 hover:bg-orange-200';
                            } else {
                                $bg_class = 'bg-red-100 hover:bg-red-200';
                            }
                            
                            $border_class = $is_today ? 'border-2 border-indigo-500' : 'border border-gray-200';
                            $cursor_class = $is_available ? 'cursor-pointer' : 'cursor-not-allowed';
                            
                            echo '<div class="p-2 text-center ' . $bg_class . ' ' . $border_class . ' ' . $cursor_class . ' rounded-lg transition duration-200" 
                                    onclick="' . ($is_available ? 'selectDate(\'' . $date . '\')' : '') . '">';
                            echo '<div class="font-semibold text-gray-900">' . $day_data['day'] . '</div>';
                            echo '<div class="text-xs text-gray-600">' . $day_data['available_rooms'] . '/' . $day_data['total_rooms'] . ' available</div>';
                            echo '</div>';
                        }
                        ?>
                    </div>

                    <!-- Legend -->
                    <div class="mt-6 flex flex-wrap gap-4 text-sm">
                        <div class="flex items-center">
                            <div class="w-4 h-4 bg-green-100 rounded mr-2"></div>
                            <span>75%+ Available</span>
                        </div>
                        <div class="flex items-center">
                            <div class="w-4 h-4 bg-yellow-100 rounded mr-2"></div>
                            <span>50-74% Available</span>
                        </div>
                        <div class="flex items-center">
                            <div class="w-4 h-4 bg-orange-100 rounded mr-2"></div>
                            <span>25-49% Available</span>
                        </div>
                        <div class="flex items-center">
                            <div class="w-4 h-4 bg-red-100 rounded mr-2"></div>
                            <span>Less than 25% Available</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Booking Form -->
            <div class="lg:col-span-1">
                <div class="bg-white rounded-xl shadow-lg p-6 sticky top-4">
                    <h3 class="text-xl font-bold text-gray-900 mb-6">Book Your Stay</h3>
                    
                    <form action="process_booking.php" method="POST" class="space-y-4">
                        <input type="hidden" name="hotel_id" value="<?php echo $hotel_id; ?>">
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Check-in Date</label>
                                <input type="date" name="checkin_date" id="checkin_date" required 
                                       min="<?php echo date('Y-m-d'); ?>" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-400">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Check-out Date</label>
                                <input type="date" name="checkout_date" id="checkout_date" required 
                                       min="<?php echo date('Y-m-d'); ?>" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-400">
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Guest Name</label>
                            <input type="text" name="guest_name" required 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-400">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">NID Number</label>
                            <input type="text" name="nid" required 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-400">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Profession</label>
                            <input type="text" name="profession" required 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-400">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                            <input type="email" name="email" required 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-400">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Phone Number</label>
                            <input type="tel" name="phone" required 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-400">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Number of Guests</label>
                            <select name="no_of_guests" required 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-400">
                                <option value="">Select</option>
                                <option value="1">1 Guest</option>
                                <option value="2">2 Guests</option>
                                <option value="3">3 Guests</option>
                                <option value="4">4 Guests</option>
                                <option value="5">5+ Guests</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Discount (BDT)</label>
                            <input type="number" name="discount" value="0" min="0" 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-400">
                        </div>
                        
                        <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white py-3 rounded-lg font-medium transition duration-200">
                            <i class="fas fa-check mr-2"></i>Confirm Booking
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </main>

    <script>
        let currentDate = new Date();
        
        function selectDate(date) {
            document.getElementById('checkin_date').value = date;
            // Set checkout date to next day
            const nextDay = new Date(date);
            nextDay.setDate(nextDay.getDate() + 1);
            document.getElementById('checkout_date').value = nextDay.toISOString().split('T')[0];
        }
        
        function previousMonth() {
            currentDate.setMonth(currentDate.getMonth() - 1);
            updateCalendar();
        }
        
        function nextMonth() {
            currentDate.setMonth(currentDate.getMonth() + 1);
            updateCalendar();
        }
        
        function updateCalendar() {
            // This would require AJAX to fetch new month data
            // For now, just update the month display
            const options = { year: 'numeric', month: 'long' };
            document.getElementById('currentMonth').textContent = currentDate.toLocaleDateString('en-US', options);
        }
        
        // Set minimum dates for date inputs
        document.addEventListener('DOMContentLoaded', function() {
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('checkin_date').min = today;
            document.getElementById('checkout_date').min = today;
        });
    </script>
</body>
</html> 