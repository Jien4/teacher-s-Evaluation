<?php
// ==============================
// DATABASE CONFIGURATION
// ==============================
define('DB_HOST','127.0.0.1');
define('DB_NAME','teacher_eval_db');
define('DB_USER','root');
define('DB_PASS','');

// Start session (only here!)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    $conn = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die('DB Connection error: '.$e->getMessage());
}


// ==============================
// SMTP EMAIL CONFIGURATION
// ==============================
// NOTE: If using Gmail, you MUST use an App Password (NOT your Gmail password)

// Required for PHPMailer SMTP
$mail_host       = 'smtp.gmail.com';  // Gmail SMTP Host
$mail_port       = 587;               // TLS port (587) / SSL port (465)
$mail_username   = 'canapiaairajezelle@gmail.com'; // <<< CHANGE THIS
$mail_password   = 'woow crti qxdf eoei'; // <<< CHANGE THIS (16-digit Gmail App Password)
$mail_from       = 'canapiaairajezelle@gmail.com'; // Can be same as username
$mail_from_name  = 'TeacherEval System';

// OPTIONAL Override (mostly auto-detected)
// $mail_smtp_secure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;


// ==============================
// HELPER FUNCTIONS
// ==============================
function current_ip() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}
?>
