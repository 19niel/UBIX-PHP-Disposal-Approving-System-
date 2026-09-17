<?php
// dashboard.php - Router for dashboards
require 'auth.php';
require 'db.php';

$user_id = $_SESSION['user_id'];
$is_admin = ($_SESSION['user_role'] === 'admin');

if ($is_admin) {
    require 'admin_dashboard.php';
} else {
    require 'user_dashboard.php';
}
?>
