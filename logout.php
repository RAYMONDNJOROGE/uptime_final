<?php
// Secure session settings (optional but consistent)
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0);

session_start();

// Destroy session and clear all data
$_SESSION = [];
session_unset();
session_destroy();

// Clear "remember me" cookie if set
setcookie('remember_username', '', time() - 3600, "/");

// Redirect to login page
header('Location: index.php');
exit;