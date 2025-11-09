# Deployment Checklist

Complete this checklist to ensure your Uptime Hotspot is properly configured.

## 📋 Pre-Deployment

### Files Upload
- [ ] `index.html` - Frontend portal
- [ ] `md5.js` - MD5 library
- [ ] `pay.php` - Payment endpoint
- [ ] `callback.php` - Callback handler
- [ ] `config.php` - Configuration
- [ ] `mikrotikapi.php` - MikroTik API
- [ ] `database.sql` - Database schema
- [ ] `.htaccess` - Apache config (optional)
- [ ] `test.php` - Testing script

### Database Setup
- [ ] Create database: `uptime_hotspot`
- [ ] Import schema: `mysql -u root -p < database.sql`
- [ ] Create database user with appropriate permissions
- [ ] Test connection: `php test.php`

### MikroTik Configuration
- [ ] Enable API service on port 8728
- [ ] Create/verify admin credentials
- [ ] Set up hotspot server
- [ ] Configure user profiles (default, premium, etc.)
- [ ] Upload custom login page (index.html) to `/hotspot/`
- [ ] Set profile to use custom page
- [ ] Test MikroTik API connection

### M-Pesa Setup
- [ ] Create Safaricom Developer account
- [ ] Create app and get credentials
- [ ] Get Consumer Key
- [ ] Get Consumer Secret
- [ ] Get Passkey
- [ ] Note your Shortcode
- [ ] Test in sandbox mode first

## ⚙️ Configuration

### config.php Settings
- [ ] Update `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`
- [ ] Update `MIKROTIK_HOST`, `MIKROTIK_USER`, `MIKROTIK_PASS`
- [ ] Update `MPESA_CONSUMER_KEY`
- [ ] Update `MPESA_CONSUMER_SECRET`
- [ ] Update `MPESA_PASSKEY`
- [ ] Update `MPESA_SHORTCODE`
- [ ] Update `MPESA_CALLBACK_URL` (must be public HTTPS)
- [ ] Verify `MPESA_ENV` is set to 'sandbox' for testing

### Frontend Configuration (index.html)
- [ ] Update `BACKEND_URL` constant (line 184)
- [ ] Verify plan prices match backend config
- [ ] Update support contact info if needed

### MikroTik Walled Garden
```bash
# Add these to allow payment without login
/ip hotspot walled-garden
add dst-host=yourdomain.com action=accept
add dst-host=api.safaricom.co.ke action=accept
add dst-host=sandbox.safaricom.co.ke action=accept
```

### Hotspot Profile
```bash
/ip hotspot profile
set default login-by=http-chap
set default html-directory=hotspot
```

## 🧪 Testing Phase

### Run System Tests
- [ ] Run `php test.php` - all tests should pass
- [ ] Check database tables created
- [ ] Verify MikroTik connection works
- [ ] Test user creation/deletion

### Test Payment Flow (Sandbox)
- [ ] Connect to hotspot
- [ ] Select a plan (start with cheapest)
- [ ] Enter test phone: `254708374149`
- [ ] Verify STK push sent
- [ ] Complete payment on phone
- [ ] Check user created in database
- [ ] Check user created in MikroTik
- [ ] Test auto-login after payment

### Test Login
- [ ] Login with created credentials
- [ ] Verify internet access works
- [ ] Check session tracking in database
- [ ] Test logout

### Check Logs
- [ ] View `error.log` - should be clean
- [ ] View `mpesa_callbacks.log` - should show callback data
- [ ] Check database `transactions` table
- [ ] Check database `api_logs` table

## 🚀 Production Deployment

### Security Hardening
- [ ] Change all default passwords
- [ ] Set strong database password
- [ ] Restrict MikroTik API access by IP
- [ ] Disable error display in config.php:
  ```php
  error_reporting(0);
  ini_set('display_errors', 0);
  ```
- [ ] Remove or protect `test.php`
- [ ] Set proper file permissions:
  ```bash
  chmod 644 *.php
  chmod 755 .
  chmod 666 *.log
  ```

### SSL/HTTPS Setup
- [ ] Install SSL certificate (Let's Encrypt recommended)
- [ ] Update callback URL to HTTPS
- [ ] Force HTTPS in .htaccess or nginx config
- [ ] Test HTTPS access to all endpoints

### M-Pesa Production
- [ ] Switch to production credentials
- [ ] Update `MPESA_ENV` to 'production'
- [ ] Update `MPESA_CONSUMER_KEY` (production)
- [ ] Update `MPESA_CONSUMER_SECRET` (production)
- [ ] Update `MPESA_PASSKEY` (production)
- [ ] Update callback URL in Safaricom portal
- [ ] Test with real money (small amount first!)

### DNS & Domain
- [ ] Point domain to your server
- [ ] Update callback URL to actual domain
- [ ] Test public accessibility
- [ ] Update walled garden with correct domain

### Monitoring Setup
- [ ] Setup log rotation for error.log
- [ ] Setup log rotation for mpesa_callbacks.log
- [ ] Create backup script for database
- [ ] Setup cron for database cleanup:
  ```bash
  0 2 * * * mysql -u root -pPASS uptime_hotspot -e "CALL cleanup_old_records();"
  ```
- [ ] Monitor disk space
- [ ] Setup email alerts for errors

## ✅ Final Checks

### Functionality Tests
- [ ] Test all plan purchases (2h, 6h, 24h, 7d)
- [ ] Test with different phone numbers
- [ ] Test existing user login
- [ ] Test payment failure handling
- [ ] Test payment timeout scenario
- [ ] Test concurrent payments

### Performance Tests
- [ ] Test with multiple simultaneous users
- [ ] Check database query performance
- [ ] Monitor MikroTik API response times
- [ ] Verify memory usage is acceptable

### User Experience
- [ ] Test on mobile devices
- [ ] Test on different browsers
- [ ] Verify all error messages are clear
- [ ] Check UI is responsive
- [ ] Test support contact links work

## 📊 Post-Deployment

### Day 1
- [ ] Monitor all logs closely
- [ ] Check first real transactions
- [ ] Verify payments are received
- [ ] Check users can login and browse

### Week 1
- [ ] Review transaction success rate
- [ ] Check for any recurring errors
- [ ] Monitor system resource usage
- [ ] Collect user feedback

### Ongoing
- [ ] Weekly database backups
- [ ] Monthly log review
- [ ] Update credentials regularly
- [ ] Monitor transaction reports:
  ```sql
  SELECT * FROM transaction_summary;
  ```

## 🆘 Emergency Contacts

- **Safaricom M-Pesa Support**: 0711 051 542
- **Your Support Email**: uptimehotspot@gmail.com
- **Your Support Phone**: +254 791 024 153

## 📝 Important URLs

- **Safaricom Developer Portal**: https://developer.safaricom.co.ke/
- **MikroTik Documentation**: https://wiki.mikrotik.com/
- **Your Payment Portal**: [Your Domain]/index.html
- **API Endpoint**: [Your Domain]/pay.php
- **Callback URL**: [Your Domain]/callback.php

---

## ⚠️ Common Issues

**Payment doesn't complete**
- Check callback URL is publicly accessible
- Verify HTTPS is working
- Check mpesa_callbacks.log
- Ensure callback URL registered in Safaricom portal

**User not created in MikroTik**
- Check MikroTik API is enabled
- Verify credentials in config.php
- Check error.log for details
- Test with `php test.php`

**Database errors**
- Verify database exists and tables created
- Check database credentials
- Ensure user has proper permissions
- Check disk space

---

**Once all items are checked, your system is ready for production! 🎉**