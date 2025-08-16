<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
require_once 'database/config.php';
require_once 'php/room_availability.php';

// Add cache-busting headers to prevent browser caching
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Set $user for sidebar and other references
$user = [
    'username' => $_SESSION['user_name'],
    'role' => $_SESSION['user_role']
];

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
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

// Fetch hotel info
$stmt = $pdo->prepare("SELECT name, description FROM hotels WHERE id = ?");
$stmt->execute([$hotel_id]);
$hotel = $stmt->fetch();

// Get rooms with pricing for this hotel - enhanced with room_categories data
$rooms_table = "rooms_hotel_{$hotel_id}";
$stmt = $pdo->prepare("
    SELECT r.*, rc.name as category_name, rc.description as category_description 
    FROM `$rooms_table` r 
    LEFT JOIN room_categories rc ON r.category = rc.name AND rc.hotel_id = ? 
    GROUP BY r.id
    ORDER BY r.room_number, r.id
");
$stmt->execute([$hotel_id]);
$rooms = $stmt->fetchAll();

// Get current room availability with 11 AM checkout rule
$current_availability = getCurrentRoomAvailability($pdo, $hotel_id);

// Debug: Show what hotel_id is being used and availability results
if (isset($_GET['debug'])) {
    echo "<div style='background: yellow; padding: 10px; margin: 10px; border: 2px solid red;'>";
    echo "<strong>DEBUG INFO:</strong><br>";
    echo "Hotel ID: $hotel_id<br>";
    echo "Total rooms: " . count($rooms) . "<br>";
    echo "Current availability results: " . count($current_availability) . "<br>";
    foreach ($current_availability as $room) {
        echo "Room {$room['room_number']} (ID: {$room['id']}): " . ($room['is_booked'] ? 'BOOKED' : 'AVAILABLE') . "<br>";
    }
    echo "Current time: " . date('H:i:s') . "<br>";
    echo "</div>";
}

// Create a lookup for availability status
$availability_lookup = [];
foreach ($current_availability as $room) {
    $availability_lookup[$room['id']] = $room['is_booked'];
}

// Enhance rooms data with booking status
foreach ($rooms as $key => $room) {
    $rooms[$key]['is_booked'] = isset($availability_lookup[$room['id']]) ? $availability_lookup[$room['id']] : false;
    $rooms[$key]['status'] = $rooms[$key]['is_booked'] ? 'booked' : 'available';
    
    // Add current time info for display
    $rooms[$key]['current_time'] = date('H:i:s');
    $rooms[$key]['checkout_time'] = '11:00:00';
}

// Group rooms by category
$grouped_rooms = [];
foreach ($rooms as $room) {
    $cat = $room['category'] ?: 'Uncategorized';
    if (!isset($grouped_rooms[$cat])) $grouped_rooms[$cat] = [];
    $grouped_rooms[$cat][] = $room;
}

// Fetch room categories from the database for this hotel
$stmt = $pdo->prepare("SELECT * FROM room_categories WHERE hotel_id = ? ORDER BY price ASC, name ASC");
$stmt->execute([$hotel_id]);
$room_categories = $stmt->fetchAll();

// Handle add, edit, delete actions (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_category'])) {
        $name = trim($_POST['name']);
        $description = trim($_POST['description']);
        $price = floatval($_POST['price']);
        $amenities = trim($_POST['amenities']);
        $icon = trim($_POST['icon']);
        $stmt = $pdo->prepare("INSERT INTO room_categories (hotel_id, name, description, price, amenities, icon) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$hotel_id, $name, $description, $price, $amenities, $icon]);
        header("Location: pricing.php?hotel_id=$hotel_id"); exit();
    } elseif (isset($_POST['edit_category'])) {
        $id = intval($_POST['category_id']);
        $name = trim($_POST['name']);
        $description = trim($_POST['description']);
        $price = floatval($_POST['price']);
        $amenities = trim($_POST['amenities']);
        $icon = trim($_POST['icon']);
        $stmt = $pdo->prepare("UPDATE room_categories SET name=?, description=?, price=?, amenities=?, icon=? WHERE id=? AND hotel_id=?");
        $stmt->execute([$name, $description, $price, $amenities, $icon, $id, $hotel_id]);
        header("Location: pricing.php?hotel_id=$hotel_id"); exit();
    } elseif (isset($_POST['delete_category'])) {
        $id = intval($_POST['category_id']);
        $stmt = $pdo->prepare("DELETE FROM room_categories WHERE id=? AND hotel_id=?");
        $stmt->execute([$id, $hotel_id]);
        header("Location: pricing.php?hotel_id=$hotel_id"); exit();
    }
}

// Handle room edit and delete actions (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['edit_room'])) {
        $room_id = intval($_POST['room_id']);
        $room_number = trim($_POST['room_number']);
        $category = trim($_POST['category']);
        $description = trim($_POST['description']);
        $price = floatval($_POST['price']);
        $status = trim($_POST['status']);
        $rooms_table = "rooms_hotel_{$hotel_id}";
        try {
            $stmt = $pdo->prepare("UPDATE `$rooms_table` SET room_number=?, category=?, description=?, price=?, status=? WHERE id=?");
            $stmt->execute([$room_number, $category, $description, $price, $status, $room_id]);
            header("Location: pricing.php?hotel_id=$hotel_id"); exit();
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $_SESSION['error'] = 'Room number already exists for this hotel!';
            } else {
                $_SESSION['error'] = 'Error: ' . $e->getMessage();
            }
            header("Location: pricing.php?hotel_id=$hotel_id"); exit();
        }
    } elseif (isset($_POST['delete_room'])) {
        $room_id = intval($_POST['room_id']);
        $rooms_table = "rooms_hotel_{$hotel_id}";
        $stmt = $pdo->prepare("DELETE FROM `$rooms_table` WHERE id=?");
        $stmt->execute([$room_id]);
        header("Location: pricing.php?hotel_id=$hotel_id"); exit();
    } elseif (isset($_POST['update_room_price'])) {
        $room_id = intval($_POST['room_id']);
        $price = floatval($_POST['price']);
        $rooms_table = "rooms_hotel_{$hotel_id}";
        $stmt = $pdo->prepare("UPDATE `$rooms_table` SET price = ? WHERE id = ?");
        $stmt->execute([$price, $room_id]);
        header("Location: pricing.php?hotel_id=$hotel_id"); exit();
    } elseif (isset($_POST['toggle_room_status'])) {
        $room_id = intval($_POST['room_id']);
        $new_status = $_POST['new_status'];
        $rooms_table = "rooms_hotel_{$hotel_id}";
        $stmt = $pdo->prepare("UPDATE `$rooms_table` SET status = ? WHERE id = ?");
        $stmt->execute([$new_status, $room_id]);
        header("Location: pricing.php?hotel_id=$hotel_id"); exit();
    }
}

// Define fixed room categories with features and prices
$fixed_categories = [
    [
        'name' => 'Standard Room',
        'description' => 'Comfortable rooms with basic amenities',
        'price' => 2500,
        'amenities' => [
            'Queen-size bed',
            'Private bathroom',
            'Free WiFi',
            'TV with cable',
        ],
    ],
    [
        'name' => 'Deluxe Room',
        'description' => 'Spacious rooms with premium amenities',
        'price' => 3500,
        'amenities' => [
            'King-size bed',
            'Premium bathroom',
            'High-speed WiFi',
            'Smart TV',
            'Mini refrigerator',
        ],
    ],
    [
        'name' => 'Suite Room',
        'description' => 'Luxury suites with separate living area',
        'price' => 5000,
        'amenities' => [
            'Separate bedroom & living room',
            'Luxury bathroom',
            'Premium WiFi',
            'Large Smart TV',
            'Full kitchen',
            'Balcony view',
        ],
    ],
    [
        'name' => 'Executive Room',
        'description' => 'Modern rooms for business travelers',
        'price' => 4000,
        'amenities' => [
            'King-size bed',
            'Work desk',
            'Coffee maker',
            'High-speed WiFi',
            'Smart TV',
        ],
    ],
    [
        'name' => 'Family Room',
        'description' => 'Spacious rooms for families',
        'price' => 4500,
        'amenities' => [
            'Two queen-size beds',
            'Sofa bed',
            'Mini refrigerator',
            'Kids play area access',
            'TV with cable',
        ],
    ],
];

// Build a map of category => description for fallback
$category_descriptions = [];
foreach ($room_categories as $cat) {
    $category_descriptions[$cat['name']] = $cat['description'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hotel - Pricing</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .sidebar {
            background: linear-gradient(180deg, #667eea 0%, #764ba2 100%);
        }
        .price-card {
            transition: all 0.3s ease;
        }
        .price-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Sidebar -->
    <div class="fixed inset-y-0 left-0 w-64 sidebar shadow-lg">
        <div class="flex items-center justify-center h-16 bg-white bg-opacity-10">
            <div class="flex items-center space-x-3">
                <i class="fas fa-hotel text-2xl text-white"></i>
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
            
            <a href="calendar.php?hotel_id=<?php echo $hotel_id; ?>" class="flex items-center px-6 py-3 text-white hover:bg-white hover:bg-opacity-20 transition-colors">
                <i class="fas fa-calendar-alt mr-3"></i>
                Calendar View
            </a>
            
            <a href="multiple_booking.php?hotel_id=<?php echo $hotel_id; ?>" class="flex items-center px-6 py-3 text-white hover:bg-white hover:bg-opacity-20 transition-colors">
                <i class="fas fa-bed mr-3"></i>
                Multiple Booking
            </a>
            
                            <a href="manage_bookings.php?hotel_id=<?php echo $hotel_id; ?>" class="flex items-center px-6 py-3 text-white hover:bg-white hover:bg-opacity-20 transition-colors">
                    <i class="fas fa-list mr-3"></i>
                    Manage Bookings
                </a>
             
            <a href="pricing.php?hotel_id=<?php echo $hotel_id; ?>" class="flex items-center px-6 py-3 text-white bg-white bg-opacity-20 border-r-4 border-white">
                <i class="fas fa-tags mr-3"></i>
                Pricing
            </a>
            
            
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
            <h1 class="text-3xl font-bold text-gray-800 mb-2">Room Pricing</h1>
            <p class="text-gray-600 mb-4">View and manage room rates</p>
            <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                <h2 class="text-xl font-semibold text-gray-800 mb-2">Hotel Description</h2>
                <div id="hotelDescriptionDisplay" class="text-gray-700 mb-2"><?php echo nl2br(htmlspecialchars($hotel['description'] ?? 'No description yet.')); ?></div>
                <?php if ($user['role'] === 'admin'): ?>
                <form id="editHotelDescriptionForm" class="hidden">
                    <textarea id="hotelDescriptionInput" name="description" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-lg mb-2"><?php echo htmlspecialchars($hotel['description'] ?? ''); ?></textarea>
                    <div class="flex space-x-2">
                        <button type="button" onclick="cancelEditDescription()" class="px-4 py-2 text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg">Cancel</button>
                        <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">Save</button>
                    </div>
                </form>
                <button id="editDescriptionBtn" onclick="startEditDescription()" class="mt-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">Edit Description</button>
                <?php endif; ?>
            </div>
            
            <!-- Room Availability Information -->
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                <div class="flex items-start">
                    <div class="flex-shrink-0">
                        <i class="fas fa-info-circle text-blue-400 text-xl"></i>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-sm font-medium text-blue-800">Room Availability Rules</h3>
                        <div class="mt-2 text-sm text-blue-700">
                            <ul class="list-disc list-inside space-y-1">
                                <li><strong>Checkout Time:</strong> All rooms must be vacated by 11:00 AM on checkout day</li>
                                <li><strong>Availability:</strong> Rooms become available for new bookings after 11:00 AM on checkout day</li>
                                <li><strong>Same-day Turnover:</strong> Rooms can be booked for the same day if checkout time has passed</li>
                                <li><strong>Current Time:</strong> <?php echo date('d/m/Y H:i:s'); ?> (Bangladesh Time)</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Room Categories Display (now at the very top) -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
            <?php foreach ($room_categories as $cat): ?>
            <div class="bg-white rounded-lg shadow-md p-6 price-card">
                <div class="flex items-center justify-between mb-2">
                    <div class="flex items-center space-x-2">
                        <i class="<?php echo htmlspecialchars($cat['icon'] ?: 'fas fa-bed'); ?> text-2xl text-indigo-600"></i>
                        <h3 class="text-xl font-bold text-indigo-700"><?php echo htmlspecialchars($cat['name']); ?></h3>
                    </div>
                    <?php if ($user['role'] === 'admin' || $user['role'] === 'manager'): ?>
                    <div class="flex space-x-2">
                        <button onclick="openEditCategoryModal(<?php echo $cat['id']; ?>)" class="text-blue-600 hover:text-blue-800"><i class="fas fa-edit"></i></button>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this category?');">
                            <input type="hidden" name="category_id" value="<?php echo $cat['id']; ?>">
                            <button type="submit" name="delete_category" class="text-red-600 hover:text-red-800"><i class="fas fa-trash"></i></button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="text-gray-700 mb-2"><?php echo htmlspecialchars($cat['description']); ?></div>
                <div class="text-2xl font-bold text-green-600 mb-2">৳<?php echo number_format($cat['price'], 0); ?></div>
                <ul class="list-disc pl-5 text-gray-600 mb-2">
                    <?php foreach (explode(',', $cat['amenities']) as $amenity): ?>
                    <li><?php echo htmlspecialchars(trim($amenity)); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endforeach; ?>
        </div>
        <!-- Add Category Button and Modal -->
        <?php if ($user['role'] === 'admin'): ?>
        <button onclick="openAddCategoryModal()" class="mb-6 px-6 py-2 bg-green-600 text-white rounded-lg shadow hover:bg-green-700"><i class="fas fa-plus mr-2"></i>Add Room Category</button>
        <!-- Edit/Add Category Modal -->
        <div id="categoryModal" class="hidden fixed inset-0 bg-black bg-opacity-40 flex items-center justify-center z-50">
            <div class="bg-white rounded-lg p-8 w-full max-w-md">
                <h2 id="categoryModalTitle" class="text-xl font-bold mb-4 text-indigo-700">Edit Room Category</h2>
                <form id="categoryForm" method="post">
                    <input type="hidden" name="category_id" id="modalCategoryId">
                    <div class="mb-3">
                        <label class="block text-gray-700 mb-1">Category Name</label>
                        <input type="text" name="name" id="modalName" class="w-full border rounded px-3 py-2" required>
                    </div>
                    <div class="mb-3">
                        <label class="block text-gray-700 mb-1">Description</label>
                        <textarea name="description" id="modalDescription" class="w-full border rounded px-3 py-2" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="block text-gray-700 mb-1">Price (BDT)</label>
                        <input type="number" name="price" id="modalPrice" class="w-full border rounded px-3 py-2" required>
                    </div>
                    <div class="mb-3">
                        <label class="block text-gray-700 mb-1">Amenities (comma separated)</label>
                        <input type="text" name="amenities" id="modalAmenities" class="w-full border rounded px-3 py-2" required>
                    </div>
                    <div class="mb-3">
                        <label class="block text-gray-700 mb-1">Icon (FontAwesome class, e.g. 'fas fa-bed')</label>
                        <input type="text" name="icon" id="modalIcon" class="w-full border rounded px-3 py-2" required>
                    </div>
                    <div class="flex justify-end space-x-2">
                        <button type="button" onclick="closeCategoryModal()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded">Cancel</button>
                        <button type="submit" id="modalSubmitBtn" class="px-6 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Save</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
        <!-- Pricing Table -->
        <div class="bg-white rounded-lg shadow-md overflow-hidden">
            <div class="p-6 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <h2 class="text-xl font-semibold text-gray-800">Room Rates</h2>
                    <div class="text-sm text-gray-500">
                        <i class="fas fa-info-circle mr-1"></i>
                        Click the edit button next to each room to modify its price
                    </div>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Room Number</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Price (BDT)</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <?php if ($user['role'] === 'admin' || $user['role'] === 'manager'): ?>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($rooms as $room): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                <?php echo htmlspecialchars($room['room_number']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo htmlspecialchars($room['category_name'] ?: $room['category']); ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-500 max-w-xs">
                                <?php echo htmlspecialchars($room['category_description'] ?: ($category_descriptions[$room['category']] ?? 'No description available')); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                ৳<?php echo number_format($room['price'], 2); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php if ($room['is_booked']): ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                        <i class="fas fa-times-circle mr-1"></i>Booked
                                    </span>
                                    <div class="text-xs text-gray-500 mt-1">
                                        <i class="fas fa-clock mr-1"></i>Available after 11:00 AM
                                    </div>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                        <i class="fas fa-check-circle mr-1"></i>Available
                                    </span>
                                    <div class="text-xs text-gray-500 mt-1">
                                        <i class="fas fa-clock mr-1"></i>Checkout: 11:00 AM
                                    </div>
                                <?php endif; ?>
                            </td>
                            <?php if ($user['role'] === 'admin' || $user['role'] === 'manager'): ?>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <div class="flex space-x-2">
                                    <!-- Edit Room Price Button -->
                                    <button onclick="openEditPriceModal(<?php echo $room['id']; ?>, '<?php echo htmlspecialchars($room['room_number']); ?>', <?php echo $room['price']; ?>)" 
                                            class="text-blue-600 hover:text-blue-900" title="Edit Room Price">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    
                                    <!-- Toggle Room Status Button -->
                                    <form method="post" style="display: inline;" onsubmit="return confirm('<?php echo $room['is_booked'] ? 'Mark room as available?' : 'Mark room as unavailable?'; ?>');">
                                        <input type="hidden" name="room_id" value="<?php echo $room['id']; ?>">
                                        <input type="hidden" name="new_status" value="<?php echo $room['is_booked'] ? 'available' : 'booked'; ?>">
                                        <button type="submit" name="toggle_room_status" 
                                                class="<?php echo $room['is_booked'] ? 'text-green-600 hover:text-green-900' : 'text-red-600 hover:text-red-900'; ?>" 
                                                title="<?php echo $room['is_booked'] ? 'Mark as Available' : 'Mark as Unavailable'; ?>">
                                            <i class="fas <?php echo $room['is_booked'] ? 'fa-check' : 'fa-times'; ?>"></i>
                                        </button>
                                    </form>
                                    
                                    <!-- Delete Room Button -->
                                    <form method="post" style="display: inline;" onsubmit="return confirm('Delete this room?');">
                                        <input type="hidden" name="room_id" value="<?php echo $room['id']; ?>">
                                        <button type="submit" name="delete_room" class="text-red-600 hover:text-red-900" title="Delete Room">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <script>
        // JS for modal logic
        function openEditCategoryModal(id) {
            const cat = <?php echo json_encode($room_categories); ?>.find(c => c.id == id);
            if (!cat) return;
            document.getElementById('categoryModal').classList.remove('hidden');
            document.getElementById('categoryModalTitle').innerText = 'Edit Room Category';
            document.getElementById('modalCategoryId').value = cat.id;
            document.getElementById('modalName').value = cat.name;
            document.getElementById('modalDescription').value = cat.description;
            document.getElementById('modalPrice').value = cat.price;
            document.getElementById('modalAmenities').value = cat.amenities;
            document.getElementById('modalIcon').value = cat.icon || 'fas fa-bed';
            document.getElementById('modalSubmitBtn').name = 'edit_category';
        }
        function openAddCategoryModal() {
            document.getElementById('categoryModal').classList.remove('hidden');
            document.getElementById('categoryModalTitle').innerText = 'Add Room Category';
            document.getElementById('modalCategoryId').value = '';
            document.getElementById('modalName').value = '';
            document.getElementById('modalDescription').value = '';
            document.getElementById('modalPrice').value = '';
            document.getElementById('modalAmenities').value = '';
            document.getElementById('modalIcon').value = 'fas fa-bed';
            document.getElementById('modalSubmitBtn').name = 'add_category';
        }
        function closeCategoryModal() {
            document.getElementById('categoryModal').classList.add('hidden');
        }
        
        // Edit Room Price Modal Functions
        function openEditPriceModal(roomId, roomNumber, currentPrice) {
            document.getElementById('editPriceRoomId').value = roomId;
            document.getElementById('editPriceRoomNumber').value = roomNumber;
            document.getElementById('editPriceValue').value = currentPrice;
            document.getElementById('editPriceModal').classList.remove('hidden');
        }
        
        function closeEditPriceModal() {
            document.getElementById('editPriceModal').classList.add('hidden');
        }
        </script>

        <!-- Pricing Cards -->
        

    <!-- Edit Room Price Modal -->
    <div id="editPriceModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
                <div class="p-6 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-800">Edit Room Price</h3>
                </div>
                
                <form method="post" class="p-6">
                    <input type="hidden" name="room_id" id="editPriceRoomId">
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Room Number</label>
                        <input type="text" id="editPriceRoomNumber" readonly class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50">
                    </div>
                    
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Price (BDT)</label>
                        <input type="number" name="price" id="editPriceValue" step="0.01" min="0" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                    </div>
                    
                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closeEditPriceModal()" class="px-4 py-2 text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">
                            Cancel
                        </button>
                        <button type="submit" name="update_room_price" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                            Update Price
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <?php if ($user['role'] === 'admin'): ?>
    <div id="editModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
                <div class="p-6 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-800">Edit Room Price</h3>
                </div>
                
                <form id="editForm" class="p-6">
                    <input type="hidden" id="editRoomId" name="room_id">
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Room Number</label>
                        <input type="text" id="editRoomNumber" readonly class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50">
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Category</label>
                        <select id="editCategory" name="category" required class="w-full px-3 py-2 border border-gray-300 rounded-lg" onchange="autoFillEditModal()">
                            <option value="Standard Rooms">Standard Rooms</option>
                            <option value="Deluxe Rooms">Deluxe Rooms</option>
                            <option value="Suite Rooms">Suite Rooms</option>
                        </select>
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                        <textarea id="editDescription" name="description" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-lg" readonly></textarea>
                    </div>
                    
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Price (BDT)</label>
                        <input type="number" id="editPrice" name="price" step="0.01" min="0" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                    </div>
                    
                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closeEditModal()" class="px-4 py-2 text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">
                            Cancel
                        </button>
                        <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                            Update
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
        function openEditModal() {
            document.getElementById('editModal').classList.remove('hidden');
        }
        
        function closeEditModal() {
            document.getElementById('editModal').classList.add('hidden');
        }
        
        function autoFillEditModal() {
            const cat = document.getElementById('editCategory').value;
            const descs = {
                'Standard Rooms': 'Comfortable rooms with basic amenities',
                'Deluxe Rooms': 'Spacious rooms with premium amenities',
                'Suite Rooms': 'Luxury suites with separate living area'
            };
            const prices = {
                'Standard Rooms': '2500.00',
                'Deluxe Rooms': '3500.00',
                'Suite Rooms': '5000.00'
            };
            document.getElementById('editDescription').value = descs[cat] || '';
            document.getElementById('editPrice').value = prices[cat] || '';
        }
        document.getElementById('editCategory').addEventListener('change', autoFillEditModal);
        
        function editRoom(roomId) {
            // This would typically fetch room data via AJAX
            // For now, we'll use placeholder data
            document.getElementById('editRoomId').value = roomId;
            document.getElementById('editRoomNumber').value = 'Room ' + roomId;
            document.getElementById('editCategory').value = 'Standard Rooms';
            autoFillEditModal();
            openEditModal();
        }
        
        document.getElementById('editForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch('update_room.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Room updated successfully!');
                    closeEditModal();
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while updating the room.');
            });
        });
        
        // Close modal when clicking outside
        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeEditModal();
            }
        });

        function startEditDescription() {
            document.getElementById('hotelDescriptionDisplay').style.display = 'none';
            document.getElementById('editHotelDescriptionForm').classList.remove('hidden');
            document.getElementById('editDescriptionBtn').style.display = 'none';
        }
        function cancelEditDescription() {
            document.getElementById('hotelDescriptionDisplay').style.display = '';
            document.getElementById('editHotelDescriptionForm').classList.add('hidden');
            document.getElementById('editDescriptionBtn').style.display = '';
        }
        document.getElementById('editHotelDescriptionForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const desc = document.getElementById('hotelDescriptionInput').value;
            fetch('php/update_hotel_description.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'hotel_id=<?php echo $hotel_id; ?>&description=' + encodeURIComponent(desc)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('hotelDescriptionDisplay').innerHTML = desc.replace(/\n/g, '<br>');
                    cancelEditDescription();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(() => alert('An error occurred while updating the description.'));
        });
    </script>
</body>
</html>
