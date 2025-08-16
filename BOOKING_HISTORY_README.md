# Booking Changes History System

## Overview

The Booking Changes History system tracks all modifications and deletions made to bookings in the hotel management system. It provides a comprehensive audit trail of booking changes including updates and deletions with old and new values. **Note: This system excludes booking creation records and focuses only on changes and deletions.**

## Features

### 🔍 **Complete Audit Trail**
- **Booking Updates**: Tracks all changes to booking details (price, discount, guest info, etc.)
- **Booking Deletions**: Records when bookings are deleted
- **Old & New Values**: Shows exact before and after values for all changes
- **User Tracking**: Shows who made each change
- **Timestamp**: Records exact date and time of changes

### 📊 **Changes Dashboard**
- **Statistics Cards**: Shows counts of updated and deleted bookings
- **Guest Information**: Displays guest name and NID in the main table
- **Enhanced Filtering**: Filter by action type (updated/deleted), date range, and search terms
- **Advanced Search**: Search by guest name, NID, details, or user
- **Pagination**: Navigate through large numbers of change records
- **Export**: Export change history data for reporting

### 🧹 **Automatic Cleanup**
- **90-Day Retention**: History records are automatically deleted after 90 days
- **Storage Management**: Prevents database bloat
- **Scheduled Cleanup**: Can be run manually or via cron job

## How to Access

### From Manage Bookings Page
1. Navigate to **Manage Bookings**
2. Click the **"Booking History"** button (purple button with history icon)
3. You'll be taken to the Booking Changes History page

### Direct URL
```
http://localhost/hotel-management/booking_history.php?hotel_id=[HOTEL_ID]
```

## Database Structure

### booking_history Table
```sql
CREATE TABLE booking_history (
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
);
```

## What Gets Logged

### Booking Updates
- **Price Changes**: Room price modifications with old and new values
- **Discount Changes**: Discount amount updates with before/after values
- **Guest Information**: Name, contact, email, address changes
- **Booking Details**: Check-in/out dates, times, status changes
- **Meal Add-ons**: Breakfast, lunch, dinner modifications
- **Payment Updates**: Paid amount, due amount changes
- **Complete Change Tracking**: All field modifications are logged with exact values

### Booking Deletions
- **Complete booking data** before deletion
- **Guest information**: Name, contact, email, profession, address
- **Booking details**: Check-in/out dates, status, type, reference
- **Financial information**: Total amount, paid amount, discount, due amount
- **Meal add-ons**: Breakfast, lunch, dinner totals
- **Room information**: Room number, category, price (if available)
- **Notes and comments**: Any additional booking notes
- **All booking details preserved** for audit purposes

## Usage Examples

### Viewing History
1. **All Changes**: View all booking modifications
2. **Filter by Type**: Show only updates, creations, or deletions
3. **Date Range**: Filter by specific date periods
4. **Search**: Find specific changes by guest name, NID, or details

### Testing Enhanced Features
1. **Guest Information Display**: 
   - Guest name and NID are shown in the main table
   - Easy identification of which guest's booking was changed

2. **Advanced Search**:
   - Search by guest name (e.g., "John Doe")
   - Search by NID number (e.g., "1234567890")
   - Search by booking details or user name

3. **Enhanced Filtering**:
   - Filter by action type (Updated/Deleted)
   - Filter by date range
   - Combined search and filter options

### Understanding the Display
- **Blue Badge**: Booking updated
- **Red Badge**: Booking deleted
- **Guest Info Column**: Shows guest name and NID for easy identification
- **Details Column**: Shows what specific fields were changed with old → new values
- **Changed By**: Shows which user made the change
- **Date/Time**: When the change occurred
- **View Details**: Click "View" button to see complete information

### Detailed View Features
- **Deleted Bookings**: Complete guest info, booking details, financial data, meal add-ons, and room information
- **Updated Bookings**: Side-by-side comparison of old vs new values for all changed fields
- **Real-time Loading**: AJAX-powered details loading with loading indicators

## Maintenance

### Automatic Cleanup
The system automatically cleans up old records:
- **Retention Period**: 90 days
- **Automatic**: Runs occasionally when history page is accessed
- **Manual**: Can be triggered manually

### Manual Cleanup
Run the cleanup script manually:
```bash
cd /opt/lampp/htdocs/hotel-management
php cleanup_booking_history.php
```

### Scheduled Cleanup (Recommended)
Add to crontab for daily cleanup:
```bash
# Edit crontab
crontab -e

# Add this line for daily cleanup at 2 AM
0 2 * * * /usr/bin/php /opt/lampp/htdocs/hotel-management/cleanup_booking_history.php
```

## Security Features

### Access Control
- Only logged-in users can access history
- Hotel managers can only see their hotel's history
- Admins can see all hotel histories

### Data Protection
- Sensitive data is stored securely
- Old records are automatically purged
- Audit trail is maintained for compliance

## Troubleshooting

### Common Issues

1. **History Not Showing**
   - Check if user is logged in
   - Verify hotel_id parameter
   - Check database connection

2. **Cleanup Not Working**
   - Verify file permissions
   - Check PHP path in cron job
   - Review error logs

3. **Performance Issues**
   - Ensure indexes are created
   - Run cleanup script regularly
   - Monitor database size

### Error Messages
- **"No booking history records found"**: No changes have been logged yet
- **"Database connection failed"**: Check database configuration
- **"Permission denied"**: Check file and folder permissions

## API Functions

### Core Functions
- `log_booking_change()`: Log a booking change
- `get_booking_history()`: Retrieve history records
- `cleanup_old_booking_history()`: Clean up old records
- `format_action_details()`: Format change details for display

### Usage in Code
```php
// Log a booking update
log_booking_change($hotel_id, $booking_id, 'updated', 'Price changed', $old_data, $new_data);

// Get history records
$history = get_booking_history($hotel_id, 50, 0, $filters);

// Clean up old records
$deleted_count = cleanup_old_booking_history();
```

## Best Practices

1. **Regular Monitoring**: Check history page regularly
2. **Scheduled Cleanup**: Set up automatic cleanup
3. **Backup**: Include history in regular backups
4. **Performance**: Monitor database performance
5. **Security**: Review access logs regularly

## Support

For issues or questions about the Booking History system:
1. Check this README first
2. Review error logs
3. Test with sample data
4. Contact system administrator

---

**Note**: The Booking History system is designed to provide transparency and accountability in booking management while maintaining system performance through automatic cleanup. 