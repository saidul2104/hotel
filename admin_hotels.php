<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
require_once 'database/config.php';

// Only admin can access
$user = null;
if (isset($_COOKIE['user'])) {
    $user = json_decode($_COOKIE['user'], true);
} elseif (isset($_SESSION['user'])) {
    $user = $_SESSION['user'];
}
if (!$user || $user['role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

// Handle add, edit, delete hotel
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_hotel'])) {
        $name = $_POST['name'];
        $address = $_POST['address'];
        $phone = $_POST['phone'];
        $email = $_POST['email'];
        $owner_id = $_SESSION['user_id'];
        $stmt = $pdo->prepare("INSERT INTO hotels (name, address, phone, email, owner_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$name, $address, $phone, $email, $owner_id]);
        $message = 'Hotel added successfully!';
    } elseif (isset($_POST['edit_hotel'])) {
        $hotel_id = $_POST['hotel_id'];
        $name = $_POST['name'];
        $address = $_POST['address'];
        $phone = $_POST['phone'];
        $email = $_POST['email'];
        $stmt = $pdo->prepare("UPDATE hotels SET name=?, address=?, phone=?, email=? WHERE id=?");
        $stmt->execute([$name, $address, $phone, $email, $hotel_id]);
        $message = 'Hotel updated successfully!';
    } elseif (isset($_POST['delete_hotel'])) {
        $hotel_id = $_POST['hotel_id'];
        
        try {
            // Start transaction
            $pdo->beginTransaction();
            
            // Verify hotel exists first
            $stmt = $pdo->prepare("SELECT id, name FROM hotels WHERE id = ?");
            $stmt->execute([$hotel_id]);
            $hotel = $stmt->fetch();
            
            if (!$hotel) {
                throw new Exception("Hotel not found with ID: $hotel_id");
            }
            
            // 1. Delete related data from main tables first (in correct order)
            // Delete bookings that reference rooms in this hotel
            $pdo->prepare("DELETE b FROM bookings b INNER JOIN rooms r ON b.room_id = r.id WHERE r.hotel_id = ?")->execute([$hotel_id]);
            
            // Delete rooms for this hotel
            $pdo->prepare("DELETE FROM rooms WHERE hotel_id = ?")->execute([$hotel_id]);
            
            // Delete room categories for this hotel
            $pdo->prepare("DELETE FROM room_categories WHERE hotel_id = ?")->execute([$hotel_id]);
            
            // Delete revenue records for this hotel
            $pdo->prepare("DELETE FROM revenue WHERE hotel_id = ?")->execute([$hotel_id]);
            
            // Delete hotel managers for this hotel
            $pdo->prepare("DELETE FROM hotel_managers WHERE hotel_id = ?")->execute([$hotel_id]);
            
            // 2. Update users to remove hotel assignment
            $pdo->prepare("UPDATE users SET hotel_id = NULL WHERE hotel_id = ?")->execute([$hotel_id]);
            
            // 3. Drop dynamic tables for this hotel
            $rooms_table = "rooms_hotel_{$hotel_id}";
            $bookings_table = "bookings_hotel_{$hotel_id}";
            
            // Check if tables exist before dropping
            $stmt = $pdo->query("SHOW TABLES LIKE '$rooms_table'");
            if ($stmt->fetch()) {
                $pdo->exec("DROP TABLE `$rooms_table`");
            }
            
            $stmt = $pdo->query("SHOW TABLES LIKE '$bookings_table'");
            if ($stmt->fetch()) {
                $pdo->exec("DROP TABLE `$bookings_table`");
            }
            
            // 4. Finally delete the hotel
            $pdo->prepare("DELETE FROM hotels WHERE id = ?")->execute([$hotel_id]);
            
            // Commit transaction
            $pdo->commit();
            $message = 'Hotel "' . htmlspecialchars($hotel['name']) . '" and all related data deleted successfully!';
            
        } catch (Exception $e) {
            // Rollback on error
            $pdo->rollBack();
            $message = 'Error deleting hotel: ' . $e->getMessage();
        }
    } elseif (isset($_POST['assign_manager'])) {
        $hotel_id = $_POST['hotel_id'];
        $manager_id = $_POST['manager_id'];
        // Generate random password
        $random_password = bin2hex(random_bytes(4));
        $hashed_password = password_hash($random_password, PASSWORD_DEFAULT);
        // Unassign this manager from any other hotel
        $pdo->prepare("UPDATE users SET hotel_id=NULL WHERE id=? AND role='manager'")->execute([$manager_id]);
        // Assign manager to this hotel and update password
        $pdo->prepare("UPDATE users SET hotel_id=?, password=? WHERE id=? AND role='manager'")->execute([$hotel_id, $hashed_password, $manager_id]);
        $message = 'Manager assigned successfully! Random password: <span class="font-mono bg-gray-200 px-2 py-1 rounded">' . $random_password . '</span>'; // Show password to admin
    }
}
// Fetch all hotels
$hotels = $pdo->query("SELECT * FROM hotels ORDER BY name")->fetchAll();
// Fetch all managers
$managers = $pdo->query("SELECT * FROM users WHERE role='manager'")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Manage Hotels</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-gray-50">
    <div class="flex min-h-screen">
        <!-- Sidebar -->
        <div class="w-64 sidebar shadow-lg bg-gradient-to-b from-indigo-500 to-purple-600 text-white">
            <!-- Replace logo and name in header/sidebar -->
            <div class="flex items-center justify-center h-16">
                <!-- Auto-generated SVG logo -->
                <svg width="40" height="40" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect width="40" height="40" rx="8" fill="#22c55e"/>
                    <rect x="10" y="18" width="20" height="12" rx="2" fill="#fff"/>
                    <rect x="14" y="22" width="4" height="4" rx="1" fill="#22c55e"/>
                    <rect x="22" y="22" width="4" height="4" rx="1" fill="#22c55e"/>
                    <rect x="18" y="14" width="4" height="6" rx="1" fill="#22c55e"/>
                </svg>
                <span class="ml-3 text-xl font-bold text-green-700">Hotel Growth Solutions</span>
            </div>
            <nav class="mt-8">
                <a href="admin_hotels.php" class="block px-6 py-3 bg-white bg-opacity-20 border-r-4 border-white"><i class="fas fa-building mr-3"></i>Hotels</a>
                <a href="logout.php" class="block px-6 py-3 mt-8 hover:bg-white hover:bg-opacity-20 transition-colors"><i class="fas fa-sign-out-alt mr-3"></i>Logout</a>
            </nav>
        </div>
        <!-- Main Content -->
        <div class="flex-1 p-8">
            <h1 class="text-3xl font-bold mb-6 text-gray-800">Manage Hotels</h1>
            <?php if ($message): ?>
                <div class="mb-4 p-4 bg-green-100 text-green-800 rounded-lg"> <?php echo $message; ?> </div>
            <?php endif; ?>
            <!-- Add Hotel Form -->
            <div class="mb-8 bg-white p-6 rounded-lg shadow">
                <h2 class="text-xl font-semibold mb-4">Add New Hotel</h2>
                <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <input type="text" name="name" placeholder="Hotel Name" required class="px-4 py-2 border rounded">
                    <input type="text" name="address" placeholder="Address" required class="px-4 py-2 border rounded">
                    <input type="text" name="phone" placeholder="Phone" required class="px-4 py-2 border rounded">
                    <input type="email" name="email" placeholder="Email" required class="px-4 py-2 border rounded">
                    <button type="submit" name="add_hotel" class="bg-indigo-600 text-white px-6 py-2 rounded hover:bg-indigo-700 md:col-span-2">Add Hotel</button>
                </form>
            </div>
            <!-- Hotel List Table -->
            <div class="bg-white p-6 rounded-lg shadow">
                <h2 class="text-xl font-semibold mb-4">All Hotels</h2>
                <table class="min-w-full table-auto">
                    <thead>
                        <tr>
                            <th class="px-4 py-2">Name</th>
                            <th class="px-4 py-2">Address</th>
                            <th class="px-4 py-2">Phone</th>
                            <th class="px-4 py-2">Email</th>
                            <th class="px-4 py-2">Manager</th>
                            <th class="px-4 py-2">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($hotels as $hotel): ?>
                        <tr class="border-b">
                            <form method="post">
                                <td class="px-4 py-2"><input type="text" name="name" value="<?php echo htmlspecialchars($hotel['name']); ?>" class="border rounded px-2 py-1 w-32"></td>
                                <td class="px-4 py-2"><input type="text" name="address" value="<?php echo htmlspecialchars($hotel['address']); ?>" class="border rounded px-2 py-1 w-40"></td>
                                <td class="px-4 py-2"><input type="text" name="phone" value="<?php echo htmlspecialchars($hotel['phone']); ?>" class="border rounded px-2 py-1 w-24"></td>
                                <td class="px-4 py-2"><input type="email" name="email" value="<?php echo htmlspecialchars($hotel['email']); ?>" class="border rounded px-2 py-1 w-32"></td>
                                <td class="px-4 py-2">
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="hotel_id" value="<?php echo $hotel['id']; ?>">
                                        <select name="manager_id" class="border rounded px-2 py-1">
                                            <option value="">-- Assign Manager --</option>
                                            <?php foreach ($managers as $manager): ?>
                                                <option value="<?php echo $manager['id']; ?>" <?php if (isset($manager['hotel_id']) && $manager['hotel_id'] == $hotel['id']) echo 'selected'; ?>>
                                                    <?php echo htmlspecialchars($manager['username']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" name="assign_manager" class="ml-2 bg-blue-500 text-white px-3 py-1 rounded hover:bg-blue-600"><i class="fas fa-user-cog"></i></button>
                                    </form>
                                </td>
                                <td class="px-4 py-2 flex space-x-2">
                                    <input type="hidden" name="hotel_id" value="<?php echo $hotel['id']; ?>">
                                    <button type="submit" name="edit_hotel" class="bg-blue-500 text-white px-3 py-1 rounded hover:bg-blue-600"><i class="fas fa-save"></i></button>
                                    <button type="submit" name="delete_hotel" class="bg-red-500 text-white px-3 py-1 rounded hover:bg-red-600" onclick="return confirm('Delete this hotel? This will remove all related data.');"><i class="fas fa-trash"></i></button>
                                    <a href="hotel_dashboard.php?hotel_id=<?php echo $hotel['id']; ?>" class="bg-green-500 text-white px-3 py-1 rounded hover:bg-green-600"><i class="fas fa-arrow-right"></i> Enter</a>
                                    <a href="php/delete_hotel.php" class="bg-orange-500 text-white px-3 py-1 rounded hover:bg-orange-600" title="Advanced Delete"><i class="fas fa-exclamation-triangle"></i></a>
                                </td>
                            </form>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html> 