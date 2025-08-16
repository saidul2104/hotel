<?php
require_once 'database/config.php';

// Default admin credentials
$admin_email = 'admin@hotelgro.com';
$admin_password = 'admin123';
$admin_name = 'System Administrator';

try {
    // Check if admin user already exists
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$admin_email]);
    
    if ($stmt->rowCount() > 0) {
        echo "<div style='background: #f0f9ff; border: 1px solid #0ea5e9; color: #0369a1; padding: 15px; margin: 20px; border-radius: 5px;'>";
        echo "Admin user already exists!<br>";
        echo "Email: $admin_email<br>";
        echo "Password: $admin_password<br>";
        echo "<a href='login.php' style='color: #0369a1; text-decoration: underline;'>Go to Login</a>";
        echo "</div>";
    } else {
        // Hash the password
        $hashed_password = password_hash($admin_password, PASSWORD_DEFAULT);
        
        // Insert admin user
        $stmt = $pdo->prepare('INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)');
        $role = 'admin';
        
        if ($stmt->execute([$admin_name, $admin_email, $hashed_password, $role])) {
            echo "<div style='background: #f0fdf4; border: 1px solid #22c55e; color: #166534; padding: 15px; margin: 20px; border-radius: 5px;'>";
            echo "✅ Admin user created successfully!<br><br>";
            echo "<strong>Login Credentials:</strong><br>";
            echo "Email: $admin_email<br>";
            echo "Password: $admin_password<br><br>";
            echo "<a href='login.php' style='color: #166534; text-decoration: underline;'>Go to Login</a>";
            echo "</div>";
        } else {
            echo "<div style='background: #fef2f2; border: 1px solid #ef4444; color: #dc2626; padding: 15px; margin: 20px; border-radius: 5px;'>";
            echo "❌ Error creating admin user";
            echo "</div>";
        }
    }
} catch (Exception $e) {
    echo "<div style='background: #fef2f2; border: 1px solid #ef4444; color: #dc2626; padding: 15px; margin: 20px; border-radius: 5px;'>";
    echo "❌ Database error: " . $e->getMessage();
    echo "</div>";
}
?> 