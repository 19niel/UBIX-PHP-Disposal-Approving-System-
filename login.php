<?php
session_start();
require 'db.php';

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $login_id = $_POST['email'];
    $password = $_POST['password'];

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? OR username = ?");
    $stmt->execute([$login_id, $login_id]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_role'] = $user['role'];
        header("Location: dashboard.php");
        exit();
    } else {
        $error = "Invalid email or password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Disposal App</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="login-container">
    <div class="card login-card">
        <div style="text-align: center; margin-bottom: 1rem;">
            <img src="Images/Ubix_Logo.png" alt="Ubix Logo" style="max-height: 80px;">
        </div>
        <div class="login-logo" style="display: flex; align-items: center; justify-content: center; gap: 0.5rem;">
            <svg style="width: 32px; height: 32px; color: var(--primary);" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
            </svg>
            <div>Disposal<span style="color: var(--primary);">App</span></div>
        </div>
        <div class="login-subtitle">Secure Asset Disposal System</div>
        
        <?php if ($error): ?>
            <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group" style="text-align: left;">
                <label class="form-label">Email or Username</label>
                <input type="text" name="email" class="form-control" required placeholder="admin">
            </div>
            <div class="form-group" style="text-align: left;">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control" required placeholder="••••••••">
            </div>
            <button type="submit" class="btn" style="width: 100%; padding: 0.875rem; font-size: 1rem;">Sign In</button>
        </form>
    </div>
</body>
</html>
