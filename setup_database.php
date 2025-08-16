<?php
// Database setup script for Neemshotel
$host = 'localhost';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

echo "<h1>Hotel Database Setup</h1>";

try {
    // Step 1: Create database connection without database name
    $pdo = new PDO("mysql:host=$host;charset=$charset", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    
    echo "<p>✅ Connected to MySQL server successfully</p>";
    
    // Step 2: Create database if it doesn't exist
    $sql = "CREATE DATABASE IF NOT EXISTS hotel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
    $pdo->exec($sql);
    echo "<p>✅ Database 'hotel' created/verified successfully</p>";
    
    // Step 3: Connect to the specific database
    $pdo = new PDO("mysql:host=$host;dbname=hotel;charset=$charset", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    
    echo "<p>✅ Connected to hotel database</p>";
    
    // Step 4: Create tables
    $tables = [
        // Users Table
        "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(100) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            role ENUM('admin', 'manager', 'owner') NOT NULL,
            hotel_id INT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )",
        
        // Hotels Table
        "CREATE TABLE IF NOT EXISTS hotels (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            address VARCHAR(255),
            description TEXT,
            phone VARCHAR(20),
            email VARCHAR(100),
            owner_id INT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )",
        
        // Rooms Table
        "CREATE TABLE IF NOT EXISTS rooms (
            id INT AUTO_INCREMENT PRIMARY KEY,
            hotel_id INT NOT NULL,
            room_no VARCHAR(20) NOT NULL,
            category VARCHAR(50),
            description TEXT,
            price DECIMAL(10,2) NOT NULL,
            status ENUM('available', 'booked', 'maintenance') DEFAULT 'available',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )",
        
        // Guests Table
        "CREATE TABLE IF NOT EXISTS guests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            nid VARCHAR(30),
            profession VARCHAR(50),
            email VARCHAR(100),
            phone VARCHAR(20),
            address VARCHAR(255),
            no_of_guests INT DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )",
        
        // Bookings Table
        "CREATE TABLE IF NOT EXISTS bookings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            room_id INT NOT NULL,
            rooms INT DEFAULT 1,
            guest_id INT NOT NULL,
            guest_name VARCHAR(100),
            guest_contact VARCHAR(20),
            checkin DATETIME NOT NULL,
            checkout DATETIME NOT NULL,
            checkin_date DATE,
            checkout_date DATE,
            checkin_time TIME,
            checkout_time TIME,
            discount DECIMAL(10,2) DEFAULT 0.00,
            total DECIMAL(10,2) NOT NULL,
            total_amount DECIMAL(10,2),
            paid DECIMAL(10,2) DEFAULT 0.00,
            due DECIMAL(10,2) DEFAULT 0.00,
            status ENUM('booked', 'checked_in', 'checked_out', 'cancelled') DEFAULT 'booked',
            booking_type VARCHAR(50),
            reference VARCHAR(100),
            note TEXT,
            breakfast_price DECIMAL(10,2) DEFAULT 0.00,
            breakfast_quantity INT DEFAULT 0,
            breakfast_total DECIMAL(10,2) DEFAULT 0.00,
            lunch_price DECIMAL(10,2) DEFAULT 0.00,
            lunch_quantity INT DEFAULT 0,
            lunch_total DECIMAL(10,2) DEFAULT 0.00,
            dinner_price DECIMAL(10,2) DEFAULT 0.00,
            dinner_quantity INT DEFAULT 0,
            dinner_total DECIMAL(10,2) DEFAULT 0.00,
            meal_total DECIMAL(10,2) DEFAULT 0.00,
            breakfast_enabled BOOLEAN DEFAULT FALSE,
            lunch_enabled BOOLEAN DEFAULT FALSE,
            dinner_enabled BOOLEAN DEFAULT FALSE,
            nid_number VARCHAR(50),
            profession VARCHAR(50),
            email VARCHAR(100),
            address TEXT,
            num_guests INT DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )",
        
        // Revenue Table
        "CREATE TABLE IF NOT EXISTS revenue (
            id INT AUTO_INCREMENT PRIMARY KEY,
            hotel_id INT NOT NULL,
            date DATE NOT NULL,
            type ENUM('daily', 'monthly', 'yearly') NOT NULL,
            amount DECIMAL(10,2) NOT NULL
        )",
        
        // Hotel Managers Table
        "CREATE TABLE IF NOT EXISTS hotel_managers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            hotel_id INT NOT NULL,
            manager_id INT NOT NULL,
            assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            password VARCHAR(255) NOT NULL
        )",
        
        // Room Categories Table
        "CREATE TABLE IF NOT EXISTS room_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            hotel_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            price DECIMAL(10,2) NOT NULL,
            amenities TEXT,
            icon VARCHAR(100),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )",
        
        // Booking History Table
        "CREATE TABLE IF NOT EXISTS booking_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            hotel_id INT NOT NULL,
            booking_id INT NOT NULL,
            action_type ENUM('created', 'updated', 'deleted') NOT NULL,
            action_details TEXT,
            old_values JSON,
            new_values JSON,
            changed_by INT NOT NULL,
            changed_by_name VARCHAR(100),
            changed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_hotel_booking (hotel_id, booking_id),
            INDEX idx_changed_at (changed_at),
            INDEX idx_action_type (action_type)
        )"
    ];
    
    foreach ($tables as $sql) {
        $pdo->exec($sql);
    }
    echo "<p>✅ All tables created successfully</p>";
    
    // Step 5: Add foreign key constraints
    $foreignKeys = [
        "ALTER TABLE users ADD CONSTRAINT fk_users_hotel FOREIGN KEY (hotel_id) REFERENCES hotels(id) ON DELETE SET NULL",
        "ALTER TABLE hotels ADD CONSTRAINT fk_hotels_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE",
        "ALTER TABLE rooms ADD CONSTRAINT fk_rooms_hotel FOREIGN KEY (hotel_id) REFERENCES hotels(id) ON DELETE CASCADE",
        "ALTER TABLE bookings ADD CONSTRAINT fk_bookings_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE",
        "ALTER TABLE bookings ADD CONSTRAINT fk_bookings_guest FOREIGN KEY (guest_id) REFERENCES guests(id) ON DELETE CASCADE",
        "ALTER TABLE revenue ADD CONSTRAINT fk_revenue_hotel FOREIGN KEY (hotel_id) REFERENCES hotels(id) ON DELETE CASCADE",
        "ALTER TABLE hotel_managers ADD CONSTRAINT fk_managers_hotel FOREIGN KEY (hotel_id) REFERENCES hotels(id) ON DELETE CASCADE",
        "ALTER TABLE hotel_managers ADD CONSTRAINT fk_managers_user FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE CASCADE",
        "ALTER TABLE room_categories ADD CONSTRAINT fk_categories_hotel FOREIGN KEY (hotel_id) REFERENCES hotels(id) ON DELETE CASCADE",
        "ALTER TABLE booking_history ADD CONSTRAINT fk_history_hotel FOREIGN KEY (hotel_id) REFERENCES hotels(id) ON DELETE CASCADE",
        "ALTER TABLE booking_history ADD CONSTRAINT fk_history_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE CASCADE"
    ];
    
    foreach ($foreignKeys as $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            // Foreign key might already exist, continue
        }
    }
    echo "<p>✅ Foreign key constraints added</p>";
    
    // Step 6: Create admin user
    $admin_email = 'admin@neemshotel.com';
    $admin_password = 'admin123';
    $admin_name = 'System Administrator';
    
    // Check if admin user already exists
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$admin_email]);
    
    if ($stmt->rowCount() == 0) {
        // Hash the password
        $hashed_password = password_hash($admin_password, PASSWORD_DEFAULT);
        
        // Insert admin user
        $stmt = $pdo->prepare('INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)');
        $stmt->execute([$admin_name, $admin_email, $hashed_password, 'admin']);
        
        echo "<p>✅ Admin user created successfully</p>";
        echo "<p><strong>Admin Login Credentials:</strong></p>";
        echo "<p>Email: $admin_email</p>";
        echo "<p>Password: $admin_password</p>";
    } else {
        echo "<p>✅ Admin user already exists</p>";
    }
    
    echo "<h2>🎉 Database setup completed successfully!</h2>";
    echo "<p><a href='login.php' style='background: #3b82f6; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Go to Login</a></p>";
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>❌ Database error: " . $e->getMessage() . "</p>";
}
?> 