<?php
$host = 'localhost';
$user = 'root';
$password = '';
$dbname = 'disposal_app';

try {
    // Connect without DB first to create it
    $pdo = new PDO("mysql:host=$host;charset=utf8", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create DB
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname`");
    $pdo->exec("USE `$dbname`");
    
    // 1. Users Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        username VARCHAR(100) NOT NULL UNIQUE,
        email VARCHAR(100) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('admin', 'standard') DEFAULT 'standard',
        job_position VARCHAR(100) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    // 2. Workflow Routing Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS workflow_routing (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sort_number INT NOT NULL,
        user_id INT NOT NULL,
        tier_label VARCHAR(100) NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    
    // 3. Requests Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        creator_user_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        department VARCHAR(100) NOT NULL,
        memo_number VARCHAR(100) NOT NULL,
        message TEXT,
        attachment_path VARCHAR(255),
        status ENUM('Pending', 'Approved', 'Rejected') DEFAULT 'Pending',
        current_sort_number INT DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (creator_user_id) REFERENCES users(id)
    )");
    
    // 4. Request Approvals Table (History)
    $pdo->exec("CREATE TABLE IF NOT EXISTS request_approvals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        request_id INT NOT NULL,
        sort_number INT NOT NULL,
        user_id INT NOT NULL,
        admin_bypass_id INT NULL,
        tier_label VARCHAR(100) NOT NULL,
        signature_base64 LONGTEXT,
        action_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        status ENUM('Approved', 'Rejected') DEFAULT 'Approved',
        FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id),
        FOREIGN KEY (admin_bypass_id) REFERENCES users(id) ON DELETE SET NULL
    )");
    
    // 5. Magic Links Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS magic_links (
        id INT AUTO_INCREMENT PRIMARY KEY,
        token VARCHAR(255) NOT NULL UNIQUE,
        request_id INT NOT NULL,
        user_id INT NOT NULL,
        is_used TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    // 6. Workflow Final Emails Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS workflow_final_emails (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    echo "Database and tables created successfully!\n";
    
    // Insert a default admin user if no users exist
    $stmt = $pdo->query("SELECT COUNT(*) FROM users");
    if ($stmt->fetchColumn() == 0) {
        $defaultPassword = password_hash('password', PASSWORD_DEFAULT);
        $pdo->exec("INSERT INTO users (name, username, email, password_hash, role) VALUES ('Admin', 'admin', 'admin@example.com', '$defaultPassword', 'admin')");
        echo "Default admin user created: admin / admin@example.com / password\n";
    }

} catch(PDOException $e) {
    die("Setup failed: " . $e->getMessage());
}
?>
