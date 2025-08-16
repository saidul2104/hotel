<?php
session_start();
require_once 'database/config.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'admin' && $_SESSION['user_role'] !== 'owner')) {
    header('Location: login.php');
    exit();
}

if (!isset($_GET['id'])) {
    header('Location: dashboard.php');
    exit();
}

$hotel_id = (int)$_GET['id'];

// Fetch hotel details
$stmt = $pdo->prepare('SELECT h.*, u.name as owner_name FROM hotels h LEFT JOIN users u ON h.owner_id = u.id WHERE h.id = ?');
$stmt->execute([$hotel_id]);

if ($stmt->rowCount() === 0) {
    header('Location: dashboard.php');
    exit();
}

$hotel = $stmt->fetch();

// Fetch rooms for this hotel
$stmt = $pdo->prepare('SELECT * FROM rooms WHERE hotel_id = ? ORDER BY room_no');
$stmt->execute([$hotel_id]);
$rooms = $stmt->fetchAll();

// Fetch recent bookings
$stmt = $pdo->prepare('
    SELECT b.*, g.name as guest_name, g.phone as guest_phone, r.room_no 
    FROM bookings b 
    JOIN guests g ON b.guest_id = g.id 
    JOIN rooms r ON b.room_id = r.id 
    WHERE r.hotel_id = ? 
    ORDER BY b.created_at DESC 
    LIMIT 10
');
$stmt->execute([$hotel_id]);
$recent_bookings = $stmt->fetchAll();

// Calculate stats
$total_rooms = count($rooms);
$available_rooms = count(array_filter($rooms, function($room) { return $room['status'] === 'available'; }));
$booked_rooms = $total_rooms - $available_rooms;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($hotel['name']); ?> - Hotel Details</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-50">
    <!-- Header -->
    <header class="bg-white shadow-sm border-b">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center">
                    <a href="dashboard.php" class="mr-4 text-gray-600 hover:text-gray-900">
                        <i class="fas fa-arrow-left"></i>
                    </a>
                    <img src="assets/images/logo.png" alt="Logo" class="w-10 h-10 mr-3">
                    <h1 class="text-2xl font-bold text-gray-900"><?php echo htmlspecialchars($hotel['name']); ?></h1>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-gray-600">Welcome, <?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                    <a href="logout.php" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition duration-200">
                        <i class="fas fa-sign-out-alt mr-2"></i>Logout
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Hotel Overview -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-8">
            <div class="flex justify-between items-start mb-6">
                <div>
                    <h2 class="text-3xl font-bold text-gray-900 mb-2"><?php echo htmlspecialchars($hotel['name']); ?></h2>
                    <p class="text-gray-600 mb-1"><?php echo htmlspecialchars($hotel['address']); ?></p>
                    <p class="text-gray-700"><?php echo htmlspecialchars($hotel['description']); ?></p>
                </div>
                <div class="flex space-x-2">
                    <button onclick="openEditHotelModal()" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition duration-200">
                        <i class="fas fa-edit mr-1"></i>Edit Hotel
                    </button>
                    <button onclick="openAddRoomModal()" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition duration-200">
                        <i class="fas fa-plus mr-1"></i>Add Room
                    </button>
                </div>
            </div>
            
            <!-- Stats -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                <div class="bg-blue-50 rounded-lg p-4 text-center">
                    <div class="text-3xl font-bold text-blue-600"><?php echo $total_rooms; ?></div>
                    <div class="text-sm text-blue-600">Total Rooms</div>
                </div>
                <div class="bg-green-50 rounded-lg p-4 text-center">
                    <div class="text-3xl font-bold text-green-600"><?php echo $available_rooms; ?></div>
                    <div class="text-sm text-green-600">Available</div>
                </div>
                <div class="bg-orange-50 rounded-lg p-4 text-center">
                    <div class="text-3xl font-bold text-orange-600"><?php echo $booked_rooms; ?></div>
                    <div class="text-sm text-orange-600">Booked</div>
                </div>
                <div class="bg-purple-50 rounded-lg p-4 text-center">
                    <div class="text-3xl font-bold text-purple-600">৳<?php echo number_format(array_sum(array_column($rooms, 'price')), 2); ?></div>
                    <div class="text-sm text-purple-600">Total Value</div>
                </div>
            </div>
        </div>

        <!-- Rooms Section -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Rooms List -->
            <div class="bg-white rounded-xl shadow-lg">
                <div class="p-6 border-b">
                    <h3 class="text-xl font-bold text-gray-900">Rooms</h3>
                </div>
                <div class="p-6">
                    <?php
                    $category_info = [
                        'Standard Rooms' => [
                            'desc' => 'Comfortable rooms with basic amenities',
                            'amenities' => [
                                'Queen-size bed',
                                'Private bathroom',
                                'Free WiFi',
                                'TV with cable'
                            ]
                        ],
                        'Deluxe Rooms' => [
                            'desc' => 'Spacious rooms with premium amenities',
                            'amenities' => [
                                'King-size bed',
                                'Premium bathroom',
                                'High-speed WiFi',
                                'Smart TV',
                                'Mini refrigerator'
                            ]
                        ],
                        'Suite Rooms' => [
                            'desc' => 'Luxury suites with separate living area',
                            'amenities' => [
                                'Separate bedroom & living room',
                                'Luxury bathroom',
                                'Premium WiFi',
                                'Large Smart TV',
                                'Full kitchen',
                                'Balcony view'
                            ]
                        ]
                    ];
                    $rooms_by_cat = ['Standard Rooms'=>[], 'Deluxe Rooms'=>[], 'Suite Rooms'=>[]];
                    foreach ($rooms as $room) {
                        if (isset($rooms_by_cat[$room['category']])) {
                            $rooms_by_cat[$room['category']][] = $room;
                        }
                    }
                    foreach ($category_info as $cat => $info): ?>
                    <div class="mb-8">
                        <div class="flex items-center mb-2">
                            <h4 class="text-lg font-bold text-indigo-700 mr-4"><?php echo $cat; ?></h4>
                            <span class="text-gray-600 text-sm"><?php echo $info['desc']; ?></span>
                        </div>
                        <ul class="flex flex-wrap gap-2 mb-2 text-xs text-gray-500">
                            <?php foreach ($info['amenities'] as $am): ?>
                                <li class="bg-gray-100 rounded px-2 py-1"><i class="fas fa-check text-green-500 mr-1"></i><?php echo $am; ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if (empty($rooms_by_cat[$cat])): ?>
                            <div class="text-gray-400 italic">No rooms in this category yet.</div>
                    <?php else: ?>
                        <div class="space-y-3">
                                <?php foreach ($rooms_by_cat[$cat] as $room): ?>
                            <div class="flex justify-between items-center p-3 border rounded-lg hover:bg-gray-50">
                                <div>
                                    <div class="font-medium text-gray-900">Room <?php echo htmlspecialchars($room['room_no']); ?></div>
                                        <div class="text-sm text-gray-600">	f<?php echo number_format($room['price'], 2); ?></div>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <span class="px-2 py-1 text-xs font-medium rounded-full 
                                        <?php echo $room['status'] === 'available' ? 'bg-green-100 text-green-800' : 
                                            ($room['status'] === 'booked' ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800'); ?>">
                                        <?php echo ucfirst($room['status']); ?>
                                    </span>
                                    <button onclick="editRoom(<?php echo $room['id']; ?>)" class="text-blue-600 hover:text-blue-800">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button onclick="deleteRoom(<?php echo $room['id']; ?>)" class="text-red-600 hover:text-red-800">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Recent Bookings -->
            <div class="bg-white rounded-xl shadow-lg">
                <div class="p-6 border-b">
                    <h3 class="text-xl font-bold text-gray-900">Recent Bookings</h3>
                </div>
                <div class="p-6">
                    <?php if (empty($recent_bookings)): ?>
                        <div class="text-center py-8">
                            <i class="fas fa-calendar text-gray-400 text-4xl mb-4"></i>
                            <p class="text-gray-600">No bookings yet</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($recent_bookings as $booking): ?>
                            <div class="p-3 border rounded-lg">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <div class="font-medium text-gray-900"><?php echo htmlspecialchars($booking['guest_name']); ?></div>
                                        <div class="text-sm text-gray-600">Room <?php echo htmlspecialchars($booking['room_no']); ?></div>
                                        <div class="text-xs text-gray-500">
                                            <?php echo format_display_date($booking['checkin']); ?> - 
                                            <?php echo format_display_date($booking['checkout']); ?>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="font-medium text-gray-900">৳<?php echo number_format($booking['total'], 2); ?></div>
                                        <span class="px-2 py-1 text-xs font-medium rounded-full 
                                            <?php echo $booking['status'] === 'booked' ? 'bg-blue-100 text-blue-800' : 
                                                ($booking['status'] === 'checked_in' ? 'bg-green-100 text-green-800' : 
                                                ($booking['status'] === 'checked_out' ? 'bg-gray-100 text-gray-800' : 'bg-red-100 text-red-800')); ?>">
                                            <?php echo ucfirst($booking['status']); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <script>
        function openEditHotelModal() {
            // Redirect to dashboard with hotel edit modal
            window.location.href = 'dashboard.php?edit_hotel=<?php echo $hotel_id; ?>';
        }
        
        function openAddRoomModal() {
            // TODO: Implement add room modal
            alert('Add room functionality coming soon');
        }
        
        function editRoom(roomId) {
            // TODO: Implement edit room
            alert('Edit room ' + roomId);
        }
        
        function deleteRoom(roomId) {
            if (confirm('Are you sure you want to delete this room?')) {
                // TODO: Implement delete room
                alert('Delete room ' + roomId);
            }
        }
    </script>
</body>
</html> 