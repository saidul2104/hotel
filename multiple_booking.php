<?php
// Start session with proper configuration
ini_set('session.cookie_path', '/');
session_start();
require_once 'database/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Get hotel ID from session or URL parameter
$hotel_id = $_SESSION['hotel_id'] ?? ($_GET['hotel_id'] ?? 1);

// Fetch hotel information
$stmt = $pdo->prepare("SELECT * FROM hotels WHERE id = ?");
$stmt->execute([$hotel_id]);
$hotel = $stmt->fetch();

if (!$hotel) {
    die("Hotel not found.");
}

// Fetch room categories
$stmt = $pdo->prepare("SELECT * FROM room_categories WHERE hotel_id = ? ORDER BY price ASC");
$stmt->execute([$hotel_id]);
$categories = $stmt->fetchAll();

// Fetch available rooms grouped by category - using per-hotel table structure
$rooms_table = "rooms_hotel_{$hotel_id}";
$bookings_table = "bookings_hotel_{$hotel_id}";

// Set default dates for initial load (today and tomorrow)
$default_checkin = date('Y-m-d');
$default_checkout = date('Y-m-d', strtotime('+1 day'));

// Use the availability function to get rooms available for default dates
require_once 'php/room_availability.php';
$availability_result = getAvailableRoomsForDates($pdo, $hotel_id, $default_checkin, $default_checkout);

if ($availability_result['success']) {
    $grouped_rooms = $availability_result['rooms'];
} else {
    // Fallback to basic room listing if availability check fails
    // Use the same availability logic but without date filtering
    $availability_result = getRoomAvailability($pdo, $hotel_id);
    
    if ($availability_result['success']) {
        // Group available rooms by category
        $grouped_rooms = [];
        foreach ($availability_result['rooms'] as $room) {
            // Only include rooms that are available
            if (!$room['is_booked']) {
                $category = $room['category'] ?: 'Uncategorized';
                if (!isset($grouped_rooms[$category])) {
                    $grouped_rooms[$category] = [
                        'name' => $category,
                        'price' => $room['price'],
                        'description' => $category . ' rooms with modern amenities',
                        'rooms' => []
                    ];
                }
                $grouped_rooms[$category]['rooms'][] = [
                    'id' => $room['id'],
                    'room_number' => $room['room_number'],
                    'category' => $room['category'],
                    'price' => $room['price'],
                    'description' => $room['description'] ?? ''
                ];
            }
        }
    } else {
        // Final fallback - show all rooms without availability check
        $stmt = $pdo->prepare("
            SELECT r.*, rc.name as category_name, rc.description as category_description 
            FROM `$rooms_table` r 
            LEFT JOIN room_categories rc ON r.category = rc.name AND rc.hotel_id = ? 
            GROUP BY r.id
            ORDER BY r.room_number, r.id
        ");
        $stmt->execute([$hotel_id]);
        $available_rooms = $stmt->fetchAll();

        // Group rooms by category
        $grouped_rooms = [];
        foreach ($available_rooms as $room) {
            $category = $room['category'] ?: 'Uncategorized';
            if (!isset($grouped_rooms[$category])) {
                $grouped_rooms[$category] = [
                    'name' => $room['category_name'] ?: $category,
                    'price' => $room['price'],
                    'description' => $room['category_description'] ?: '',
                    'rooms' => []
                ];
            }
            $grouped_rooms[$category]['rooms'][] = $room;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Multiple Booking - <?php echo htmlspecialchars($hotel['name']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .category-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            border: 2px solid transparent;
            margin-bottom: 20px;
        }
        
        .category-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
            border-color: #3498db;
        }
        
        .category-header {
            background: linear-gradient(135deg, #3498db, #2980b9);
            color: white;
            padding: 20px;
            border-radius: 15px 15px 0 0;
            position: relative;
        }
        
        .room-item {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin: 10px 0;
            border: 2px solid transparent;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .room-item:hover {
            border-color: #27ae60;
            background: #e8f5e8;
        }
        
        .room-item.selected {
            border-color: #27ae60;
            background: #d4edda;
        }
        
        .booking-summary {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            position: sticky;
            top: 20px;
            height: fit-content;
        }
        
        .price-tag {
            background: linear-gradient(135deg, #f39c12, #e67e22);
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.9em;
        }
        
        .selected-rooms {
            max-height: 300px;
            overflow-y: auto;
        }
        
        .selected-room-item {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 10px;
            margin: 5px 0;
            border-left: 4px solid #27ae60;
        }
        
        .remove-room {
            color: #e74c3c;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .remove-room:hover {
            color: #c0392b;
            transform: scale(1.1);
        }
        
        .total-section {
            background: linear-gradient(135deg, #27ae60, #229954);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-top: 20px;
        }
        
        .loading {
            display: none;
            text-align: center;
            padding: 20px;
        }
        
        .spinner-border {
            width: 3rem;
            height: 3rem;
        }
    </style>
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
                
                <a href="calendar.php?hotel_id=<?php echo $hotel_id; ?>" class="block px-6 py-3 hover:bg-white hover:bg-opacity-20 transition-colors"><i class="fas fa-calendar-alt mr-3"></i>Calendar View</a>
                
                <a href="multiple_booking.php?hotel_id=<?php echo $hotel_id; ?>" class="block px-6 py-3 bg-white bg-opacity-20 border-r-4 border-white"><i class="fas fa-bed mr-3"></i>Multiple Booking</a>
                
                <a href="manage_bookings.php?hotel_id=<?php echo $hotel_id; ?>" class="block px-6 py-3 hover:bg-white hover:bg-opacity-20 transition-colors"><i class="fas fa-list mr-3"></i>Manage Bookings</a>
                
                <a href="pricing.php?hotel_id=<?php echo $hotel_id; ?>" class="block px-6 py-3 hover:bg-white hover:bg-opacity-20 transition-colors"><i class="fas fa-tags mr-3"></i>Pricing</a>
                
                <?php if ($_SESSION['user_role'] === 'admin'): ?>
                <a href="manage_rooms.php?hotel_id=<?php echo $hotel_id; ?>" class="block px-6 py-3 hover:bg-white hover:bg-opacity-20 transition-colors"><i class="fas fa-bed mr-3"></i>Manage Rooms</a>
                <?php endif; ?>
                
                <a href="logout.php" class="block px-6 py-3 mt-8 hover:bg-white hover:bg-opacity-20 transition-colors"><i class="fas fa-sign-out-alt mr-3"></i>Logout</a>
            </nav>
        </div>
        
        <!-- Main Content -->
        <div class="flex-1 p-8 ml-64 flex flex-col h-screen">
            <!-- Fixed Header Section -->
            <div class="flex-shrink-0">
                <h1 class="text-3xl font-bold mb-6 text-gray-800">Multiple Room Booking</h1>
                <p class="text-gray-600 mb-6">Book multiple rooms at once with a single guest form</p>
                
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-semibold text-gray-800">
                        <i class="fas fa-bed mr-2"></i>
                        Multiple Room Booking
                    </h2>
                    <span class="bg-blue-600 text-white px-3 py-1 rounded-full text-sm font-medium">
                        <i class="fas fa-calendar mr-1"></i>
                        <?php echo date('d/m/Y'); ?>
                    </span>
                </div>
                
                <!-- Date Filter Section -->
                <div class="bg-white border border-gray-200 rounded-lg p-6 mb-6 shadow-sm">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">
                        <i class="fas fa-calendar-alt mr-2"></i>
                        Select Dates to View Available Rooms
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Check-in Date *</label>
                            <input type="date" id="filterCheckinDate" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" 
                                   min="<?php echo date('Y-m-d'); ?>" 
                                   value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Check-out Date *</label>
                            <input type="date" id="filterCheckoutDate" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" 
                                   min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" 
                                   value="<?php echo date('Y-m-d', strtotime('+1 day')); ?>">
                        </div>
                        <div class="flex items-end">
                            <button type="button" onclick="filterAvailableRooms()" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200">
                                <i class="fas fa-search mr-2"></i>
                                Check Availability
                            </button>
                        </div>


                    </div>
                </div>

                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                    <i class="fas fa-info-circle text-blue-600 mr-2"></i>
                    <strong class="text-blue-800">Instructions:</strong> 
                    <span class="text-blue-700">Select your dates above, then click on room categories to expand and select multiple rooms. Fill in guest information once and book all selected rooms together.</span>
                </div>

                <!-- Selected Dates Display -->
                <div id="selectedDatesDisplay" class="mb-6">
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-3">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-4">
                                <i class="fas fa-calendar-alt text-blue-600"></i>
                                <span class="text-blue-800 font-medium">
                                    Check-in: <?php echo date('d/m/Y'); ?> | Check-out: <?php echo date('d/m/Y', strtotime('+1 day')); ?>
                                </span>
                            </div>
                            <span class="text-blue-600 text-sm">1 night</span>
                        </div>
                    </div>
                </div>

            <!-- Scrollable Content Section -->
            <div class="flex-1 overflow-auto">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Left Side - Room Categories -->
                    <div class="lg:col-span-2">
                        <?php if (empty($grouped_rooms)): ?>
                            <div class="text-center py-12">
                                <i class="fas fa-bed text-6xl text-gray-400 mb-4"></i>
                                <h4 class="text-gray-500 text-xl mb-2">No Available Rooms</h4>
                                <p class="text-gray-400">All rooms are currently booked or under maintenance.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($grouped_rooms as $category => $category_data): ?>
                                <div class="category-card mb-6">
                                    <div class="category-header">
                                        <div class="flex justify-between items-center">
                                            <div>
                                                <h4 class="text-lg font-semibold mb-1">
                                                    <i class="fas fa-door-open mr-2"></i>
                                                    <?php echo htmlspecialchars($category_data['name']); ?>
                                                </h4>
                                                <p class="text-white opacity-75 text-sm">
                                                    <?php echo htmlspecialchars($category_data['description']); ?>
                                                </p>
                                            </div>
                                            <div class="text-right">
                                                <div class="price-tag">
                                                    BDT <?php echo number_format($category_data['price'], 2); ?> / night
                                                </div>
                                                <small class="text-white opacity-75 text-sm">
                                                    <?php echo count($category_data['rooms']); ?> rooms available
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="p-4">
                                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                            <?php foreach ($category_data['rooms'] as $room): ?>
                                                <div class="room-item" 
                                                     data-room-id="<?php echo $room['id']; ?>"
                                                     data-room-no="<?php echo htmlspecialchars($room['room_number']); ?>"
                                                     data-price="<?php echo $room['price']; ?>"
                                                     data-category="<?php echo htmlspecialchars($category); ?>">
                                                    <div class="flex justify-between items-center">
                                                        <div>
                                                            <h6 class="font-semibold mb-1">
                                                                <i class="fas fa-door-open mr-1"></i>
                                                                Room <?php echo htmlspecialchars($room['room_number']); ?>
                                                            </h6>
                                                            <small class="text-gray-600 text-sm">
                                                                <?php echo htmlspecialchars($room['description']); ?>
                                                            </small>
                                                        </div>
                                                        <div class="text-right">
                                                            <div class="font-bold text-green-600">
                                                                BDT <?php echo number_format($room['price'], 2); ?>
                                                            </div>
                                                            <small class="text-green-600 text-sm">
                                                                <i class="fas fa-check-circle"></i> Available
                                                            </small>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Right Side - Booking Summary -->
                    <div class="lg:col-span-1">
                        <div class="booking-summary p-6">
                            <h4 class="text-lg font-semibold mb-6 text-gray-800">
                                <i class="fas fa-clipboard-list mr-2"></i>
                                Booking Summary
                            </h4>
                        
                        <!-- Guest Information Form -->
                        <form id="multipleBookingForm">
                            <div class="mb-4">
                                <label class="block text-sm font-semibold text-gray-700 mb-2">
                                    <i class="fas fa-user mr-1"></i> Guest Information
                                </label>
                            </div>
                            
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Full Name *</label>
                                    <input type="text" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" name="guest_name" required>
                                </div>
                                
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">NID Number *</label>
                                        <input type="text" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" name="nid_number" required>
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Phone *</label>
                                        <input type="tel" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" name="phone" required>
                                    </div>
                                </div>
                                
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                                        <input type="email" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" name="email">
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Profession</label>
                                        <input type="text" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" name="profession">
                                    </div>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                                    <textarea class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" name="address" rows="2"></textarea>
                                </div>
                            </div>
                            
                            <hr class="my-6 border-gray-200">
                            
                            <!-- Booking Details -->
                            <div class="mb-4">
                                <label class="block text-sm font-semibold text-gray-700 mb-2">
                                    <i class="fas fa-calendar mr-1"></i> Booking Details
                                </label>
                            </div>
                            
                            <div class="space-y-4">
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Check-in Date *</label>
                                        <input type="date" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" name="checkin_date" required 
                                               min="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Check-out Date *</label>
                                        <input type="date" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" name="checkout_date" required
                                               min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>">
                                    </div>
                                </div>
                                
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Check-in Time (After 10:50 AM)</label>
                                        <input type="time" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" name="checkin_time" value="11:00" min="10:50" step="300">
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Check-out Time (Before 11:00 AM)</label>
                                        <input type="time" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" name="checkout_time" value="10:50" max="11:00" step="300">
                                    </div>
                                </div>
                                
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Number of Guests</label>
                                        <input type="number" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" name="num_guests" value="1" min="1">
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Total Amount (BDT)</label>
                                        <input type="text" id="total" class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50 font-semibold text-lg" readonly value="0.00">
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Discount (BDT)</label>
                                        <input type="number" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" name="discount" value="0" min="0" step="0.01">
                                    </div>
                                </div>
                                
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Paid Amount (BDT)</label>
                                        <input type="number" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" name="paid" value="0" min="0" step="0.01">
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Booking Type</label>
                                        <select class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" name="booking_type">
                                            
                                             <option value="online">Online</option>
                                            <option value="offline">Offline</option>
                                           
                                            <option value="phone">Phone</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Reference (Optional)</label>
                                        <input type="text" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" name="reference" placeholder="Optional reference">
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Note (Optional)</label>
                                        <textarea class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" name="note" rows="2" placeholder="Optional notes"></textarea>
                                    </div>
                                </div>
                            </div>
                            
                            <hr class="my-6 border-gray-200">
                            
                            <!-- Meal Add-ons -->
                            <div class="mb-6">
                                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                                    <label class="block text-lg font-semibold text-yellow-800 mb-3">
                                        <i class="fas fa-utensils mr-2"></i> Meal Add-ons (Optional)
                                    </label>
                                    <p class="text-yellow-700 text-sm mb-4">Add meal costs to the total booking amount. Prices and quantities will be included in the final calculation.</p>
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                        <div class="bg-white rounded-lg p-4 border border-yellow-200">
                                            <label class="block text-sm font-semibold text-gray-700 mb-2">
                                                <i class="fas fa-coffee mr-1"></i> Breakfast
                                            </label>
                                            <div class="space-y-2">
                                                <div>
                                                    <label class="block text-xs text-gray-600 mb-1">Price per meal (BDT) - Optional</label>
                                                    <input type="number" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:border-transparent" name="breakfast_price" placeholder="0.00 (Free)" value="0" min="0" step="0.01" onchange="calculateMealTotals()" oninput="calculateMealTotals()">
                                                </div>
                                                <div>
                                                    <label class="block text-xs text-gray-600 mb-1">Quantity</label>
                                                    <input type="number" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:border-transparent" name="breakfast_quantity" placeholder="0" value="0" min="0" onchange="calculateMealTotals()" oninput="calculateMealTotals()">
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
                                        
                                        <div class="bg-white rounded-lg p-4 border border-yellow-200">
                                            <label class="block text-sm font-semibold text-gray-700 mb-2">
                                                <i class="fas fa-hamburger mr-1"></i> Lunch
                                            </label>
                                            <div class="space-y-2">
                                                <div>
                                                    <label class="block text-xs text-gray-600 mb-1">Price per meal (BDT) - Optional</label>
                                                    <input type="number" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:border-transparent" name="lunch_price" placeholder="0.00 (Free)" value="0" min="0" step="0.01" onchange="calculateMealTotals()" oninput="calculateMealTotals()">
                                                </div>
                                                <div>
                                                    <label class="block text-xs text-gray-600 mb-1">Quantity</label>
                                                    <input type="number" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:border-transparent" name="lunch_quantity" placeholder="0" value="0" min="0" onchange="calculateMealTotals()" oninput="calculateMealTotals()">
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
                                        
                                        <div class="bg-white rounded-lg p-4 border border-yellow-200">
                                            <label class="block text-sm font-semibold text-gray-700 mb-2">
                                                <i class="fas fa-utensils mr-1"></i> Dinner
                                            </label>
                                            <div class="space-y-2">
                                                <div>
                                                    <label class="block text-xs text-gray-600 mb-1">Price per meal (BDT) - Optional</label>
                                                    <input type="number" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:border-transparent" name="dinner_price" placeholder="0.00 (Free)" value="0" min="0" step="0.01" onchange="calculateMealTotals()" oninput="calculateMealTotals()">
                                                </div>
                                                <div>
                                                    <label class="block text-xs text-gray-600 mb-1">Quantity</label>
                                                    <input type="number" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:border-transparent" name="dinner_quantity" placeholder="0" value="0" min="0" onchange="calculateMealTotals()" oninput="calculateMealTotals()">
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
                                    
                                    <div class="mt-4 bg-green-50 border border-green-200 rounded-lg p-3">
                                        <div class="flex justify-between items-center">
                                            <span class="text-sm font-semibold text-green-800">Total Meal Cost:</span>
                                            <span class="text-lg font-bold text-green-600">BDT <span id="total_meal_cost">0.00</span></span>
                                        </div>
                                    </div>
                                    

                                </div>
                            </div>
                            
                            <hr class="my-6 border-gray-200">
                            
                            <!-- Selected Rooms -->
                            <div class="mb-4">
                                <label class="block text-sm font-semibold text-gray-700 mb-2">
                                    <i class="fas fa-bed mr-1"></i> Selected Rooms
                                    <span class="bg-blue-600 text-white px-2 py-1 rounded-full text-xs font-medium ml-2" id="selectedCount">0</span>
                                </label>
                                <div class="selected-rooms bg-gray-50 rounded-lg p-3" id="selectedRooms">
                                    <div class="text-gray-500 text-center py-6">
                                        <i class="fas fa-bed text-3xl mb-3"></i>
                                        <p class="mb-1">No rooms selected</p>
                                        <small class="text-sm">Click on rooms to add them</small>
                                    </div>
                                </div>
                            </div>
                            

                            
                            <!-- Submit Button -->
                            <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-semibold py-3 px-6 rounded-lg shadow transition duration-200 mt-4 disabled:opacity-50 disabled:cursor-not-allowed" id="bookButton" disabled>
                                <i class="fas fa-check mr-2"></i>
                                Book Selected Rooms
                            </button>
                            
                            <!-- Test Modal Button (for debugging) -->
                           

                        </form>
                        
                        <!-- Loading -->
                        <div class="loading text-center py-6" id="loading">
                            <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
                            <p class="mt-2 text-gray-600">Processing booking...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

                    <!-- Success Modal -->
                <div id="successModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-[9999] flex items-center justify-center">
        <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
            <div class="bg-green-500 text-white px-6 py-4 rounded-t-lg">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-semibold">
                        <i class="fas fa-check-circle mr-2"></i>
                        Booking Successful!
                    </h3>
                    <button onclick="closeSuccessModal()" class="text-white hover:text-gray-200">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            
            <div class="p-6">
                <div class="text-center mb-6">
                    <i class="fas fa-check-circle text-6xl text-green-500 mb-4"></i>
                    <h4 class="text-xl font-semibold text-gray-800 mb-2">Booking Confirmed</h4>
                    <p class="text-gray-600" id="modalSuccessMessage">Your booking has been successfully processed.</p>
                </div>
                
                <div class="bg-gray-50 rounded-lg p-4 mb-6">
                    <h5 class="font-semibold text-gray-800 mb-3">Booking Details:</h5>
                    <div class="grid grid-cols-2 gap-4 text-sm">
                        <div class="space-y-2">
                            <div class="flex justify-between">
                                <span class="text-gray-600">Booking ID:</span>
                                <span class="font-medium" id="modalBookingIds">-</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Guest Name:</span>
                                <span class="font-medium" id="modalGuestName">-</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Phone:</span>
                                <span class="font-medium" id="modalGuestPhone">-</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Email:</span>
                                <span class="font-medium" id="modalGuestEmail">-</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">NID:</span>
                                <span class="font-medium" id="modalGuestNID">-</span>
                            </div>
                        </div>
                        <div class="space-y-2">
                            <div class="flex justify-between">
                                <span class="text-gray-600">Check-in:</span>
                                <span class="font-medium" id="modalCheckin">-</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Check-out:</span>
                                <span class="font-medium" id="modalCheckout">-</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Rooms:</span>
                                <span class="font-medium" id="modalRooms">-</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Total Amount:</span>
                                <span class="font-medium text-green-600" id="modalTotalAmount">-</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Status:</span>
                                <span class="font-medium text-blue-600" id="modalStatus">-</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="bg-blue-50 rounded-lg p-4 mb-6">
                    <h5 class="font-semibold text-blue-800 mb-3">What would you like to do next?</h5>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <button onclick="printBookingPDF()" class="bg-blue-500 hover:bg-blue-600 text-white py-3 px-4 rounded-lg font-medium transition duration-200 flex items-center justify-center">
                            <i class="fas fa-print mr-2"></i>
                            Print PDF
                        </button>
                        <button onclick="sendBookingEmail()" class="bg-green-500 hover:bg-green-600 text-white py-3 px-4 rounded-lg font-medium transition duration-200 flex items-center justify-center">
                            <i class="fas fa-envelope mr-2"></i>
                            Send Email
                        </button>
                        <button onclick="downloadReceipt()" class="bg-purple-500 hover:bg-purple-600 text-white py-3 px-4 rounded-lg font-medium transition duration-200 flex items-center justify-center">
                            <i class="fas fa-download mr-2"></i>
                            Download PDF
                        </button>
                    </div>
                    <div class="mt-3 text-center">
                        <button onclick="closeSuccessModal()" class="bg-gray-500 hover:bg-gray-600 text-white py-2 px-6 rounded-lg font-medium transition duration-200">
                            <i class="fas fa-times mr-2"></i>
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let selectedRooms = [];
        let totalAmount = 0;
        
        // Room selection functionality
        document.querySelectorAll('.room-item').forEach(room => {
            room.addEventListener('click', function() {
                const roomId = this.dataset.roomId;
                const roomNo = this.dataset.roomNo;
                const price = parseFloat(this.dataset.price);
                const category = this.dataset.category;
                
                if (this.classList.contains('selected')) {
                    // Remove room
                    this.classList.remove('selected');
                    selectedRooms = selectedRooms.filter(r => r.id !== roomId);
                } else {
                    // Add room
                    this.classList.add('selected');
                    selectedRooms.push({
                        id: roomId,
                        roomNo: roomNo,
                        price: price,
                        category: category
                    });
                }
                
                updateSelectedRooms();
                updateTotal();
            });
        });
        
        function updateSelectedRooms() {
            const container = document.getElementById('selectedRooms');
            const count = document.getElementById('selectedCount');
            
            if (count) {
                count.textContent = selectedRooms.length;
            }
            
            if (container) {
                if (selectedRooms.length === 0) {
                    container.innerHTML = `
                        <div class="text-gray-500 text-center py-6">
                            <i class="fas fa-bed text-3xl mb-3"></i>
                            <p class="mb-1">No rooms selected</p>
                            <small class="text-sm">Click on rooms to add them</small>
                        </div>
                    `;
                } else {
                                    // Calculate nights for display
                const checkinDate = document.querySelector('input[name="checkin_date"]').value;
                const checkoutDate = document.querySelector('input[name="checkout_date"]').value;
                
                let nights = 1;
                if (checkinDate && checkoutDate) {
                    const checkin = new Date(checkinDate);
                    const checkout = new Date(checkoutDate);
                    const diffTime = checkout.getTime() - checkin.getTime();
                    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                    nights = diffDays > 0 ? diffDays : 1;
                }
                
                container.innerHTML = selectedRooms.map(room => {
                    const totalForStay = room.price * nights;
                    return `
                        <div class="selected-room-item">
                            <div class="flex justify-between items-center">
                                <div>
                                    <h6 class="font-semibold mb-1">Room ${room.roomNo}</h6>
                                    <small class="text-gray-600 text-sm">${room.category}</small>
                                    <small class="text-gray-500 text-xs block">${nights} night${nights > 1 ? 's' : ''} × ৳${room.price.toFixed(2)}</small>
                                </div>
                                <div class="text-right">
                                    <div class="font-bold text-green-600">৳${totalForStay.toFixed(2)}</div>
                                    <i class="fas fa-times remove-room cursor-pointer" onclick="removeRoom('${room.id}')"></i>
                                </div>
                            </div>
                        </div>
                    `;
                }).join('');
                }
            }
        }
        
        function removeRoom(roomId) {
            selectedRooms = selectedRooms.filter(r => r.id !== roomId);
            const roomElement = document.querySelector(`[data-room-id="${roomId}"]`);
            if (roomElement) {
                roomElement.classList.remove('selected');
            }
            updateSelectedRooms();
            updateTotal();
        }
        
        function updateTotal() {
            // Calculate number of nights
            const checkinDate = document.querySelector('input[name="checkin_date"]').value;
            const checkoutDate = document.querySelector('input[name="checkout_date"]').value;
            
            let nights = 1; // Default to 1 night
            if (checkinDate && checkoutDate) {
                const checkin = new Date(checkinDate);
                const checkout = new Date(checkoutDate);
                const diffTime = checkout.getTime() - checkin.getTime();
                const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                nights = diffDays > 0 ? diffDays : 1;
            }
            
            // Calculate room cost based on nights
            const roomCost = selectedRooms.reduce((sum, room) => sum + (room.price * nights), 0);
            const discount = parseFloat(document.querySelector('input[name="discount"]').value) || 0;
            const discountAmount = discount; // Direct discount amount, not percentage
            
            // Get meal totals directly from the total meal cost display
            const mealTotal = parseFloat(document.getElementById('total_meal_cost').textContent) || 0;
            
            // Total Amount = Room Prices + Meal Prices
            const totalAmount = roomCost + mealTotal;
            
            // Amount to be Paid = Total Amount - Discount
            const total = totalAmount - discountAmount;
            
            // Update the total field in the form (this element exists)
            const totalElement = document.getElementById('total');
            if (totalElement) {
                totalElement.value = total.toFixed(2);
            }
            
            // Update total display with BDT formatting
            const totalDisplayElement = document.getElementById('totalDisplay');
            if (totalDisplayElement) {
                totalDisplayElement.textContent = 'BDT ' + total.toFixed(2);
            }
            
            // Calculate and update due amount
            const paidAmount = parseFloat(document.querySelector('input[name="paid"]').value) || 0;
            const dueAmount = Math.max(0, total - paidAmount);
            
            const dueElement = document.getElementById('due');
            if (dueElement) {
                dueElement.value = dueAmount.toFixed(2);
            }
            
            const dueDisplayElement = document.getElementById('dueDisplay');
            if (dueDisplayElement) {
                dueDisplayElement.textContent = 'BDT ' + dueAmount.toFixed(2);
            }
            
            // Debug logging
            console.log('Total calculation:', {
                nights: nights,
                roomCost: roomCost,
                mealTotal: mealTotal,
                discount: discountAmount,
                finalTotal: total,
                paidAmount: paidAmount,
                dueAmount: dueAmount
            });
            
            // Enable/disable book button
            const bookButton = document.getElementById('bookButton');
            if (bookButton) {
                bookButton.disabled = selectedRooms.length === 0;
            }
        }
        
        // Calculate meal totals
        function calculateMealTotals() {
            const breakfastPrice = parseFloat(document.querySelector('input[name="breakfast_price"]').value) || 0;
            const breakfastQty = parseFloat(document.querySelector('input[name="breakfast_quantity"]').value) || 0;
            const breakfastTotal = breakfastPrice * breakfastQty;
            
            const lunchPrice = parseFloat(document.querySelector('input[name="lunch_price"]').value) || 0;
            const lunchQty = parseFloat(document.querySelector('input[name="lunch_quantity"]').value) || 0;
            const lunchTotal = lunchPrice * lunchQty;
            
            const dinnerPrice = parseFloat(document.querySelector('input[name="dinner_price"]').value) || 0;
            const dinnerQty = parseFloat(document.querySelector('input[name="dinner_quantity"]').value) || 0;
            const dinnerTotal = dinnerPrice * dinnerQty;
            
            const totalMealCost = breakfastTotal + lunchTotal + dinnerTotal;
            
            // Debug logging
            console.log('Meal calculation:', {
                breakfast: { price: breakfastPrice, qty: breakfastQty, total: breakfastTotal },
                lunch: { price: lunchPrice, qty: lunchQty, total: lunchTotal },
                dinner: { price: dinnerPrice, qty: dinnerQty, total: dinnerTotal },
                totalMealCost: totalMealCost
            });
            
            // Update status and total displays
            const breakfastStatusElement = document.getElementById('breakfast_status');
            const breakfastTotalElement = document.getElementById('breakfast_total');
            const lunchStatusElement = document.getElementById('lunch_status');
            const lunchTotalElement = document.getElementById('lunch_total');
            const dinnerStatusElement = document.getElementById('dinner_status');
            const dinnerTotalElement = document.getElementById('dinner_total');
            const totalMealCostElement = document.getElementById('total_meal_cost');
            
            // Breakfast status and total
            if (breakfastQty > 0) {
                if (breakfastPrice > 0) {
                    breakfastStatusElement.textContent = `${breakfastQty}x Breakfast (৳${breakfastPrice.toFixed(2)} each)`;
                    breakfastStatusElement.className = 'text-green-600';
                } else {
                    breakfastStatusElement.textContent = `${breakfastQty}x Breakfast (FREE)`;
                    breakfastStatusElement.className = 'text-blue-600';
                }
                breakfastTotalElement.textContent = breakfastPrice > 0 ? `BDT ${breakfastTotal.toFixed(2)}` : 'FREE';
            } else {
                breakfastStatusElement.textContent = 'Not selected';
                breakfastStatusElement.className = 'text-gray-500';
                breakfastTotalElement.textContent = 'BDT 0.00';
            }
            
            // Lunch status and total
            if (lunchQty > 0) {
                if (lunchPrice > 0) {
                    lunchStatusElement.textContent = `${lunchQty}x Lunch (৳${lunchPrice.toFixed(2)} each)`;
                    lunchStatusElement.className = 'text-green-600';
                } else {
                    lunchStatusElement.textContent = `${lunchQty}x Lunch (FREE)`;
                    lunchStatusElement.className = 'text-blue-600';
                }
                lunchTotalElement.textContent = lunchPrice > 0 ? `BDT ${lunchTotal.toFixed(2)}` : 'FREE';
            } else {
                lunchStatusElement.textContent = 'Not selected';
                lunchStatusElement.className = 'text-gray-500';
                lunchTotalElement.textContent = 'BDT 0.00';
            }
            
            // Dinner status and total
            if (dinnerQty > 0) {
                if (dinnerPrice > 0) {
                    dinnerStatusElement.textContent = `${dinnerQty}x Dinner (৳${dinnerPrice.toFixed(2)} each)`;
                    dinnerStatusElement.className = 'text-green-600';
                } else {
                    dinnerStatusElement.textContent = `${dinnerQty}x Dinner (FREE)`;
                    dinnerStatusElement.className = 'text-blue-600';
                }
                dinnerTotalElement.textContent = dinnerPrice > 0 ? `BDT ${dinnerTotal.toFixed(2)}` : 'FREE';
            } else {
                dinnerStatusElement.textContent = 'Not selected';
                dinnerStatusElement.className = 'text-gray-500';
                dinnerTotalElement.textContent = 'BDT 0.00';
            }
            
            // Total meal cost
            if (totalMealCostElement) {
                totalMealCostElement.textContent = totalMealCost > 0 ? `BDT ${totalMealCost.toFixed(2)}` : 'FREE';
            }
            
            // Update total calculation to include meals
            updateTotal();
        }
        
        // Update total when discount changes
        document.querySelector('input[name="discount"]').addEventListener('input', updateTotal);
        
        // Update due amount when paid amount changes
        document.querySelector('input[name="paid"]').addEventListener('input', updateTotal);
        
        // Update meal totals when meal inputs change
        document.querySelectorAll('input[name*="price"], input[name*="quantity"]').forEach(input => {
            input.addEventListener('input', calculateMealTotals);
        });
        
        // Update total when dates change
        document.querySelectorAll('input[name="checkin_date"], input[name="checkout_date"]').forEach(input => {
            input.addEventListener('change', updateTotal);
        });
        
        // Calculate meal totals on page load
        document.addEventListener('DOMContentLoaded', function() {
            calculateMealTotals();
            
            // Debug: Check if modal elements exist
            console.log('Page loaded, checking modal elements...');
            const modal = document.getElementById('successModal');
            if (modal) {
                console.log('✅ Modal element found');
            } else {
                console.error('❌ Modal element not found');
            }
            
            // Check all required elements
            const requiredElements = [
                'modalBookingIds', 'modalGuestName', 'modalGuestPhone', 
                'modalGuestEmail', 'modalGuestNID', 'modalCheckin', 
                'modalCheckout', 'modalRooms', 'modalTotalAmount', 'modalStatus'
            ];
            
            requiredElements.forEach(id => {
                const element = document.getElementById(id);
                if (element) {
                    console.log(`✅ Element ${id} found`);
                } else {
                    console.error(`❌ Element ${id} not found`);
                }
            });
        });
        

        
        // Form submission
        document.getElementById('multipleBookingForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            console.log('Form submission started');
            
            if (selectedRooms.length === 0) {
                alert('Please select at least one room.');
                return;
            }
            
            // Validate time constraints
            const checkinTime = document.querySelector('input[name="checkin_time"]').value;
            const checkoutTime = document.querySelector('input[name="checkout_time"]').value;
            
            if (checkinTime < '10:50') {
                alert('Check-in time must be after 10:50 AM');
                return;
            }
            
            if (checkoutTime > '11:00') {
                alert('Check-out time must be before 11:00 AM');
                return;
            }
            
            const formData = new FormData(this);
            formData.append('rooms', JSON.stringify(selectedRooms));
            formData.append('hotel_id', '<?php echo $hotel_id; ?>');
            
            // Calculate and add meal totals
            calculateMealTotals(); // Ensure meal totals are up to date
            
            // Get meal totals - handle both priced and free meals
            const breakfastTotalText = document.getElementById('breakfast_total').textContent || 'BDT 0.00';
            const lunchTotalText = document.getElementById('lunch_total').textContent || 'BDT 0.00';
            const dinnerTotalText = document.getElementById('dinner_total').textContent || 'BDT 0.00';
            
            console.log('Meal totals before parsing:', {
                breakfast: breakfastTotalText,
                lunch: lunchTotalText,
                dinner: dinnerTotalText
            });
            
            // Parse meal totals, handling "FREE" text
            const parseMealTotal = (text) => {
                if (text.includes('FREE')) return 0;
                return parseFloat(text.replace('BDT', '').replace(',', '').trim()) || 0;
            };
            
            const breakfastTotal = parseMealTotal(breakfastTotalText);
            const lunchTotal = parseMealTotal(lunchTotalText);
            const dinnerTotal = parseMealTotal(dinnerTotalText);
            const mealTotal = breakfastTotal + lunchTotal + dinnerTotal;
            
            console.log('Meal totals after parsing:', {
                breakfast: breakfastTotal,
                lunch: lunchTotal,
                dinner: dinnerTotal,
                total: mealTotal
            });
            
            formData.append('breakfast_total', breakfastTotal);
            formData.append('lunch_total', lunchTotal);
            formData.append('dinner_total', dinnerTotal);
            formData.append('meal_total', mealTotal);
            
            // Add paid amount
            const paidAmount = parseFloat(document.querySelector('input[name="paid"]').value) || 0;
            formData.append('paid', paidAmount);
            
            // Show loading
            document.getElementById('loading').style.display = 'block';
            document.getElementById('bookButton').disabled = true;
            

            
            fetch('php/process_multiple_booking.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                console.log('Response status:', response.status);
                return response.json();
            })
            .then(data => {
                console.log('Response data:', data);
                document.getElementById('loading').style.display = 'none';
                
                if (data.success) {
                    console.log('Booking successful, showing modal...');
                    console.log('Modal data:', {
                        booking_id: data.booking_id,
                        guest_name: document.querySelector('input[name="guest_name"]').value,
                        total_amount: document.getElementById('total').value,
                        room_numbers: data.room_numbers || []
                    });
                    
                    // Debug: Check if modal element exists
                    const modalElement = document.getElementById('successModal');
                    console.log('Modal element check:', modalElement);
                    if (!modalElement) {
                        console.error('❌ Modal element not found!');
                        alert('Modal element not found. Please refresh the page and try again.');
                        return;
                    }
                    
                    // Debug: Check if showSuccessModal function exists
                    if (typeof showSuccessModal !== 'function') {
                        console.error('❌ showSuccessModal function not found!');
                        alert('Modal function not found. Please refresh the page and try again.');
                        return;
                    }
                    
                    console.log('✅ Modal element and function found, calling showSuccessModal...');
                    
                    // Show success modal with booking details from server response
                    const modalData = {
                        booking_id: data.booking_id,
                        booking_ids: data.booking_ids || [data.booking_id], // Use all booking IDs
                        guest_name: data.guest_name || document.querySelector('input[name="guest_name"]').value,
                        guest_phone: data.guest_phone,
                        guest_email: data.guest_email,
                        guest_nid: data.guest_nid,
                        guest_profession: data.guest_profession,
                        guest_address: data.guest_address,
                        checkin_date: data.checkin_date,
                        checkout_date: data.checkout_date,
                        checkin_time: data.checkin_time,
                        checkout_time: data.checkout_time,
                        nights: data.nights,
                        total_amount: data.total_amount,
                        rooms_count: selectedRooms.length,
                        room_numbers: data.room_numbers || [],
                        num_guests: data.num_guests,
                        discount: data.discount,
                        paid: data.paid,
                        due: data.due,
                        meal_total: data.meal_total,
                        pdf_file: data.pdf_file
                    };
                    
                    console.log('About to show modal with data:', modalData);
                    
                    // Force modal to show immediately
                    setTimeout(() => {
                        showSuccessModal(modalData);
                        
                        // Fallback: if modal doesn't show, try again
                        setTimeout(() => {
                            const modal = document.getElementById('successModal');
                            if (modal && modal.classList.contains('hidden')) {
                                console.log('Modal still hidden, forcing show...');
                                modal.classList.remove('hidden');
                                modal.style.opacity = '1';
                                modal.style.transform = 'scale(1)';
                            }
                        }, 500);
                    }, 100);
                    
                    // Reset form
                    this.reset();
                    selectedRooms = [];
                    updateSelectedRooms();
                    updateTotal();
                    document.querySelectorAll('.room-item.selected').forEach(room => {
                        room.classList.remove('selected');
                    });
                    
                    // Reset meal totals
                    calculateMealTotals();
                } else {
                    console.log('Booking failed:', data.message);
                    console.log('Full error response:', data);
                    
                    // Show more detailed error message
                    let errorMessage = 'Booking failed: ' + data.message;
                    if (data.details) {
                        errorMessage += '\n\nDetails: ' + data.details;
                    }
                    alert(errorMessage);
                    document.getElementById('bookButton').disabled = false;
                }
            })
            .catch(error => {
                document.getElementById('loading').style.display = 'none';
                document.getElementById('bookButton').disabled = false;
                
                console.error('Fetch error:', error);
                
                // Try to get more specific error information
                if (error.message) {
                    alert('Error: ' + error.message);
                } else {
                    alert('An error occurred. Please try again. Check console for details.');
                }
            });
        });
        
        // Set minimum checkout date based on checkin date
        document.querySelector('input[name="checkin_date"]').addEventListener('change', function() {
            const checkinDate = new Date(this.value);
            const checkoutDate = new Date(checkinDate);
            checkoutDate.setDate(checkoutDate.getDate() + 1);
            
            document.querySelector('input[name="checkout_date"]').min = checkoutDate.toISOString().split('T')[0];
            document.querySelector('input[name="checkout_date"]').value = checkoutDate.toISOString().split('T')[0];
        });
        
        // Time validation
        document.querySelector('input[name="checkin_time"]').addEventListener('change', function() {
            const time = this.value;
            if (time < '10:50') {
                alert('Check-in time must be after 10:50 AM');
                this.value = '11:00';
            }
        });
        
        document.querySelector('input[name="checkout_time"]').addEventListener('change', function() {
            const time = this.value;
            if (time > '11:00') {
                alert('Check-out time must be before 11:00 AM');
                this.value = '10:50';
            }
        });
        
        // Helper function to format dates
        function formatDate(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString('en-GB', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric'
            });
        }
        
        // Date filter functionality
        function filterAvailableRooms() {
            const checkinDate = document.getElementById('filterCheckinDate').value;
            const checkoutDate = document.getElementById('filterCheckoutDate').value;
            
            if (!checkinDate || !checkoutDate) {
                alert('Please select both check-in and check-out dates.');
                return;
            }
            
            if (checkinDate >= checkoutDate) {
                alert('Check-out date must be after check-in date.');
                return;
            }
            
            // Validate that checkout is at least 1 day after checkin
            const checkin = new Date(checkinDate);
            const checkout = new Date(checkoutDate);
            const diffTime = checkout - checkin;
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
            
            if (diffDays < 1) {
                alert('Check-out date must be at least 1 day after check-in date.');
                return;
            }
            
            // Update the date display
            const dateDisplay = document.getElementById('selectedDatesDisplay');
            if (dateDisplay) {
                dateDisplay.innerHTML = `
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 mb-4">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-4">
                                <i class="fas fa-calendar-alt text-blue-600"></i>
                                <span class="text-blue-800 font-medium">
                                    Check-in: ${formatDate(checkinDate)} | Check-out: ${formatDate(checkoutDate)}
                                </span>
                            </div>
                            <span class="text-blue-600 text-sm">
                                ${diffDays} night${diffDays > 1 ? 's' : ''}
                            </span>
                        </div>
                    </div>
                `;
            }
            
            // Show loading
            const roomContainer = document.querySelector('.lg\\:col-span-2');
            roomContainer.innerHTML = `
                <div class="text-center py-12">
                    <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600 mb-4"></div>
                    <p class="text-gray-600">Checking room availability...</p>
                </div>
            `;
            
            const requestData = {
                hotel_id: <?php echo $hotel_id; ?>,
                checkin_date: checkinDate,
                checkout_date: checkoutDate
            };
            
            // Fetch available rooms for selected dates
            fetch('php/get_available_rooms.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(requestData)
            })
            .then(response => response.json())
            .then(data => {
                if (data && data.success) {
                    // Update room display
                    updateRoomDisplay(data.rooms);
                    
                    // Update form dates
                    document.querySelector('input[name="checkin_date"]').value = checkinDate;
                    document.querySelector('input[name="checkout_date"]').value = checkoutDate;
                    
                    // Reset selections
                    selectedRooms = [];
                    updateSelectedRooms();
                    updateTotal();
                    
                    // Remove selected class from all rooms
                    document.querySelectorAll('.room-item.selected').forEach(room => {
                        room.classList.remove('selected');
                    });
                } else {
                    const errorMsg = data && data.message ? data.message : 'Unknown error occurred';
                    alert('Error: ' + errorMsg);
                    location.reload();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error checking room availability. Please try again.');
                location.reload();
            });
        }
        
        function updateRoomDisplay(roomsData) {
            const roomContainer = document.querySelector('.lg\\:col-span-2');
            
            if (!roomsData || Object.keys(roomsData).length === 0) {
                roomContainer.innerHTML = `
                    <div class="text-center py-12">
                        <i class="fas fa-bed text-6xl text-gray-400 mb-4"></i>
                        <h4 class="text-gray-500 text-xl mb-2">No Available Rooms</h4>
                        <p class="text-gray-400">No rooms are available for the selected dates.</p>
                    </div>
                `;
                return;
            }
            
            let html = '';
            for (const [category, categoryData] of Object.entries(roomsData)) {
                html += `
                    <div class="category-card mb-6">
                        <div class="category-header">
                            <div class="flex justify-between items-center">
                                <div>
                                    <h4 class="text-lg font-semibold mb-1">
                                        <i class="fas fa-door-open mr-2"></i>
                                        ${categoryData.name}
                                    </h4>
                                    <p class="text-white opacity-75 text-sm">
                                        ${categoryData.description}
                                    </p>
                                </div>
                                <div class="text-right">
                                    <div class="price-tag">
                                        ৳${parseFloat(categoryData.price).toFixed(2)} / night
                                    </div>
                                    <small class="text-white opacity-75 text-sm">
                                        ${categoryData.rooms.length} rooms available
                                    </small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="p-4">
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                `;
                
                categoryData.rooms.forEach(room => {
                    html += `
                        <div class="room-item" 
                             data-room-id="${room.id}"
                             data-room-no="${room.room_number}"
                             data-price="${room.price}"
                             data-category="${room.category}">
                            <div class="room-card">
                                <div class="room-header">
                                    <h5 class="font-semibold">Room ${room.room_number}</h5>
                                    <span class="category-badge">${room.category}</span>
                                </div>
                                <div class="room-price">
                                    ৳${parseFloat(room.price).toFixed(2)} / night
                                </div>
                                <div class="room-status">
                                    <small class="text-green-600 font-medium">
                                        <i class="fas fa-check-circle"></i> Available
                                    </small>
                                </div>
                            </div>
                        </div>
                    `;
                });
                
                html += `
                            </div>
                        </div>
                    </div>
                `;
            }
            
            roomContainer.innerHTML = html;
            
            // Reattach event listeners
            document.querySelectorAll('.room-item').forEach(room => {
                room.addEventListener('click', function() {
                    const roomId = this.dataset.roomId;
                    const roomNo = this.dataset.roomNo;
                    const price = parseFloat(this.dataset.price);
                    const category = this.dataset.category;
                    
                    if (this.classList.contains('selected')) {
                        // Remove room
                        this.classList.remove('selected');
                        selectedRooms = selectedRooms.filter(r => r.id !== roomId);
                    } else {
                        // Add room
                        this.classList.add('selected');
                        selectedRooms.push({
                            id: roomId,
                            roomNo: roomNo,
                            price: price,
                            category: category
                        });
                    }
                    
                    updateSelectedRooms();
                    updateTotal();
                });
            });
        }
        


        
        // Global variables for success modal
        let bookingSuccessData = null;
        
        // Test function to directly test modal
        function testModalDirectly() {
            console.log('Testing modal directly...');
            
            // Test data
            const testData = {
                booking_id: 25,
                booking_ids: [25, 26, 27, 28],
                guest_name: 'Test Guest',
                total_amount: '৳8,400.00',
                rooms_count: 4,
                room_numbers: ['111', '701', '102', '105']
            };
            
            console.log('Test data:', testData);
            showSuccessModal(testData);
        }
        
        // Show success modal
        function showSuccessModal(data) {
            console.log('showSuccessModal called with data:', data);
            bookingSuccessData = data;
            
            // Update success message based on number of rooms
            const roomsCount = data.rooms_count || (data.room_numbers ? data.room_numbers.length : 0);
            const successMessage = roomsCount > 1 ? 
                'Your multiple room booking has been successfully processed.' : 
                'Your single room booking has been successfully processed.';
            document.getElementById('modalSuccessMessage').textContent = successMessage;
            
            // Update modal content with all details from server response
            document.getElementById('modalBookingIds').textContent = data.booking_id || 'N/A';
            document.getElementById('modalGuestName').textContent = data.guest_name || 'N/A';
            document.getElementById('modalGuestPhone').textContent = data.guest_phone || 'N/A';
            document.getElementById('modalGuestEmail').textContent = data.guest_email || 'N/A';
            document.getElementById('modalGuestNID').textContent = data.guest_nid || 'N/A';
            
            // Format dates in dd-mm-yy format
            const formatDate = (dateStr) => {
                if (!dateStr) return 'N/A';
                const date = new Date(dateStr);
                if (isNaN(date.getTime())) return 'N/A';
                const day = String(date.getDate()).padStart(2, '0');
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const year = String(date.getFullYear()).slice(-2);
                return `${day}-${month}-${year}`;
            };
            
            document.getElementById('modalCheckin').textContent = data.checkin_date ? 
                formatDate(data.checkin_date) + ' ' + (data.checkin_time || '') : 'N/A';
            document.getElementById('modalCheckout').textContent = data.checkout_date ? 
                formatDate(data.checkout_date) + ' ' + (data.checkout_time || '') : 'N/A';
            document.getElementById('modalRooms').textContent = (data.room_numbers && data.room_numbers.length > 0) ? 
                data.room_numbers.join(', ') : 'N/A';
            document.getElementById('modalTotalAmount').textContent = data.total_amount ? 
                '৳' + parseFloat(data.total_amount).toFixed(2) : '৳0.00';
            document.getElementById('modalStatus').textContent = 'Active';
            
            // Show modal with smooth animation
            const modal = document.getElementById('successModal');
            console.log('Modal element:', modal);
            if (modal) {
                modal.classList.remove('hidden');
                modal.style.opacity = '0';
                modal.style.transform = 'scale(0.9)';
                
                // Animate in
                setTimeout(() => {
                    modal.style.transition = 'all 0.3s ease-out';
                    modal.style.opacity = '1';
                    modal.style.transform = 'scale(1)';
                }, 10);
                
                console.log('Modal classes after removing hidden:', modal.className);
                console.log('Modal should now be visible');
            } else {
                console.error('Modal element not found!');
            }
        }
        
        // Close success modal
        function closeSuccessModal() {
            const modal = document.getElementById('successModal');
            if (modal) {
                // Animate out
                modal.style.transition = 'all 0.3s ease-in';
                modal.style.opacity = '0';
                modal.style.transform = 'scale(0.9)';
                
                // Hide after animation
                setTimeout(() => {
                    modal.classList.add('hidden');
                    modal.style.opacity = '';
                    modal.style.transform = '';
                    modal.style.transition = '';
                }, 300);
            }
            bookingSuccessData = null;
        }
        
        // Print booking PDF
        function printBookingPDF() {
            if (!bookingSuccessData) {
                alert('No booking data available. Please try again.');
                return;
            }
            
            try {
                // Show loading indicator
                const button = event.target;
                const originalText = button.innerHTML;
                button.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Generating...';
                button.disabled = true;
                
                // Open PDF in new window for printing
                const bookingId = bookingSuccessData.booking_id || bookingSuccessData.booking_ids[0];
                const pdfUrl = `php/generate_booking_pdf.php?booking_id=${bookingId}&hotel_id=<?php echo $hotel_id; ?>`;
                const newWindow = window.open(pdfUrl, '_blank');
                
                // Reset button after a short delay
                setTimeout(() => {
                    button.innerHTML = originalText;
                    button.disabled = false;
                }, 2000);
                
                if (!newWindow) {
                    alert('Please allow pop-ups for this site to print the PDF.');
                }
            } catch (error) {
                alert('Error generating PDF: ' + error.message);
                button.innerHTML = originalText;
                button.disabled = false;
            }
        }
        
        // Send booking email
        function sendBookingEmail() {
            if (!bookingSuccessData) return;
            
            // Use guest email from booking data
            let email = bookingSuccessData.guest_email;
            
            if (!email || email.trim() === '') {
                alert('No email address available for this guest. Please add an email address to the guest information.');
                return;
            }
            
            // Show loading
            const button = event.target;
            const originalText = button.innerHTML;
            button.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Sending...';
            button.disabled = true;
            
            const bookingId = bookingSuccessData.booking_id || bookingSuccessData.booking_ids[0];
            const bookingIds = bookingSuccessData.booking_ids || [bookingId];
            
            fetch('php/email_booking_pdf.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    booking_id: bookingId,
                    booking_ids: bookingIds,
                    email: email,
                    hotel_id: <?php echo $hotel_id; ?>
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('✅ Email sent successfully to ' + email);
                } else {
                    alert('❌ Failed to send email: ' + data.message);
                }
            })
            .catch(error => {
                alert('Error sending email: ' + error.message);
            })
            .finally(() => {
                button.innerHTML = originalText;
                button.disabled = false;
            });
        }
        
        // Download receipt
        function downloadReceipt() {
            if (!bookingSuccessData) {
                alert('No booking data available. Please try again.');
                return;
            }
            
            try {
                // Show loading indicator
                const button = event.target;
                const originalText = button.innerHTML;
                button.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Downloading...';
                button.disabled = true;
                
                const bookingId = bookingSuccessData.booking_id || bookingSuccessData.booking_ids[0];
                const bookingIds = bookingSuccessData.booking_ids || [bookingId];
                
                // Use booking_ids parameter for multiple bookings
                const downloadUrl = `php/download_booking_receipt.php?booking_ids=${bookingIds.join(',')}&hotel_id=<?php echo $hotel_id; ?>`;
                const newWindow = window.open(downloadUrl, '_blank');
                
                // Reset button after a short delay
                setTimeout(() => {
                    button.innerHTML = originalText;
                    button.disabled = false;
                }, 2000);
                
                if (!newWindow) {
                    alert('Please allow pop-ups for this site to download the PDF.');
                }
            } catch (error) {
                alert('Error downloading PDF: ' + error.message);
                button.innerHTML = originalText;
                button.disabled = false;
            }
        }
        

    </script>
</body>
</html> 