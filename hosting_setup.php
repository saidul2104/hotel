<?php
// Hosting Setup Script for Hotel Management System
// Run this script after uploading files to your hosting

echo "<h1>Hotel Management System - Hosting Setup</h1>";
echo "<p>This script will help you configure your application for hosting.</p>";

// Check PHP version
echo "<h2>1. System Requirements Check</h2>";
echo "<p>PHP Version: " . phpversion() . "</p>";
if (version_compare(PHP_VERSION, '7.4.0') >= 0) {
    echo "<p>✅ PHP version is compatible</p>";
} else {
    echo "<p>❌ PHP version should be 7.4 or higher</p>";
}

// Check required extensions
$required_extensions = ['pdo', 'pdo_mysql', 'mysqli', 'gd', 'mbstring'];
echo "<h2>2. Required Extensions</h2>";
foreach ($required_extensions as $ext) {
    if (extension_loaded($ext)) {
        echo "<p>✅ $ext extension is loaded</p>";
    } else {
        echo "<p>❌ $ext extension is missing</p>";
    }
}

// Check file permissions
echo "<h2>3. File Permissions</h2>";
$directories_to_check = ['uploads', 'pdf', 'pictures'];
foreach ($directories_to_check as $dir) {
    if (is_dir($dir)) {
        if (is_writable($dir)) {
            echo "<p>✅ $dir directory is writable</p>";
        } else {
            echo "<p>❌ $dir directory is not writable (set to 755)</p>";
        }
    } else {
        echo "<p>⚠️ $dir directory does not exist</p>";
    }
}

// Database connection test
echo "<h2>4. Database Connection Test</h2>";
echo "<p>To test database connection, you need to:</p>";
echo "<ol>";
echo "<li>Update database/config_production.php with your hosting database details</li>";
echo "<li>Rename database/config_production.php to database/config.php</li>";
echo "<li>Run setup_database.php to create tables</li>";
echo "</ol>";

// Email configuration
echo "<h2>5. Email Configuration</h2>";
echo "<p>For email functionality, update these settings in your PHP files:</p>";
echo "<ul>";
echo "<li>SMTP Host: Your hosting provider's SMTP server</li>";
echo "<li>SMTP Port: Usually 587 or 465</li>";
echo "<li>SMTP Username: Your email username</li>";
echo "<li>SMTP Password: Your email password</li>";
echo "</ul>";

// Security recommendations
echo "<h2>6. Security Recommendations</h2>";
echo "<ul>";
echo "<li>✅ Change default admin password (admin@neemshotel.com / admin123)</li>";
echo "<li>✅ Enable HTTPS on your domain</li>";
echo "<li>✅ Set proper file permissions (755 for directories, 644 for files)</li>";
echo "<li>✅ Keep PHP and all dependencies updated</li>";
echo "<li>✅ Regular database backups</li>";
echo "</ul>";

// Next steps
echo "<h2>7. Next Steps</h2>";
echo "<ol>";
echo "<li>Upload all project files to your hosting public_html directory</li>";
echo "<li>Create a MySQL database in your hosting control panel</li>";
echo "<li>Update database/config_production.php with your database credentials</li>";
echo "<li>Rename config_production.php to config.php</li>";
echo "<li>Run setup_database.php to create tables</li>";
echo "<li>Test the application by visiting your domain</li>";
echo "<li>Change default admin password</li>";
echo "</ol>";

echo "<h2>8. Troubleshooting</h2>";
echo "<p>If you encounter issues:</p>";
echo "<ul>";
echo "<li>Check error logs in your hosting control panel</li>";
echo "<li>Verify database credentials are correct</li>";
echo "<li>Ensure all files uploaded completely</li>";
echo "<li>Check file permissions</li>";
echo "<li>Contact your hosting provider for PHP/MySQL support</li>";
echo "</ul>";

echo "<hr>";
echo "<p><strong>Note:</strong> This is a setup guide. Make sure to follow your hosting provider's specific instructions for database creation and configuration.</p>";
?> 