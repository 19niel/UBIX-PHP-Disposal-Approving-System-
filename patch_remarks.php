<?php
require 'db.php';

try {
    $pdo->exec("ALTER TABLE request_approvals ADD COLUMN remarks TEXT NULL");
    echo "Column 'remarks' added successfully.\n";
} catch(PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Column 'remarks' already exists.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
?>
