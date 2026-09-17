<?php
$host = 'localhost';
$user = 'root';
$password = '';
$dbname = 'disposal_app';

// Set default timezone for PHP functions
date_default_timezone_set('Asia/Manila');

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Ensure MySQL uses the same timezone
    $pdo->exec("SET time_zone = '+08:00'");
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>
