<?php
/**
 * Booking History Cleanup Script
 * 
 * This script cleans up booking history records older than 90 days.
 * It can be run manually or scheduled via cron job.
 * 
 * Usage:
 * - Manual: php cleanup_booking_history.php
 * - Cron: 0 2 * * * /usr/bin/php /path/to/cleanup_booking_history.php
 */

require_once 'database/config.php';
require_once 'php/booking_history_functions.php';

echo "Starting booking history cleanup...\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";

try {
    $deleted_count = cleanup_old_booking_history();
    
    if ($deleted_count !== false) {
        echo "✅ Successfully cleaned up $deleted_count old booking history records\n";
    } else {
        echo "❌ Error occurred during cleanup\n";
        exit(1);
    }
    
    echo "Cleanup completed successfully!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?> 