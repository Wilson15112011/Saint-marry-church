<?php
// ================================================
// config.php – إعدادات عامة وأمان
// ================================================

// === Gmail SMTP Settings ===
if (!defined('GMAIL_USER')) define('GMAIL_USER', 'st.mary.and.archangel.michael1@gmail.com');
if (!defined('GMAIL_PASS')) define('GMAIL_PASS', 'bhtt sqwa icfk waed');

// === Security ===
if (!defined('SITE_NAME')) define('SITE_NAME', 'كنيسة العذراء والملاك ميخائيل');

// === Other Settings ===
if (!defined('OTP_EXPIRY_MINUTES'))       define('OTP_EXPIRY_MINUTES', 5);
if (!defined('RESET_OTP_EXPIRY_MINUTES')) define('RESET_OTP_EXPIRY_MINUTES', 15);