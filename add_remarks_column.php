<?php
require 'db.php';

try {
    $pdo->exec("ALTER TABLE request_approvals ADD COLUMN remarks TEXT NULL");
    echo "Column 'remarks' added successfully to the request_approvals table.\n";
} catch(PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Column 'remarks' already exists in the request_approvals table.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
?>
