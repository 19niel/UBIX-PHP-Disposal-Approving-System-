<?php
require 'db.php';
$stmt = $pdo->prepare("SELECT id, request_id, sort_number, user_id, admin_bypass_id, tier_label, action_date FROM request_approvals");
$stmt->execute();
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
