# Uptime Hotspot - Setup Instructions

Complete guide to setting up your MikroTik hotspot with M-Pesa payment integration.

## 📋 Prerequisites

1. **MikroTik Router** with RouterOS v6.0 or higher
2. **Web Server** with PHP 7.4+ and MySQL 5.7+
3. **M-Pesa Business Account** (Paybill or Till Number)
4. **SSL Certificate** (required for production)
5. **Safaricom Developer Account** (for M-Pesa API credentials)

## 🚀 Installation Steps

### 1. Database Setup

```bash
# Login to MySQL
mysql -u root -p

# Create database and import schema
mysql -u root -p < database.sql

# Verify tables were created
mysql -u root -p uptime_hotspot -e "SHOW TABLES;"
```

### 2. File Structure

Upload files to your web server:

```
/var/www/html/
├── hotspot/
│   ├── index.html          # Hotspot login portal
│   └── md5.js             # MD5 library for CHAP auth
├── api/
│   ├── config.php         # Configuration file
│   ├── mikrotikapi.php    # MikroTik API class
│   ├── mpesa-initiate.php # Payment initiation
│   └── mpesa-callback.php # Payment callback handler
```

### 3. Configure MikroTik Router

#### A. Enable API Service
```bash
/ip service
set api address=0.0.0.0/0 disabled=no
```

#### B. Create Hotspot Server (if not exists)
```bash
/ip hotspot setup
# Follow the wizard or use manual configuration
```

#### C. Set Hotspot Login Page
```bash
/ip hotspot profile
set default login-by=http-chap
set default html-directory=hotspot

# Upload your custom login page to Files > hotspot folder
# Or use the web interface: WebFig > Files > Upload
```

#### D. Create User Profiles
```bash
# Default profile for paid users
/ip hotspot user profile
add name=default shared-users=1 rate-limit=2M/2M

# You can create different profiles for different plans
add name=premium shared-users=1 rate-limit=5M/5M
```

### 4. Configure M-Pesa Credentials

#### A. Get M-Pesa API Credentials
1. Go to [Safaricom Developer Portal](https://developer.safaricom.co.ke/)
2. Create an account and login
3. Create a new app
4. Get your **Consumer Key** and **Consumer Secret**
5. Get your **Passkey** from Lipa Na M-Pesa Online

#### B. Update config.php
```php
define('MPESA_CONSUMER_KEY', 'your_consumer_key');
define('MPESA_CONSUMER_SECRET', 'your_consumer_secret');
define('MPESA_PASSKEY', 'your_passkey');
define('MPESA_SHORTCODE', 'your_shortcode');
define('MPESA_CALLBACK_URL', 'https://yourdomain.com/api/mpesa-callback.php');
```

#### C. Register Callback URLs
Register your callback URL in the Safaricom Developer Portal:
- **Validation URL**: Not required for STK Push
- **Confirmation URL**: `https://yourdomain.com/api/mpesa-callback.php`

### 5. Update Configuration Files

#### config.php
```php
// Database
define('DB_HOST', 'localhost');
define('DB_NAME', 'uptime_hotspot');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');

// MikroTik
define('MIKROTIK_HOST', '192.168.88.1');
define('MIKROTIK_USER', 'admin');
define('MIKROTIK_PASS', 'your_mikrotik_password');

// M-Pesa - Update with your credentials
define('MPESA_ENV', 'sandbox'); // Change to 'production' when ready
```

#### index.html
Update the backend URL:
```javascript
const BACKEND_URL = '/api'; // Or full URL: https://yourdomain.com/api
```

### 6. Set File Permissions

```bash
# Make sure web server can write logs
chmod 755 /var/www/html/api/
chmod 644 /var/www/html/api/*.php
touch /var/www/html/api/mpesa_callback_log.txt
chmod 666 /var/www/html/api/mpesa_callback_log.txt
```

### 7. Configure Hotspot Walled Garden

Allow access to your payment API without login:

```bash
/ip hotspot walled-garden
add dst-host=yourdomain.com action=accept
add dst-host=api.safaricom.co.ke action=accept
add dst-host=sandbox.safaricom.co.ke action=accept
```

### 8. Test the Setup

#### A. Test Database Connection
```bash
php -r "require 'config.php'; var_dump(getDBConnection());"
```

#### B. Test MikroTik API Connection
```bash
php -r "require 'mikrotikapi.php'; \$mt = new MikrotikAPI(); var_dump(\$mt->connect());"
```

#### C. Test M-Pesa Integration (Sandbox)
1. Access your hotspot portal
2. Select a plan
3. Enter test phone: `254708374149` (Safaricom sandbox test number)
4. Complete STK push on your phone
5. Check if user is created in MikroTik: `/ip hotspot user print`

## 🔧 Troubleshooting

### MikroTik API Issues

**Error: Connection timeout**
```bash
# Check if API is enabled and accessible
/ip service print
/ip service set api address=0.0.0.0/0 disabled=no

# Check firewall rules
/ip firewall filter print
```

**Error: Login failed**
- Verify username and password in config.php
- Check if API user has sufficient permissions

### M-Pesa Issues

**Error: Invalid Access Token**
- Verify Consumer Key and Secret
- Check if credentials are for correct environment (sandbox/production)

**Error: Callback URL not reachable**
- Ensure your server has SSL certificate
- Verify callback URL is publicly accessible
- Check firewall allows incoming connections

**STK Push not received**
- Verify phone number format (254XXXXXXXXX)
- Check if number is active and has M-Pesa
- Review mpesa_callback_log.txt for errors

### Database Issues

**Error: Connection failed**
```bash
# Check MySQL is running
sudo systemctl status mysql

# Verify credentials
mysql -u your_user -p uptime_hotspot
```

## 📱 Going to Production

### 1. Update M-Pesa Environment
```php
define('MPESA_ENV', 'production');
```

### 2. Get Production Credentials
- Login to Safaricom Developer Portal
- Switch app to production mode
- Get production Consumer Key, Secret, and Passkey

### 3. Enable SSL/HTTPS
```bash
# Install Certbot
sudo apt install certbot python3-certbot-apache

# Get SSL certificate
sudo certbot --apache -d yourdomain.com
```

### 4. Secure Your Setup
```php
// In config.php
error_reporting(0);
ini_set('display_errors', 0);

// Enable secure cookies
ini_set('session.cookie_secure', 1);
```

### 5. Setup Monitoring
- Monitor `api_logs` table for errors
- Setup cron job for database cleanup:
```bash
# Add to crontab (crontab -e)
0 2 * * * mysql -u root -p'password' uptime_hotspot -e "CALL cleanup_old_records();"
```

## 📊 Monitoring & Maintenance

### Check Transactions
```sql
SELECT * FROM transaction_summary;
```

### View Active Users
```sql
SELECT * FROM active_users_view;
```

### Check Failed Payments
```sql
SELECT * FROM transactions WHERE status = 'failed' ORDER BY created_at DESC LIMIT 10;
```

### Clear Old Logs
```sql
CALL cleanup_old_records();
```

## 🔐 Security Recommendations

1. **Use strong passwords** for database and MikroTik
2. **Enable HTTPS** for all endpoints
3. **Restrict API access** by IP if possible
4. **Regular backups** of database
5. **Monitor logs** for suspicious activity
6. **Update regularly** - keep PHP, MySQL, and RouterOS updated

## 📞 Support

- **Email**: uptimehotspot@gmail.com
- **Phone**: +254 791 024 153

## 📄 License

This software is provided as-is for commercial use.

---

**Note**: Always test thoroughly in sandbox environment before going live!