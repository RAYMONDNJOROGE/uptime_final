# Quick Setup Guide

## 📁 File Structure

```
/var/www/html/
├── index.html           # Your frontend file (already have)
├── md5.js              # MD5 library for CHAP
├── pay.php             # Payment initiation
├── callback.php        # M-Pesa callback handler
├── config.php          # Configuration
└── mikrotikapi.php     # MikroTik API class
```

## ⚡ Quick Start (5 Minutes)

### 1. Update config.php

```php
// Database (lines 11-14)
define('DB_HOST', 'localhost');
define('DB_NAME', 'uptime_hotspot');
define('DB_USER', 'root');
define('DB_PASS', 'your_password');

// MikroTik (lines 17-20)
define('MIKROTIK_HOST', '192.168.88.1');  // Your router IP
define('MIKROTIK_USER', 'admin');
define('MIKROTIK_PASS', 'your_mikrotik_password');

// M-Pesa (lines 24-27)
define('MPESA_CONSUMER_KEY', 'your_key');
define('MPESA_CONSUMER_SECRET', 'your_secret');
define('MPESA_PASSKEY', 'your_passkey');
define('MPESA_SHORTCODE', 'your_shortcode');

// Callback URL (line 38)
define('MPESA_CALLBACK_URL', 'https://yourdomain.com/callback.php');
```

### 2. Import Database

```bash
mysql -u root -p < database.sql
```

### 3. Test Connections

Create `test.php`:

```php
<?php
require_once 'config.php';
require_once 'mikrotikapi.php';

echo "Testing Database... ";
$pdo = getDBConnection();
echo $pdo ? "✓ Connected\n" : "✗ Failed\n";

echo "Testing MikroTik... ";
$mt = new MikrotikAPI();
echo $mt->connect() ? "✓ Connected\n" : "✗ Failed\n";

echo "\nSetup looks good! 🎉\n";
?>
```

Run: `php test.php`

### 4. Enable MikroTik API

On your router:
```
/ip service
set api address=0.0.0.0/0 disabled=no
```

### 5. Configure Walled Garden

Allow access to your payment server:
```
/ip hotspot walled-garden
add dst-host=yourdomain.com action=accept
add dst-host=api.safaricom.co.ke action=accept
add dst-host=sandbox.safaricom.co.ke action=accept
```

### 6. Set Custom Login Page

Upload `index.html` to your MikroTik:
- Files > hotspot folder
- Or via FTP to `/hotspot/` directory

Then set it:
```
/ip hotspot profile
set default html-directory=hotspot
set default login-by=http-chap
```

## 🧪 Testing

### Test with Safaricom Sandbox

1. Use test number: `254708374149`
2. Test amount: Any amount from your plans
3. You'll get an instant response in sandbox mode

### Check Logs

```bash
# Check callback logs
tail -f mpesa_callbacks.log

# Check error logs
tail -f error.log

# Check database
mysql -u root -p uptime_hotspot -e "SELECT * FROM transactions ORDER BY created_at DESC LIMIT 5;"
```

## 🐛 Troubleshooting

### "Database connection failed"
```bash
# Check MySQL is running
sudo systemctl status mysql

# Verify credentials
mysql -u root -p uptime_hotspot
```

### "Failed to connect to MikroTik"
```bash
# Test telnet to API port
telnet 192.168.88.1 8728

# Check firewall
/ip firewall filter print
```

### "STK Push not received"
- Verify phone number format (254XXXXXXXXX)
- Check M-Pesa credentials in config.php
- Ensure callback URL is publicly accessible
- Check mpesa_callbacks.log

### "User not created in MikroTik"
- Check if user exists: `/ip hotspot user print`
- Verify API credentials
- Check error.log for details
- Test connection with test.php

## 🔒 Production Checklist

- [ ] Change MPESA_ENV to 'production'
- [ ] Get production M-Pesa credentials
- [ ] Enable HTTPS/SSL certificate
- [ ] Update MPESA_CALLBACK_URL to HTTPS
- [ ] Disable error display in config.php:
  ```php
  error_reporting(0);
  ini_set('display_errors', 0);
  ```
- [ ] Set strong database password
- [ ] Restrict API access by IP
- [ ] Setup database backups
- [ ] Test thoroughly before going live

## 📞 Support

If you get stuck:
1. Check error.log
2. Check mpesa_callbacks.log
3. Check database transactions table
4. Contact: uptimehotspot@gmail.com

## 🎯 Quick Commands

```bash
# View recent transactions
mysql -u root -p uptime_hotspot -e "SELECT phone, amount, plan, status, created_at FROM transactions ORDER BY created_at DESC LIMIT 10;"

# View MikroTik users
ssh admin@192.168.88.1 "/ip hotspot user print"

# Clear old logs
rm mpesa_callbacks.log error.log

# Restart web server
sudo systemctl restart apache2
# or
sudo systemctl restart nginx
```

---

**Your setup should now be working! 🚀**

Test by connecting to your hotspot and trying to purchase a plan.