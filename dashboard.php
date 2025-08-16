<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
require_once 'database/config.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'admin' && $_SESSION['user_role'] !== 'owner')) {
    header('Location: login.php');
    exit();
}

// Fetch hotels from database
$hotels = [];
$stmt = $pdo->prepare('SELECT * FROM hotels ORDER BY created_at DESC');
$stmt->execute();
$hotels = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Hotel Growth Solutions</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-50">
    <!-- Header -->
    <header class="bg-white shadow-sm border-b">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center">
                    <img src="assets/images/logo.png" alt="Logo" class="w-10 h-10 mr-3">
                    <h1 class="text-2xl font-bold text-gray-900">Hotel Growth Solutions</h1>
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
        <!-- Success/Error Messages -->
        <?php if(isset($_SESSION['success'])): ?>
            <div class="mb-4 p-4 bg-green-100 border border-green-400 text-green-700 rounded-lg">
                <div class="flex items-center">
                    <i class="fas fa-check-circle mr-2"></i>
                    <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if(isset($_SESSION['error'])): ?>
            <div class="mb-4 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="mb-8">
            <div class="flex justify-between items-center">
                <div>
                    <h2 class="text-3xl font-bold text-gray-900">Hotel Management</h2>
                    <p class="text-gray-600 mt-1">Manage your hotels and assign managers</p>
                </div>
                <button onclick="openAddHotelModal()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-3 rounded-lg font-medium transition duration-200 flex items-center">
                    <i class="fas fa-plus mr-2"></i>Add New Hotel
                </button>
            </div>
        </div>

        <!-- Hotels Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-2 gap-6">
            <?php foreach ($hotels as $hotel): ?>
<?php
    $hotel_id = $hotel['id'];
    $rooms_table = "rooms_hotel_{$hotel_id}";
    $bookings_table = "bookings_hotel_{$hotel_id}";
    // Get total rooms
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM `$rooms_table`");
        $total_rooms = $stmt->fetchColumn();
    } catch (Exception $e) { $total_rooms = 0; }
    // Get actual available rooms (based on booking conflicts, not static status)
    try {
        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM `$rooms_table` r 
            WHERE NOT EXISTS (
                SELECT 1 FROM `$bookings_table` b 
                WHERE b.room_id = r.id 
                AND b.status = 'active'
                AND (
                    (b.checkin_date < ? AND b.checkout_date > ?) OR
                    (b.checkin_date < ? AND b.checkout_date > ?) OR
                    (b.checkin_date >= ? AND b.checkout_date <= ?)
                )
            )
        ");
        $stmt->execute([$tomorrow, $today, $tomorrow, $today, $today, $tomorrow]);
        $available_rooms = $stmt->fetchColumn();
    } catch (Exception $e) { $available_rooms = 0; }
    
    // Get actually booked rooms (rooms with active bookings)
    try {
        $today = date('Y-m-d');
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT room_id) FROM `$bookings_table` 
            WHERE status = 'active' AND checkout_date >= ?
        ");
        $stmt->execute([$today]);
        $booked_rooms = $stmt->fetchColumn();
    } catch (Exception $e) { $booked_rooms = 0; }
?>
            <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden hover:shadow-xl transition duration-300">
                <!-- Hotel Image/Logo -->
                <div class="h-48 bg-gradient-to-br from-blue-400 to-purple-500 flex items-center justify-center overflow-hidden">
                    <?php if (!empty($hotel['logo'])): ?>
                        <img src="<?php echo htmlspecialchars($hotel['logo']); ?>" alt="<?php echo htmlspecialchars($hotel['name']); ?> Logo" class="h-full w-full object-cover">
                    <?php else: ?>
                        <i class="fas fa-hotel text-white text-4xl"></i>
                    <?php endif; ?>
                </div>
                
                <!-- Hotel Info -->
                <div class="p-6">
                    <div class="flex justify-between items-start mb-4">
                        <div>
                            <h3 class="text-xl font-bold text-gray-900 mb-1"><?php echo htmlspecialchars($hotel['name']); ?></h3>
                            <p class="text-gray-600 text-sm"><?php echo htmlspecialchars($hotel['address']); ?></p>
                        </div>
                        <span class="bg-green-100 text-green-800 text-xs font-medium px-2.5 py-0.5 rounded-full">Active</span>
                    </div>
                    
                    <div class="mb-4">
                        <?php if (!empty($hotel['phone'])): ?>
                            <p class="text-gray-700 text-sm mb-1">
                                <i class="fas fa-phone mr-2"></i><?php echo htmlspecialchars($hotel['phone']); ?>
                            </p>
                        <?php endif; ?>
                        <?php if (!empty($hotel['email'])): ?>
                            <p class="text-gray-700 text-sm">
                                <i class="fas fa-envelope mr-2"></i><?php echo htmlspecialchars($hotel['email']); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Stats -->
                    <div class="grid grid-cols-3 gap-4 mb-6">
                        <div class="text-center">
                            <div class="text-2xl font-bold text-indigo-600"><?php echo $total_rooms; ?></div>
                            <div class="text-xs text-gray-500">Rooms</div>
                        </div>
                        <div class="text-center">
                            <div class="text-2xl font-bold text-green-600"><?php echo $available_rooms; ?></div>
                            <div class="text-xs text-gray-500">Available</div>
                        </div>
                        <div class="text-center">
                            <div class="text-2xl font-bold text-orange-600"><?php echo $booked_rooms; ?></div>
                            <div class="text-xs text-gray-500">Booked</div>
                        </div>
                    </div>
                    
                    <!-- Manager Info -->
                    <?php
                        // Fetch manager info for this hotel
                        $manager_stmt = $pdo->prepare("SELECT name, email FROM users WHERE role='manager' AND hotel_id=? LIMIT 1");
                        $manager_stmt->execute([$hotel_id]);
                        $manager = $manager_stmt->fetch();
                    ?>
                    <?php if ($manager): ?>
                        <div class="mb-4 p-4 bg-indigo-50 rounded-lg">
                            <div class="font-semibold text-gray-800">Manager Name: <span class="text-indigo-700"><?php echo htmlspecialchars($manager['name']); ?></span></div>
                            <div class="font-semibold text-gray-800">Manager Email: <span class="text-indigo-700"><?php echo htmlspecialchars($manager['email']); ?></span></div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Action Buttons -->
                    <div class="flex space-x-2">
                        <form method="GET" action="hotel_dashboard.php" style="display: inline;">
                            <input type="hidden" name="hotel_id" value="<?php echo $hotel['id']; ?>">
                            <button type="submit" class="flex-1 bg-indigo-500 hover:bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition duration-200">
                                <i class="fas fa-eye mr-1"></i>View Details
                            </button>
                        </form>
                        <button onclick="openEditHotelModal(<?php echo $hotel['id']; ?>)" class="flex-1 bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition duration-200">
                            <i class="fas fa-edit mr-1"></i>Edit
                        </button>
                        <button onclick="assignManager(<?php echo $hotel['id']; ?>)" class="flex-1 bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition duration-200">
                            <i class="fas fa-user-plus mr-1"></i>Assign Manager
                        </button>
                        <button onclick="deleteHotel(<?php echo $hotel['id']; ?>)" class="flex-1 bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition duration-200">
                            <i class="fas fa-trash mr-1"></i>Delete
                        </button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Empty State -->
        <?php if (empty($hotels)): ?>
        <div class="text-center py-12">
            <i class="fas fa-hotel text-gray-400 text-6xl mb-4"></i>
            <h3 class="text-xl font-medium text-gray-900 mb-2">No hotels yet</h3>
            <p class="text-gray-600 mb-6">Get started by adding your first hotel</p>
            <button onclick="openAddHotelModal()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-3 rounded-lg font-medium transition duration-200">
                <i class="fas fa-plus mr-2"></i>Add First Hotel
            </button>
        </div>
        <?php endif; ?>
    </main>

    <!-- Add Hotel Modal -->
    <div id="addHotelModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-xl shadow-xl max-w-md w-full">
                <div class="flex justify-between items-center p-6 border-b">
                    <h3 class="text-xl font-bold text-gray-900">Add New Hotel</h3>
                    <button onclick="closeAddHotelModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <form action="php/add_hotel.php" method="POST" enctype="multipart/form-data" class="p-6">
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Hotel Name</label>
                            <input type="text" name="name" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-400">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                            <textarea name="address" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-400"></textarea>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                            <input type="tel" name="phone" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-400">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                            <input type="email" name="email" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-400">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Hotel Logo</label>
                            <input type="file" name="logo" accept="image/*" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-400">
                            <p class="text-xs text-gray-500 mt-1">Supported formats: JPG, PNG, GIF. Maximum size: 5MB</p>
                        </div>
                    </div>
                    <div class="flex space-x-3 mt-6">
                        <button type="button" onclick="closeAddHotelModal()" class="flex-1 px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition duration-200">Cancel</button>
                        <button type="submit" class="flex-1 px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition duration-200">Add Hotel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Hotel Modal -->
    <div id="editHotelModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-xl shadow-xl max-w-md w-full">
                <div class="flex justify-between items-center p-6 border-b">
                    <h3 class="text-xl font-bold text-gray-900">Edit Hotel</h3>
                    <button onclick="closeEditHotelModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <form action="php/edit_hotel.php" method="POST" enctype="multipart/form-data" class="p-6">
                    <input type="hidden" name="hotel_id" id="edit_hotel_id">
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Hotel Name</label>
                            <input type="text" name="name" id="edit_hotel_name" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-400">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                            <textarea name="address" id="edit_hotel_address" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-400"></textarea>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                            <textarea name="description" id="edit_hotel_description" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-400"></textarea>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                            <input type="email" name="email" id="edit_hotel_email" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-400">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                            <input type="tel" name="phone" id="edit_hotel_phone" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-400">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Hotel Logo</label>
                            <div id="current_logo_display" class="mb-2"></div>
                            <input type="file" name="logo" accept="image/*" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-400">
                            <p class="text-xs text-gray-500 mt-1">Supported formats: JPG, PNG, GIF. Maximum size: 5MB</p>
                        </div>
                    </div>
                    <div class="flex space-x-3 mt-6">
                        <button type="button" onclick="closeEditHotelModal()" class="flex-1 px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition duration-200">Cancel</button>
                        <button type="submit" class="flex-1 px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition duration-200">Update Hotel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Assign Manager Modal -->
    <div id="assignManagerModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-xl shadow-xl max-w-md w-full">
                <div class="flex justify-between items-center p-6 border-b">
                    <h3 class="text-xl font-bold text-gray-900">Assign Manager</h3>
                    <button onclick="closeAssignManagerModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <form action="php/assign_manager.php" method="POST" class="p-6">
                    <input type="hidden" name="hotel_id" id="assign_hotel_id">
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Manager Name</label>
                            <input type="text" name="manager_name" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-400">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Manager Email</label>
                            <input type="email" name="manager_email" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-400">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Manager Email</label>
                            <input type="email" name="manager_email" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-400">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Manager Password</label>
                            <input type="text" name="manager_password" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-400">
                        </div>
                    </div>
                    <div class="flex space-x-3 mt-6">
                        <button type="button" onclick="closeAssignManagerModal()" class="flex-1 px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition duration-200">Cancel</button>
                        <button type="submit" class="flex-1 px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition duration-200">Assign Manager</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Reset Manager Password Modal -->
    <div id="resetManagerPasswordModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-xl shadow-xl max-w-md w-full">
                <div class="flex justify-between items-center p-6 border-b">
                    <h3 class="text-xl font-bold text-gray-900">Reset Manager Password</h3>
                    <button onclick="closeResetManagerPasswordModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <form id="resetManagerPasswordForm" class="p-6">
                    <input type="hidden" name="hotel_id" id="reset_hotel_id">
                    <input type="hidden" name="email" id="reset_manager_email">
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">New Password</label>
                        <input type="text" name="new_password" id="reset_new_password" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                    </div>
                    <div class="flex space-x-3 mt-6">
                        <button type="button" onclick="closeResetManagerPasswordModal()" class="flex-1 px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition duration-200">Cancel</button>
                        <button type="submit" class="flex-1 px-4 py-2 bg-yellow-500 text-white rounded-lg hover:bg-yellow-600 transition duration-200">Reset Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openAddHotelModal() {
            document.getElementById('addHotelModal').classList.remove('hidden');
        }

        function closeAddHotelModal() {
            document.getElementById('addHotelModal').classList.add('hidden');
        }

        function openEditHotelModal(hotelId) {
            // Fetch hotel data and populate form
            fetch(`php/get_hotel.php?id=${hotelId}`)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('edit_hotel_id').value = data.id;
                    document.getElementById('edit_hotel_name').value = data.name;
                    document.getElementById('edit_hotel_address').value = data.address;
                    document.getElementById('edit_hotel_description').value = data.description || '';
                    document.getElementById('edit_hotel_email').value = data.email || '';
                    document.getElementById('edit_hotel_phone').value = data.phone || '';
                    
                    // Display current logo if exists
                    const logoDisplay = document.getElementById('current_logo_display');
                    if (data.logo) {
                        logoDisplay.innerHTML = `
                            <div class="flex items-center space-x-2 mb-2">
                                <img src="${data.logo}" alt="Current Logo" class="h-12 w-auto max-w-20 object-contain border border-gray-200 rounded">
                                <span class="text-sm text-gray-600">Current logo</span>
                            </div>
                        `;
                    } else {
                        logoDisplay.innerHTML = `
                            <div class="text-sm text-gray-500 mb-2">
                                <i class="fas fa-image mr-1"></i>No logo uploaded
                            </div>
                        `;
                    }
                    
                    document.getElementById('editHotelModal').classList.remove('hidden');
                });
        }

        function closeEditHotelModal() {
            document.getElementById('editHotelModal').classList.add('hidden');
        }

        function assignManager(hotelId) {
            document.getElementById('assign_hotel_id').value = hotelId;
            document.getElementById('assignManagerModal').classList.remove('hidden');
        }

        function closeAssignManagerModal() {
            document.getElementById('assignManagerModal').classList.add('hidden');
        }

        function deleteHotel(hotelId) {
            if (confirm('Are you sure you want to delete this hotel?')) {
                window.location.href = `php/delete_hotel.php?id=${hotelId}`;
            }
        }
    </script>
    <script>
function openResetManagerPasswordModal(hotelId, email) {
    document.getElementById('reset_hotel_id').value = hotelId;
    document.getElementById('reset_manager_email').value = email;
    document.getElementById('reset_new_password').value = '';
    document.getElementById('resetManagerPasswordModal').classList.remove('hidden');
}
function closeResetManagerPasswordModal() {
    document.getElementById('resetManagerPasswordModal').classList.add('hidden');
}
document.getElementById('resetManagerPasswordForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const hotelId = document.getElementById('reset_hotel_id').value;
    const email = document.getElementById('reset_manager_email').value;
    const newPassword = document.getElementById('reset_new_password').value;
    fetch('php/reset_manager_password.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'hotel_id=' + encodeURIComponent(hotelId) + '&email=' + encodeURIComponent(email) + '&new_password=' + encodeURIComponent(newPassword)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById('manager-password-' + hotelId).textContent = newPassword;
            closeResetManagerPasswordModal();
            alert('Password reset successfully!');
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(() => alert('An error occurred while resetting the password.'));
});
</script>
</body>
</html>
