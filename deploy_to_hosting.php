<?php
// Deployment Script for Hotel Management System
// This script prepares your project for hosting

echo "<h1>Hotel Management System - Deployment Preparation</h1>";

// Check if we're in the right directory
if (!file_exists('database/config.php')) {
    echo "<p>❌ Error: Please run this script from the project root directory</p>";
    exit;
}

echo "<h2>1. Creating Production Configuration</h2>";

// Copy production config
if (file_exists('database/config_production.php')) {
    echo "<p>✅ Production config file exists</p>";
    echo "<p>📝 Remember to update database credentials in database/config_production.php</p>";
} else {
    echo "<p>❌ Production config file not found</p>";
}

echo "<h2>2. Checking Required Files</h2>";

$required_files = [
    'index.php',
    'login.php',
    'dashboard.php',
    'hotel_dashboard.php',
    'manage_bookings.php',
    'calendar.php',
    'pricing.php',
    'multiple_booking.php',
    'process_booking.php',
    'check_availability.php',
    'setup_database.php',
    'database/config.php',
    'database/setup.sql',
    'fpdf/',
    'vendor/',
    'assets/',
    'uploads/',
    'pdf/',
    'pictures/'
];

$missing_files = [];
foreach ($required_files as $file) {
    if (file_exists($file) || is_dir($file)) {
        echo "<p>✅ $file</p>";
    } else {
        echo "<p>❌ $file (missing)</p>";
        $missing_files[] = $file;
    }
}

if (!empty($missing_files)) {
    echo "<h3>⚠️ Missing Files:</h3>";
    echo "<ul>";
    foreach ($missing_files as $file) {
        echo "<li>$file</li>";
    }
    echo "</ul>";
}

echo "<h2>3. Creating Deployment Package</h2>";

// Create a list of files to include in deployment
$deployment_files = [
    // Core PHP files
    '*.php',
    // Directories
    'assets/',
    'database/',
    'fpdf/',
    'vendor/',
    'uploads/',
    'pdf/',
    'pictures/',
    // Configuration files
    'composer.json',
    'composer.lock',
    // Documentation
    'README.md',
    'HOSTING_GUIDE.md',
    'SETUP_INSTRUCTIONS.txt'
];

echo "<p>📦 Files to upload to hosting:</p>";
echo "<ul>";
foreach ($deployment_files as $pattern) {
    echo "<li>$pattern</li>";
}
echo "</ul>";

echo "<h2>4. Pre-deployment Checklist</h2>";

echo "<div style='background: #f0f0f0; padding: 15px; border-radius: 5px;'>";
echo "<h3>Before Uploading to Hosting:</h3>";
echo "<ol>";
echo "<li>✅ Test all functionality locally</li>";
echo "<li>✅ Backup your current database</li>";
echo "<li>✅ Update database/config_production.php with hosting credentials</li>";
echo "<li>✅ Remove any test files or temporary files</li>";
echo "<li>✅ Check file permissions (755 for directories, 644 for files)</li>";
echo "<li>✅ Ensure all images and assets are included</li>";
echo "</ol>";
echo "</div>";

echo "<h2>5. Upload Instructions</h2>";

echo "<h3>Method 1: File Manager (Recommended)</h3>";
echo "<ol>";
echo "<li>Login to your hosting control panel</li>";
echo "<li>Open File Manager</li>";
echo "<li>Navigate to public_html directory</li>";
echo "<li>Upload all project files</li>";
echo "<li>Extract if uploaded as ZIP</li>";
echo "</ol>";

echo "<h3>Method 2: FTP Upload</h3>";
echo "<ol>";
echo "<li>Use FileZilla or similar FTP client</li>";
echo "<li>Connect to your hosting server</li>";
echo "<li>Upload files to public_html directory</li>";
echo "<li>Maintain directory structure</li>";
echo "</ol>";

echo "<h2>6. Post-upload Steps</h2>";

echo "<ol>";
echo "<li><strong>Database Setup:</strong> Run setup_database.php on your domain</li>";
echo "<li><strong>Configuration:</strong> Update database credentials</li>";
echo "<li><strong>Permissions:</strong> Set proper file permissions</li>";
echo "<li><strong>Testing:</strong> Test all functionality</li>";
echo "<li><strong>Security:</strong> Change default admin password</li>";
echo "<li><strong>Cleanup:</strong> Remove setup files after successful installation</li>";
echo "</ol>";

echo "<h2>7. Important Notes</h2>";

echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px; border-left: 4px solid #ffc107;'>";
echo "<h3>⚠️ Security Reminders:</h3>";
echo "<ul>";
echo "<li>Change default admin password immediately</li>";
echo "<li>Enable HTTPS/SSL on your domain</li>";
echo "<li>Set proper file permissions</li>";
echo "<li>Remove setup files after installation</li>";
echo "<li>Regular database backups</li>";
echo "</ul>";
echo "</div>";

echo "<h2>8. Support Information</h2>";

echo "<p>If you encounter issues during deployment:</p>";
echo "<ul>";
echo "<li>Check hosting provider's documentation</li>";
echo "<li>Verify PHP version compatibility (7.4+)</li>";
echo "<li>Ensure MySQL/MariaDB is available</li>";
echo "<li>Check error logs in hosting control panel</li>";
echo "<li>Contact hosting provider support</li>";
echo "</ul>";

echo "<hr>";
echo "<p><strong>Ready to deploy?</strong> Follow the HOSTING_GUIDE.md for detailed step-by-step instructions.</p>";
echo "<p><strong>Need help?</strong> Contact your hosting provider's support team.</p>";
?> 