<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

require_once 'database/config.php';
require_once 'php/booking_history_functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
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

// Get hotel information
$stmt = $pdo->prepare("SELECT * FROM hotels WHERE id = ?");
$stmt->execute([$hotel_id]);
$hotel = $stmt->fetch();

if (!$hotel) {
    echo '<h2 class="text-red-600">Hotel not found.</h2>';
    exit();
}

            // Handle filters - exclude 'created' bookings by default
            $filters = [];
            $filters['action_type'] = $_GET['action_type'] ?? '';
            $filters['date_from'] = $_GET['date_from'] ?? '';
            $filters['date_to'] = $_GET['date_to'] ?? '';
            $filters['search'] = $_GET['search'] ?? '';
            
            // If no specific action type is selected, exclude 'created' bookings
            if (empty($filters['action_type'])) {
                $filters['exclude_created'] = true;
            }

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

// Get booking history
$history_records = get_booking_history($hotel_id, $limit, $offset, $filters);
$total_records = get_booking_history_count($hotel_id, $filters);
$total_pages = ceil($total_records / $limit);

// Clean up old records (run this occasionally)
if (rand(1, 100) <= 5) { // 5% chance to run cleanup
    cleanup_old_booking_history();
}

// Handle AJAX request for record details
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_record') {
    $record_id = intval($_GET['record_id'] ?? 0);
    if ($record_id > 0) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM booking_history WHERE id = ? AND hotel_id = ?");
            $stmt->execute([$record_id, $hotel_id]);
            $record = $stmt->fetch();
            
            if ($record) {
                header('Content-Type: application/json');
                echo json_encode($record);
                exit();
            }
        } catch (Exception $e) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Failed to load record']);
            exit();
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid record ID']);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking History - <?php echo htmlspecialchars($hotel['name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .animate-fade-in {
            animation: fadeIn 0.5s ease-in-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <!-- Sidebar -->
    <div class="fixed inset-y-0 left-0 w-64 bg-gradient-to-b from-green-600 to-green-800 text-white shadow-lg">
        <div class="p-6">
            <div class="flex items-center">
                <i class="fas fa-hotel text-2xl mr-3"></i>
                <?php if ($_SESSION['user_role'] === 'admin'): ?>
                    <a href="dashboard.php" class="text-white hover:text-green-200 transition-colors">
                        <i class="fas fa-arrow-left mr-2"></i>Admin Panel
                    </a>
                <?php endif; ?>
                <span class="ml-3 text-xl font-bold"><?php echo htmlspecialchars($hotel['name']); ?></span>
            </div>
            <nav class="mt-8">
                <a href="hotel_dashboard.php?hotel_id=<?php echo $hotel_id; ?>" class="block px-6 py-3 hover:bg-white hover:bg-opacity-20 transition-colors">
                    <i class="fas fa-tachometer-alt mr-3"></i>Dashboard
                </a>
                <a href="calendar.php?hotel_id=<?php echo $hotel_id; ?>" class="block px-6 py-3 hover:bg-white hover:bg-opacity-20 transition-colors">
                    <i class="fas fa-calendar-alt mr-3"></i>Calendar
                </a>
                <?php if ($_SESSION['user_role'] === 'admin'): ?>
                <a href="manage_rooms.php?hotel_id=<?php echo $hotel_id; ?>" class="block px-6 py-3 hover:bg-white hover:bg-opacity-20 transition-colors">
                    <i class="fas fa-bed mr-3"></i>Manage Rooms
                </a>
                <?php endif; ?>
                <a href="manage_bookings.php?hotel_id=<?php echo $hotel_id; ?>" class="block px-6 py-3 hover:bg-white hover:bg-opacity-20 transition-colors">
                    <i class="fas fa-list mr-3"></i>Manage Bookings
                </a>
                <a href="booking_history.php?hotel_id=<?php echo $hotel_id; ?>" class="block px-6 py-3 bg-white bg-opacity-20 border-r-4 border-white">
                    <i class="fas fa-history mr-3"></i>Booking History
                </a>
                <a href="pricing.php?hotel_id=<?php echo $hotel_id; ?>" class="block px-6 py-3 hover:bg-white hover:bg-opacity-20 transition-colors">
                    <i class="fas fa-tags mr-3"></i>Pricing
                </a>
                <a href="logout.php" class="block px-6 py-3 mt-8 hover:bg-white hover:bg-opacity-20 transition-colors">
                    <i class="fas fa-sign-out-alt mr-3"></i>Logout
                </a>
            </nav>
        </div>
    </div>

    <!-- Main Content -->
    <div class="flex-1 p-8 ml-64">
        <div class="max-w-7xl mx-auto">
            <!-- Header -->
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h1 class="text-3xl font-bold text-gray-800">Booking Changes History</h1>
                    <p class="text-gray-600 mt-2">Track all booking updates and deletions with old and new values</p>
                </div>
                <div class="flex space-x-4">
                    <a href="manage_bookings.php?hotel_id=<?php echo $hotel_id; ?>" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-6 py-2 rounded-lg shadow transition duration-200">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Bookings
                    </a>
                    <button onclick="exportHistory()" class="bg-green-600 hover:bg-green-700 text-white font-semibold px-6 py-2 rounded-lg shadow transition duration-200">
                        <i class="fas fa-download mr-2"></i>Export
                    </button>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                <div class="bg-white p-6 rounded-lg shadow">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-blue-100 text-blue-600">
                            <i class="fas fa-edit text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-600">Updated</p>
                            <p class="text-2xl font-semibold text-gray-900">
                                <?php 
                                $updated_count = get_booking_history_count($hotel_id, ['action_type' => 'updated']);
                                echo $updated_count;
                                ?>
                            </p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white p-6 rounded-lg shadow">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-red-100 text-red-600">
                            <i class="fas fa-trash text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-600">Deleted</p>
                            <p class="text-2xl font-semibold text-gray-900">
                                <?php 
                                $deleted_count = get_booking_history_count($hotel_id, ['action_type' => 'deleted']);
                                echo $deleted_count;
                                ?>
                            </p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white p-6 rounded-lg shadow">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-purple-100 text-purple-600">
                            <i class="fas fa-clock text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-600">Total Changes</p>
                            <p class="text-2xl font-semibold text-gray-900"><?php echo $total_records; ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="bg-white p-6 rounded-lg shadow mb-6">
                <form method="GET" action="" class="space-y-4">
                    <input type="hidden" name="hotel_id" value="<?php echo $hotel_id; ?>">
                    
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Search</label>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($filters['search']); ?>" 
                                   placeholder="Search by guest name, NID, details, or user..." 
                                   class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-green-500">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Action Type</label>
                            <select name="action_type" class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-green-500">
                                <option value="">All Changes (Updated & Deleted)</option>
                                <option value="updated" <?php echo $filters['action_type'] === 'updated' ? 'selected' : ''; ?>>Updated</option>
                                <option value="deleted" <?php echo $filters['action_type'] === 'deleted' ? 'selected' : ''; ?>>Deleted</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Date From</label>
                            <input type="date" name="date_from" value="<?php echo htmlspecialchars($filters['date_from']); ?>" 
                                   class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-green-500">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Date To</label>
                            <input type="date" name="date_to" value="<?php echo htmlspecialchars($filters['date_to']); ?>" 
                                   class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-green-500">
                        </div>
                    </div>
                    
                    <div class="flex space-x-4">
                        <button type="submit" class="bg-green-600 hover:bg-green-700 text-white font-semibold px-6 py-2 rounded-lg shadow transition duration-200">
                            <i class="fas fa-search mr-2"></i>Filter
                        </button>
                        <a href="booking_history.php?hotel_id=<?php echo $hotel_id; ?>" class="bg-gray-500 hover:bg-gray-600 text-white font-semibold px-6 py-2 rounded-lg shadow transition duration-200">
                            <i class="fas fa-times mr-2"></i>Clear
                        </a>
                    </div>
                </form>
            </div>

            <!-- History Table -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-800">Booking Changes Records</h3>
                    <p class="text-sm text-gray-600 mt-1">Showing <?php echo count($history_records); ?> of <?php echo $total_records; ?> change records (updates and deletions only)</p>
                </div>
                
                <?php if (empty($history_records)): ?>
                    <div class="p-8 text-center">
                        <i class="fas fa-history text-4xl text-gray-300 mb-4"></i>
                        <p class="text-gray-500 text-lg">No booking changes found</p>
                        <p class="text-gray-400 text-sm mt-2">Booking updates and deletions will appear here</p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Booking ID</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Guest Info</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Details</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Changed By</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date & Time</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($history_records as $record): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-<?php echo get_action_type_color($record['action_type']); ?>-100 text-<?php echo get_action_type_color($record['action_type']); ?>-800">
                                                <i class="fas fa-<?php echo $record['action_type'] === 'created' ? 'plus' : ($record['action_type'] === 'updated' ? 'edit' : 'trash'); ?> mr-1"></i>
                                                <?php echo ucfirst($record['action_type']); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                            #<?php echo $record['booking_id']; ?>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-900">
                                            <?php 
                                            $guest_info = get_guest_info_from_record($record);
                                            if ($guest_info) {
                                                echo '<div class="space-y-1">';
                                                echo '<div class="font-medium">' . htmlspecialchars($guest_info['name']) . '</div>';
                                                echo '<div class="text-gray-600 text-xs">NID: ' . htmlspecialchars($guest_info['nid']) . '</div>';
                                                echo '</div>';
                                            } else {
                                                echo '<span class="text-gray-400">N/A</span>';
                                            }
                                            ?>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-900">
                                            <?php echo format_action_details($record['action_type'], $record['action_details'], $record['old_values'], $record['new_values']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <?php echo htmlspecialchars($record['changed_by_name']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <?php echo date('d/m/Y H:i', strtotime($record['changed_at'])); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <button onclick="viewDetails(<?php echo $record['id']; ?>)" class="text-blue-600 hover:text-blue-900 mr-3">
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="bg-white px-4 py-3 flex items-center justify-between border-t border-gray-200 sm:px-6 mt-6 rounded-lg shadow">
                    <div class="flex-1 flex justify-between sm:hidden">
                        <?php if ($page > 1): ?>
                            <a href="?hotel_id=<?php echo $hotel_id; ?>&page=<?php echo $page - 1; ?>&<?php echo http_build_query(array_filter($filters)); ?>" class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                Previous
                            </a>
                        <?php endif; ?>
                        <?php if ($page < $total_pages): ?>
                            <a href="?hotel_id=<?php echo $hotel_id; ?>&page=<?php echo $page + 1; ?>&<?php echo http_build_query(array_filter($filters)); ?>" class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                Next
                            </a>
                        <?php endif; ?>
                    </div>
                    <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                        <div>
                            <p class="text-sm text-gray-700">
                                Showing <span class="font-medium"><?php echo ($offset + 1); ?></span> to <span class="font-medium"><?php echo min($offset + $limit, $total_records); ?></span> of <span class="font-medium"><?php echo $total_records; ?></span> results
                            </p>
                        </div>
                        <div>
                            <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                                <?php if ($page > 1): ?>
                                    <a href="?hotel_id=<?php echo $hotel_id; ?>&page=<?php echo $page - 1; ?>&<?php echo http_build_query(array_filter($filters)); ?>" class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                <?php endif; ?>
                                
                                <?php
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);
                                
                                for ($i = $start_page; $i <= $end_page; $i++):
                                ?>
                                    <a href="?hotel_id=<?php echo $hotel_id; ?>&page=<?php echo $i; ?>&<?php echo http_build_query(array_filter($filters)); ?>" class="relative inline-flex items-center px-4 py-2 border text-sm font-medium <?php echo $i === $page ? 'z-10 bg-green-50 border-green-500 text-green-600' : 'bg-white border-gray-300 text-gray-500 hover:bg-gray-50'; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endfor; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                    <a href="?hotel_id=<?php echo $hotel_id; ?>&page=<?php echo $page + 1; ?>&<?php echo http_build_query(array_filter($filters)); ?>" class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                <?php endif; ?>
                            </nav>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Details Modal -->
    <div id="detailsModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900">Booking History Details</h3>
                    <button onclick="closeDetailsModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <div id="detailsContent" class="text-sm text-gray-600">
                    <!-- Content will be loaded here -->
                </div>
            </div>
        </div>
    </div>

    <script>
        async function viewDetails(recordId) {
            try {
                // Show loading state
                document.getElementById('detailsContent').innerHTML = `
                    <div class="flex items-center justify-center py-8">
                        <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
                        <span class="ml-2 text-gray-600">Loading details...</span>
                    </div>
                `;
                document.getElementById('detailsModal').classList.remove('hidden');
                
                // Get the record data from the server
                const record = await getRecordData(recordId);
                if (record) {
                    let content = '';
                    
                    if (record.action_type === 'deleted') {
                        content = generateDeletedBookingDetails(record);
                    } else if (record.action_type === 'updated') {
                        content = generateUpdatedBookingDetails(record);
                    } else {
                        content = `<p>Detailed information for record #${recordId}</p>`;
                    }
                    
                    document.getElementById('detailsContent').innerHTML = content;
                } else {
                    document.getElementById('detailsContent').innerHTML = `
                        <div class="text-center py-8">
                            <p class="text-red-600">Failed to load record details</p>
                        </div>
                    `;
                }
            } catch (error) {
                document.getElementById('detailsContent').innerHTML = `
                    <div class="text-center py-8">
                        <p class="text-red-600">Error: ${error.message}</p>
                    </div>
                `;
            }
        }
        
        function getRecordData(recordId) {
            // Make AJAX call to get the actual record data
            return fetch(`booking_history.php?ajax=get_record&record_id=${recordId}&hotel_id=${getUrlParameter('hotel_id')}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        throw new Error(data.error);
                    }
                    return data;
                })
                .catch(error => {
                    console.error('Error loading record:', error);
                    return null;
                });
        }
        
        function getUrlParameter(name) {
            const urlParams = new URLSearchParams(window.location.search);
            return urlParams.get(name);
        }
        
        function generateDeletedBookingDetails(record) {
            try {
                const oldData = JSON.parse(record.old_values);
                let content = `
                    <div class="space-y-4">
                        <div class="border-b pb-4">
                            <h4 class="text-lg font-semibold text-red-600 mb-2">🗑️ Deleted Booking Details</h4>
                            <p class="text-sm text-gray-600">Booking ID: #${record.booking_id}</p>
                            <p class="text-sm text-gray-600">Deleted by: ${record.changed_by_name}</p>
                            <p class="text-sm text-gray-600">Deleted on: ${record.changed_at}</p>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="space-y-3">
                                <h5 class="font-semibold text-gray-800">Guest Information</h5>
                                <div class="space-y-2 text-sm">
                                    <div><span class="font-medium">Name:</span> ${oldData.guest_name || 'N/A'}</div>
                                    <div><span class="font-medium">Contact:</span> ${oldData.guest_contact || 'N/A'}</div>
                                    <div><span class="font-medium">Email:</span> ${oldData.email || 'N/A'}</div>
                                    <div><span class="font-medium">Profession:</span> ${oldData.profession || 'N/A'}</div>
                                    <div><span class="font-medium">Address:</span> ${oldData.address || 'N/A'}</div>
                                    <div><span class="font-medium">Number of Guests:</span> ${oldData.num_guests || 'N/A'}</div>
                                </div>
                            </div>
                            
                            <div class="space-y-3">
                                <h5 class="font-semibold text-gray-800">Booking Details</h5>
                                <div class="space-y-2 text-sm">
                                    <div><span class="font-medium">Check-in:</span> ${oldData.checkin_date || 'N/A'}</div>
                                    <div><span class="font-medium">Check-out:</span> ${oldData.checkout_date || 'N/A'}</div>
                                    <div><span class="font-medium">Status:</span> ${oldData.status || 'N/A'}</div>
                                    <div><span class="font-medium">Booking Type:</span> ${oldData.booking_type || 'N/A'}</div>
                                    <div><span class="font-medium">Reference:</span> ${oldData.reference || 'N/A'}</div>
                                    <div><span class="font-medium">Room ID:</span> ${oldData.room_id || 'N/A'}</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="space-y-3">
                                <h5 class="font-semibold text-gray-800">Financial Information</h5>
                                <div class="space-y-2 text-sm">
                                    <div><span class="font-medium">Total Amount:</span> ৳${oldData.total_amount || '0.00'}</div>
                                    <div><span class="font-medium">Paid Amount:</span> ৳${oldData.paid || '0.00'}</div>
                                    <div><span class="font-medium">Discount:</span> ৳${oldData.discount || '0.00'}</div>
                                    <div><span class="font-medium">Due Amount:</span> ৳${oldData.due || '0.00'}</div>
                                </div>
                            </div>
                            
                            <div class="space-y-3">
                                <h5 class="font-semibold text-gray-800">Meal Add-ons</h5>
                                <div class="space-y-2 text-sm">
                                    <div><span class="font-medium">Breakfast:</span> ৳${oldData.breakfast_total || '0.00'}</div>
                                    <div><span class="font-medium">Lunch:</span> ৳${oldData.lunch_total || '0.00'}</div>
                                    <div><span class="font-medium">Dinner:</span> ৳${oldData.dinner_total || '0.00'}</div>
                                    <div><span class="font-medium">Total Meals:</span> ৳${oldData.meal_total || '0.00'}</div>
                                </div>
                            </div>
                        </div>
                        
                        ${oldData.note ? `
                        <div class="space-y-3">
                            <h5 class="font-semibold text-gray-800">Notes</h5>
                            <div class="bg-gray-50 p-3 rounded text-sm">
                                ${oldData.note}
                            </div>
                        </div>
                        ` : ''}
                    </div>
                `;
                return content;
            } catch (e) {
                return `<p class="text-red-600">Error loading deleted booking details: ${e.message}</p>`;
            }
        }
        
        function generateUpdatedBookingDetails(record) {
            try {
                const oldData = JSON.parse(record.old_values);
                const newData = JSON.parse(record.new_values);
                let content = `
                    <div class="space-y-4">
                        <div class="border-b pb-4">
                            <h4 class="text-lg font-semibold text-blue-600 mb-2">✏️ Booking Update Details</h4>
                            <p class="text-sm text-gray-600">Booking ID: #${record.booking_id}</p>
                            <p class="text-sm text-gray-600">Updated by: ${record.changed_by_name}</p>
                            <p class="text-sm text-gray-600">Updated on: ${record.changed_at}</p>
                        </div>
                        
                        <div class="space-y-3">
                            <h5 class="font-semibold text-gray-800">Changes Made</h5>
                            <div class="space-y-2">
                `;
                
                // Compare old and new values
                for (const [field, newValue] of Object.entries(newData)) {
                    if (oldData[field] !== newValue) {
                        const oldValue = oldData[field] || 'N/A';
                        content += `
                            <div class="flex justify-between items-center p-2 bg-gray-50 rounded">
                                <span class="font-medium">${field}:</span>
                                <div class="text-sm">
                                    <span class="text-red-600 line-through">${oldValue}</span>
                                    <span class="mx-2">→</span>
                                    <span class="text-green-600 font-semibold">${newValue}</span>
                                </div>
                            </div>
                        `;
                    }
                }
                
                content += `
                            </div>
                        </div>
                    </div>
                `;
                return content;
            } catch (e) {
                return `<p class="text-red-600">Error loading updated booking details: ${e.message}</p>`;
            }
        }

        function closeDetailsModal() {
            document.getElementById('detailsModal').classList.add('hidden');
        }

        function exportHistory() {
            // Create export URL with current filters
            const currentUrl = new URL(window.location);
            currentUrl.searchParams.set('export', '1');
            window.open(currentUrl.toString(), '_blank');
        }

        // Close modal when clicking outside
        document.getElementById('detailsModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeDetailsModal();
            }
        });
    </script>
</body>
</html> 