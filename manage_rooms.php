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

// Only allow admin to access this page
if ($_SESSION['user_role'] !== 'admin') {
    echo '<h2 class="text-red-600 text-center mt-12">Access denied. Only admin can manage rooms.</h2>';
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

// Build the per-hotel rooms table name
$rooms_table = "rooms_hotel_{$hotel_id}";

// Handle add, edit, delete actions
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_room'])) {
        $room_number = $_POST['room_number'];
        $category = $_POST['category'];
        $description = $_POST['description'];
        $price = $_POST['price'];
        
        // Debug information
        error_log("Adding room: Number=$room_number, Category=$category, Description=$description, Price=$price");
        
        try {
            $stmt = $pdo->prepare("INSERT INTO `$rooms_table` (room_number, category, description, price, status) VALUES (?, ?, ?, ?, 'available')");
            $result = $stmt->execute([$room_number, $category, $description, $price]);
            
            if ($result) {
                $inserted_id = $pdo->lastInsertId();
                $message = "Room added successfully! ID: $inserted_id";
                error_log("Room added successfully with ID: $inserted_id");
                
                // Redirect to prevent form resubmission
                header("Location: manage_rooms.php?hotel_id=$hotel_id&success=1&room_id=$inserted_id");
                exit();
            } else {
                $message = '<span class="text-red-600">Failed to add room!</span>';
                error_log("Failed to add room");
            }
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $message = '<span class="text-red-600">Room number already exists for this hotel!</span>';
            } else {
                $message = '<span class="text-red-600">Error: ' . htmlspecialchars($e->getMessage()) . '</span>';
            }
        }
    } elseif (isset($_POST['edit_room'])) {
        $room_id = $_POST['room_id'];
        $room_number = $_POST['room_number'];
        $category = $_POST['category'];
        $description = $_POST['description'];
        $price = $_POST['price'];
        $status = $_POST['status'];
        try {
            $stmt = $pdo->prepare("UPDATE `$rooms_table` SET room_number=?, category=?, price=?, status=? WHERE id=?");
            $stmt->execute([$room_number, $category, $price, $status, $room_id]);
            $message = 'Room updated successfully!';
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $message = '<span class="text-red-600">Room number already exists for this hotel!</span>';
            } else {
                $message = '<span class="text-red-600">Error: ' . htmlspecialchars($e->getMessage()) . '</span>';
            }
        }
    } elseif (isset($_POST['delete_room'])) {
        $room_id = $_POST['room_id'];
        $stmt = $pdo->prepare("DELETE FROM `$rooms_table` WHERE id=?");
        $stmt->execute([$room_id]);
        $message = 'Room deleted successfully!';
    }
}
// Fetch all rooms for this hotel
$rooms = $pdo->prepare("SELECT * FROM `$rooms_table` ORDER BY room_number");
$rooms->execute();
$rooms = $rooms->fetchAll();

// Fetch hotel info
$stmt = $pdo->prepare("SELECT name FROM hotels WHERE id = ?");
$stmt->execute([$hotel_id]);
$hotel = $stmt->fetch();

// Fetch all room categories for this hotel
$stmt = $pdo->prepare("SELECT * FROM room_categories WHERE hotel_id = ? ORDER BY name ASC");
$stmt->execute([$hotel_id]);
$room_categories = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Rooms - Admin Panel</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-gray-50">
    <div class="flex min-h-screen">
        <!-- Sidebar -->
        <div class="w-64 sidebar shadow-lg bg-gradient-to-b from-indigo-500 to-purple-600 text-white">
            <div class="flex items-center justify-center h-16">
                <i class="fas fa-hotel text-2xl"></i>
                <span class="ml-3 text-xl font-bold"><?php echo htmlspecialchars($hotel['name']); ?></span>
            </div>
            <nav class="mt-8">
                <a href="hotel_dashboard.php?hotel_id=<?php echo $hotel_id; ?>" class="block px-6 py-3 hover:bg-white hover:bg-opacity-20 transition-colors"><i class="fas fa-tachometer-alt mr-3"></i>Dashboard</a>
                <a href="manage_rooms.php?hotel_id=<?php echo $hotel_id; ?>" class="block px-6 py-3 bg-white bg-opacity-20 border-r-4 border-white"><i class="fas fa-bed mr-3"></i>Manage Rooms</a>
                <a href="manage_bookings.php?hotel_id=<?php echo $hotel_id; ?>" class="block px-6 py-3 hover:bg-white hover:bg-opacity-20 transition-colors"><i class="fas fa-list mr-3"></i>Manage Bookings</a>
                <a href="logout.php" class="block px-6 py-3 mt-8 hover:bg-white hover:bg-opacity-20 transition-colors"><i class="fas fa-sign-out-alt mr-3"></i>Logout</a>
            </nav>
        </div>
        <!-- Main Content -->
        <div class="flex-1 p-8">
            <h1 class="text-3xl font-bold mb-6 text-gray-800">Manage Rooms</h1>
            
            <!-- Success Message -->
            <?php if (isset($_GET['success']) && $_GET['success'] == '1'): ?>
                <div class="mb-4 p-4 bg-green-100 text-green-800 rounded-lg">
                    ✅ Room added successfully! ID: <?php echo htmlspecialchars($_GET['room_id'] ?? ''); ?>
                </div>
            <?php endif; ?>
            
            <!-- Error Message -->
            <?php if ($message): ?>
                <div class="mb-4 p-4 bg-red-100 text-red-800 rounded-lg"> <?php echo $message; ?> </div>
            <?php endif; ?>
            <!-- Add Room Form -->
            <div class="mb-8 bg-white p-6 rounded-lg shadow">
                <h2 class="text-xl font-semibold mb-4">Add New Room</h2>
                <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4" id="addRoomForm" onsubmit="return validateRoomNumber()">
                    <input type="text" name="room_number" id="roomNumberInput" placeholder="Room Number" required class="px-4 py-2 border rounded" onblur="checkRoomNumberAvailability()">
                    <div id="roomNumberStatus" class="text-sm mt-1"></div>
                    <select name="category" id="addCategorySelect" required class="px-4 py-2 border rounded" onchange="autoFillCategory(this, 'add')">
                        <option value="">Select Category</option>
                        <?php foreach ($room_categories as $cat): ?>
                        <option value="<?php echo htmlspecialchars($cat['name']); ?>" data-description="<?php echo htmlspecialchars($cat['description']); ?>" data-price="<?php echo htmlspecialchars($cat['price']); ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="description" id="addDescription" value="" placeholder="Description (auto-filled)" class="px-4 py-2 border rounded md:col-span-2" readonly>
                    <input type="number" name="price" id="addPrice" placeholder="Price (BDT)" required class="px-4 py-2 border rounded">
                    <button type="submit" name="add_room" class="bg-indigo-600 text-white px-6 py-2 rounded hover:bg-indigo-700 md:col-span-2">Add Room</button>
                </form>
            </div>
            
            <!-- Existing Rooms Display -->
            <div class="bg-white p-6 rounded-lg shadow">
                <h2 class="text-xl font-semibold mb-4">Existing Rooms</h2>
                <?php if (count($rooms) > 0): ?>
                    <div class="overflow-x-auto">
                        <table class="w-full border-collapse border border-gray-300">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="border border-gray-300 px-4 py-2 text-left">Room Number</th>
                                    <th class="border border-gray-300 px-4 py-2 text-left">Category</th>
                                    <th class="border border-gray-300 px-4 py-2 text-left">Description</th>
                                    <th class="border border-gray-300 px-4 py-2 text-left">Price (BDT)</th>
                                    <th class="border border-gray-300 px-4 py-2 text-left">Status</th>
                                    <th class="border border-gray-300 px-4 py-2 text-left">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rooms as $room): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="border border-gray-300 px-4 py-2 room-number"><?php echo htmlspecialchars($room['room_number']); ?></td>
                                        <td class="border border-gray-300 px-4 py-2"><?php echo htmlspecialchars($room['category']); ?></td>
                                        <td class="border border-gray-300 px-4 py-2"><?php echo htmlspecialchars($room['description'] ?? ''); ?></td>
                                        <td class="border border-gray-300 px-4 py-2">৳<?php echo number_format($room['price'], 2); ?></td>
                                        <td class="border border-gray-300 px-4 py-2">
                                            <span class="px-2 py-1 text-xs rounded <?php echo $room['status'] === 'available' ? 'bg-green-100 text-green-800' : 'bg-gray-800 text-white'; ?>">
                                                <?php echo ucfirst($room['status']); ?>
                                            </span>
                                        </td>
                                        <td class="border border-gray-300 px-4 py-2">
                                            <button onclick="openEditModal(<?php echo $room['id']; ?>, '<?php echo htmlspecialchars($room['room_number']); ?>', '<?php echo htmlspecialchars($room['category']); ?>', '<?php echo htmlspecialchars($room['description'] ?? ''); ?>', <?php echo $room['price']; ?>, '<?php echo $room['status']; ?>')" class="bg-blue-600 text-white px-3 py-1 rounded text-sm hover:bg-blue-700 mr-2">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <form method="post" class="inline" onsubmit="return confirm('Are you sure you want to delete this room?')">
                                                <input type="hidden" name="room_id" value="<?php echo $room['id']; ?>">
                                                <button type="submit" name="delete_room" class="bg-red-600 text-white px-3 py-1 rounded text-sm hover:bg-red-700">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-gray-500 text-center py-8">No rooms added yet.</p>
                <?php endif; ?>
            </div>

            <!-- Edit Room Modal -->
            <div id="editModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
                <div class="flex items-center justify-center min-h-screen p-4">
                    <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
                        <div class="p-6 border-b border-gray-200">
                            <h3 class="text-lg font-semibold text-gray-800">Edit Room</h3>
                        </div>
                        <form method="post" class="p-6" id="editForm">
                            <input type="hidden" name="room_id" id="editRoomId">
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Room Number</label>
                                    <input type="text" name="room_number" id="editRoomNumber" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Category</label>
                                    <select name="category" id="editCategory" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" onchange="autoFillCategory(this, 'edit', document.getElementById('editForm'))">
                                        <option value="">Select Category</option>
                                        <?php foreach ($room_categories as $cat): ?>
                                        <option value="<?php echo htmlspecialchars($cat['name']); ?>" data-description="<?php echo htmlspecialchars($cat['description']); ?>" data-price="<?php echo htmlspecialchars($cat['price']); ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                                    <input type="text" name="description" id="editDescription" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Price (BDT)</label>
                                    <input type="number" name="price" id="editPrice" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                                    <select name="status" id="editStatus" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                        <option value="available">Available</option>
                                        <option value="maintenance">Maintenance</option>
                                    </select>
                                </div>
                            </div>
                            <div class="flex justify-end space-x-3 mt-6">
                                <button type="button" onclick="closeEditModal()" class="px-4 py-2 text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">
                                    Cancel
                                </button>
                                <button type="submit" name="edit_room" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                                    Update Room
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        </div>
    </div>
    <script>
// Edit Modal Functions
function openEditModal(roomId, roomNumber, category, description, price, status) {
    document.getElementById('editRoomId').value = roomId;
    document.getElementById('editRoomNumber').value = roomNumber;
    document.getElementById('editCategory').value = category;
    document.getElementById('editDescription').value = description;
    document.getElementById('editPrice').value = price;
    document.getElementById('editStatus').value = status;
    
    document.getElementById('editModal').classList.remove('hidden');
}

function closeEditModal() {
    document.getElementById('editModal').classList.add('hidden');
}

// Close modal when clicking outside
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('editModal');
    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            closeEditModal();
        }
    });
});

function autoFillCategory(select, mode, form) {
    var option = select.options[select.selectedIndex];
    var desc = option.getAttribute('data-description') || '';
    var price = option.getAttribute('data-price') || '';
    if (mode === 'add') {
        document.getElementById('addDescription').value = desc;
        document.getElementById('addPrice').value = price;
    } else if (mode === 'edit' && form) {
        form.querySelector('input[name="description"]').value = desc;
        form.querySelector('input[name="price"]').value = price;
    }
}

// Simple form submission handler
function handleFormSubmit(form) {
    var submitButton = form.querySelector('button[type="submit"]');
    submitButton.disabled = true;
    submitButton.textContent = 'Adding Room...';
    submitButton.style.backgroundColor = '#9CA3AF';
    return true;
}

// On page load, auto-fill all edit forms
window.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.editRoomForm select[name="category"]').forEach(function(sel) {
        autoFillCategory(sel, 'edit', sel.form);
    });
});

// Client-side room number validation
function checkRoomNumberAvailability() {
    const roomNumber = document.getElementById('roomNumberInput').value.trim();
    const statusDiv = document.getElementById('roomNumberStatus');
    
    if (!roomNumber) {
        statusDiv.innerHTML = '';
        return;
    }
    
    // Get existing room numbers from the page
    const existingRooms = [];
    document.querySelectorAll('.room-number').forEach(function(element) {
        existingRooms.push(element.textContent.trim());
    });
    
    if (existingRooms.includes(roomNumber)) {
        statusDiv.innerHTML = '<span class="text-red-600">❌ Room number already exists!</span>';
        return false;
    } else {
        statusDiv.innerHTML = '<span class="text-green-600">✅ Room number available</span>';
        return true;
    }
}

function validateRoomNumber() {
    const roomNumber = document.getElementById('roomNumberInput').value.trim();
    
    if (!roomNumber) {
        alert('Please enter a room number');
        return false;
    }
    
    // Check if room number already exists
    const existingRooms = [];
    document.querySelectorAll('.room-number').forEach(function(element) {
        existingRooms.push(element.textContent.trim());
    });
    
    if (existingRooms.includes(roomNumber)) {
        alert('Room number ' + roomNumber + ' already exists! Please choose a different room number.');
        return false;
    }
    
    return true;
}
</script>
</body>
</html> 