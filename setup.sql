
-- 1. Hotels Table (created first to avoid foreign key issues)
CREATE TABLE hotels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    address VARCHAR(255),
    phone VARCHAR(20),
    email VARCHAR(100),
    description TEXT,
    logo VARCHAR(255) DEFAULT NULL,
    owner_id INT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);


-- 2. Users Table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'manager', 'owner') NOT NULL,
    hotel_id INT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (hotel_id) REFERENCES hotels(id) ON DELETE SET NULL
);

-- 3. Add foreign key to hotels table after users table exists
ALTER TABLE hotels ADD FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE;

-- 4. Rooms Table
CREATE TABLE rooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hotel_id INT NOT NULL,
    room_no VARCHAR(20) NOT NULL,
    category VARCHAR(50),
    description TEXT,
    price DECIMAL(10,2) NOT NULL,
    status ENUM('available', 'booked', 'maintenance') DEFAULT 'available',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (hotel_id) REFERENCES hotels(id) ON DELETE CASCADE
);

-- 5. Guests Table
CREATE TABLE guests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    nid VARCHAR(30),
    profession VARCHAR(50),
    email VARCHAR(100),
    phone VARCHAR(20),
    address VARCHAR(255),
    no_of_guests INT DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 6. Bookings Table
CREATE TABLE bookings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_id INT NOT NULL,
    guest_id INT NOT NULL,
    parent_booking_id INT DEFAULT NULL,
    guest_name VARCHAR(100),
    guest_contact VARCHAR(20),
    checkin DATETIME NOT NULL,
    checkout DATETIME NOT NULL,
    checkin_date DATE,
    checkout_date DATE,
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
    checkin_time TIME,
    checkout_time TIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
    FOREIGN KEY (guest_id) REFERENCES guests(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_booking_id) REFERENCES bookings(id) ON DELETE CASCADE
);

-- 7. Revenue Table
CREATE TABLE revenue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hotel_id INT NOT NULL,
    date DATE NOT NULL,
    type ENUM('daily', 'monthly', 'yearly') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (hotel_id) REFERENCES hotels(id) ON DELETE CASCADE
);

-- 8. Hotel Managers Table
CREATE TABLE hotel_managers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hotel_id INT NOT NULL,
    manager_id INT NOT NULL,
    assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    password VARCHAR(255) NOT NULL,
    FOREIGN KEY (hotel_id) REFERENCES hotels(id) ON DELETE CASCADE,
    FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 9. Room Categories Table
CREATE TABLE room_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hotel_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    price DECIMAL(10,2) NOT NULL,
    amenities TEXT,
    icon VARCHAR(100),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (hotel_id) REFERENCES hotels(id) ON DELETE CASCADE
); 