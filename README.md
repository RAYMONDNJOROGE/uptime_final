# Uptime Hotspot Payment System

Complete MikroTik hotspot portal with M-Pesa payment integration. Accept mobile payments and automatically create user accounts.

## 🌟 Features

- ✅ **M-Pesa STK Push** - Automated payment prompts
- ✅ **Auto User Creation** - Instant account creation on payment
- ✅ **CHAP Authentication** - Secure hotspot login
- ✅ **Multiple Plans** - 2h, 6h, 24h, 7 days
- ✅ **Real-time Status** - Live payment tracking
- ✅ **Complete Logging** - Full audit trail
- ✅ **Responsive Design** - Mobile-friendly interface
- ✅ **Error Handling** - Comprehensive error management

## 📦 What's Included

### Frontend
- `index.html` - Beautiful hotspot login portal
- `md5.js` - MD5 library for CHAP authentication

### Backend (PHP)
- `pay.php` - Payment initiation endpoint
- `callback.php` - M-Pesa callback handler
- `config.php` - Centralized configuration
- `mikrotikapi.php` - MikroTik API integration

### Database
- `database.sql` - Complete schema with tables, views, and procedures

### Documentation
- `README.md` - This file
- `QUICK_SETUP.md` - 5-minute quick start guide
- `DEPLOYMENT_CHECKLIST.md` - Complete deployment checklist
- `SETUP_INSTRUCTIONS.md` - Detailed setup guide

### Tools
- `test.php` - System verification script
- `.htaccess` - Apache configuration

## 🚀 Quick Start

### 1. Upload Files
```bash
# Upload all files to your web server
# Recommended structure:
/var/www/html/
├── index.html
├── md5.js
├── pay.php
├── callback.php
├── config.php
├── mikrotikapi.php
└── test.php
```

### 2. Setup Database
```bash
mysql -u root -p < database.sql
```

### 3. Configure
Edit `config.php`:
```php
// Database
define('DB_HOST', 'localhost');
define('DB_NAME', 'uptime_hotspot');
define('DB_USER', 'root');
define('DB_PASS', 'your_password');

// MikroTik
define('MIKROTIK_HOST', '192.168.88.1');
define('MIKROTIK_USER', 'admin');
define('MIKROTIK_PASS', 'your_password');

// M-Pesa
define('MPESA_CONSUMER_KEY', 'your_key');
define('MPESA_CONSUMER_SECRET', 'your_secret');
define('MPESA_PASSKEY', 'your_passkey');
define('MPESA_SHORTCODE', 'your_shortcode');
define('MPESA_CALLBACK_URL', 'https://yourdomain.com/callback.php');
```

### 4. Test
```bash
php test.php
```

### 5. Deploy
Upload `index.html` to your MikroTik router and configure hotspot profile.

## 📋 Requirements

- **MikroTik Router** with RouterOS v6.0+
- **PHP** 7.4 or higher
- **MySQL** 5.7 or higher
- **Apache/Nginx** web server
- **SSL Certificate** (for production)
- **M-Pesa Business Account**
- **Safaricom Developer Account**

### PHP Extensions Required
- PDO
- pdo_mysql
- curl
- json
- mbstring

## 🎯 Payment Plans

Default plans (configurable):

| Plan | Duration | Price |
|------|----------|-------|
| 2 Hours | 2h | KES 10 |
| 6 Hours | 6h | KES 20 |
| 1 Day | 24h | KES 50 |
| 7 Days | 7d | KES 200 |

## 🔧 MikroTik Configuration

### Enable API
```bash
/ip service
set api address=0.0.0.0/0 disabled=no
```

### Configure Hotspot Profile
```bash
/ip hotspot profile
set default login-by=http-chap
set default html-directory=hotspot
```

### Setup Walled Garden
```bash
/ip hotspot walled-garden
add dst-host=yourdomain.com action=accept
add dst-host=api.safaricom.co.ke action=accept
add dst-host=sandbox.safaricom.co.ke action=accept
```

## 💳 M-Pesa Setup

1. Create account at [Safaricom Developer Portal](https://developer.safaricom.co.ke/)
2. Create a new app
3. Get credentials:
   - Consumer Key
   - Consumer Secret
   - Passkey
4. Test in sandbox first
5. Switch to production when ready

## 🧪 Testing

### System Test
```bash
php test.php
```

### Test Payment (Sandbox)
1. Connect to hotspot
2. Select a plan
3. Use test number: `254708374149`
4. Complete payment
5. Verify user created

### Check Logs
```bash
tail -f error.log
tail -f mpesa_callbacks.log
```

### Database Check
```bash
mysql -u root -p uptime_hotspot -e "SELECT * FROM transactions ORDER BY created_at DESC LIMIT 5;"
```

## 📊 Database Tables

- `transactions` - Payment records
- `users` - User accounts
- `active_sessions` - Session tracking
- `payment_logs` - Detailed payment logs
- `api_logs` - API request logs
- `settings` - System configuration

## 🔒 Security

### Before Production
- [ ] Enable HTTPS/SSL
- [ ] Change all default passwords
- [ ] Disable error display
- [ ] Restrict file permissions
- [ ] Setup firewall rules
- [ ] Regular backups

### File Permissions
```bash
chmod 644 *.php
chmod 755 .
chmod 666 *.log
```

## 📈 Monitoring

### View Transactions
```sql
SELECT * FROM transaction_summary;
```

### Active Users
```sql
SELECT * FROM active_users_view;
```

### Failed Payments
```sql
SELECT * FROM transactions 
WHERE status = 'failed' 
ORDER BY created_at DESC 
LIMIT 10;
```

## 🐛 Troubleshooting

### Common Issues

**"Database connection failed"**
- Check MySQL is running
- Verify credentials in config.php

**"Failed to connect to MikroTik"**
- Enable API service
- Check firewall rules
- Verify credentials

**"STK Push not received"**
- Check phone number format
- Verify M-Pesa credentials
- Ensure callback URL is accessible

**"User not created"**
- Check MikroTik API connection
- Review error.log
- Verify user profile exists

### Debug Mode
Enable in config.php:
```php
error_reporting(E_ALL);
ini_set('display_errors', 1);
```

## 📞 Support

- **Email**: uptimehotspot@gmail.com
- **Phone**: +254 791 024 153

## 📚 Documentation

- `QUICK_SETUP.md` - Get started in 5 minutes
- `DEPLOYMENT_CHECKLIST.md` - Complete deployment guide
- `SETUP_INSTRUCTIONS.md` - Detailed installation steps

## 🔄 Updates & Maintenance

### Daily
- Monitor logs for errors
- Check transaction success rate

### Weekly
- Review system performance
- Backup database

### Monthly
- Update credentials
- Review and clean old logs
- Check for system updates

## 📝 Changelog

### Version 1.0.0
- Initial release
- M-Pesa STK Push integration
- MikroTik API integration
- Auto user creation
- Complete logging system
- Responsive frontend
- CHAP authentication

## 🙏 Credits

Built for Uptime Hotspot
- Frontend: Modern responsive design
- Backend: PHP with MikroTik API
- Payment: Safaricom M-Pesa integration

## ⚖️ License

Proprietary - For commercial use by Uptime Hotspot

---

**Ready to deploy? Follow the QUICK_SETUP.md guide!**

For detailed instructions, see SETUP_INSTRUCTIONS.md
For deployment checklist, see DEPLOYMENT_CHECKLIST.md

## 🎉 Success Metrics

After deployment, you should see:
- ✓ Users purchasing plans automatically
- ✓ Instant account creation
- ✓ Seamless auto-login
- ✓ Real-time payment confirmation
- ✓ Complete transaction logs

**Need help? Contact support!**