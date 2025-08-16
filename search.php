<?php
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

$search_results = [];
$search_performed = false;

if (isset($_GET['phone']) && !empty($_GET['phone'])) {
    $search_performed = true;
    $phone = $_GET['phone'];
    $stmt = $conn->prepare("
        SELECT b.*, r.room_no, r.category, g.name as guest_name, g.phone
        FROM bookings b 
        JOIN rooms r ON b.room_id = r.id 
        JOIN guests g ON b.guest_id = g.id
        WHERE r.hotel_id = ? AND g.phone LIKE ? 
        ORDER BY b.created_at DESC
    ");
    $search_phone = '%' . $phone . '%';
    $stmt->bind_param('is', $hotel_id, $search_phone);
    $stmt->execute();
    $search_results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search Bookings - Hotel</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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
                    <h1 class="text-2xl font-bold text-gray-900">Search Bookings</h1>
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
        <div class="mb-8">
            <h2 class="text-3xl font-bold text-gray-900 mb-2">Search Bookings</h2>
            <p class="text-gray-600">Find bookings by guest phone number</p>
        </div>

        <!-- Search Form -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <form method="GET" class="flex items-center space-x-4">
                <div class="flex-1">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Guest Phone Number</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-phone text-gray-400"></i>
                        </div>
                        <input type="tel" name="phone" value="<?php echo isset($_GET['phone']) ? htmlspecialchars($_GET['phone']) : ''; ?>" 
                               placeholder="Enter phone number to search..." 
                               class="w-full pl-10 pr-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                </div>
                <div class="pt-6">
                    <button type="submit" class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                        <i class="fas fa-search mr-2"></i>
                        Search
                    </button>
                </div>
            </form>
        </div>

        <!-- Search Results -->
        <?php if ($search_performed): ?>
        <div class="bg-white rounded-lg shadow-md">
            <div class="p-6 border-b border-gray-200">
                <h3 class="text-xl font-semibold text-gray-800">
                    Search Results 
                    <?php if (!empty($search_results)): ?>
                    <span class="text-sm font-normal text-gray-500">(<?php echo count($search_results); ?> booking<?php echo count($search_results) !== 1 ? 's' : ''; ?> found)</span>
                    <?php endif; ?>
                </h3>
            </div>
            
            <?php if (empty($search_results)): ?>
            <div class="p-8 text-center">
                <i class="fas fa-search text-4xl text-gray-400 mb-4"></i>
                <p class="text-gray-600">No bookings found for the provided phone number.</p>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2">Booking ID</th>
                            <th class="px-4 py-2">Room</th>
                            <th class="px-4 py-2">Guest Name</th>
                            <th class="px-4 py-2">Phone</th>
                            <th class="px-4 py-2">Check-in</th>
                            <th class="px-4 py-2">Check-out</th>
                            <th class="px-4 py-2">Total</th>
                            <th class="px-4 py-2">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($search_results as $booking): ?>
                        <tr class="border-b hover:bg-gray-50">
                            <td class="px-4 py-2"><?php echo $booking['id']; ?></td>
                            <td class="px-4 py-2"><?php echo htmlspecialchars($booking['room_no']); ?></td>
                            <td class="px-4 py-2"><?php echo htmlspecialchars($booking['guest_name']); ?></td>
                            <td class="px-4 py-2"><?php echo htmlspecialchars($booking['phone']); ?></td>
                            <td class="px-4 py-2"><?php echo htmlspecialchars($booking['checkin']); ?></td>
                            <td class="px-4 py-2"><?php echo htmlspecialchars($booking['checkout']); ?></td>
                            <td class="px-4 py-2">৳<?php echo number_format($booking['total'], 2); ?></td>
                            <td class="px-4 py-2">
                                <span class="px-2 py-1 text-xs font-medium rounded-full 
                                    <?php echo $booking['status'] === 'booked' ? 'bg-blue-100 text-blue-800' : 
                                        ($booking['status'] === 'checked_in' ? 'bg-green-100 text-green-800' : 
                                        ($booking['status'] === 'checked_out' ? 'bg-gray-100 text-gray-800' : 'bg-red-100 text-red-800')); ?>">
                                    <?php echo ucfirst($booking['status']); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </main>
</body>
</html>
