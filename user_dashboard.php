<?php
// user_dashboard.php
// Assumes auth.php and db.php are already required in dashboard.php
// Assumes $pdo, $user_id, and $_SESSION['user_name'] are available.

// Fetch counts for the tiles
$stmt = $pdo->prepare("SELECT COUNT(*) FROM requests WHERE status = 'Pending'");
$stmt->execute();
$count_pending = $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM requests r 
    JOIN workflow_routing w ON r.current_sort_number = w.sort_number 
    WHERE r.status = 'Pending' AND w.user_id = ?
");
$stmt->execute([$user_id]);
$count_to_approve = $stmt->fetchColumn();
$total_pending_action = $count_to_approve;

$stmt = $pdo->prepare("SELECT COUNT(*) FROM requests WHERE status = 'Approved'");
$stmt->execute();
$count_approved = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM requests WHERE status = 'Rejected'");
$stmt->execute();
$count_rejected = $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="Images/favicon.png">
    <meta charset="UTF-8">
    <title>Dashboard - Disposal App</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .stats-grid { 
            display: grid; 
            grid-template-columns: 1fr 1fr; 
            gap: 1.5rem; 
            margin-bottom: 2rem; 
        }
        .stat-card { 
            padding: 3rem; 
            border-radius: 12px; 
            background: var(--card); 
            border: 1px solid var(--border); 
            text-align: center; 
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            text-decoration: none;
            display: block;
            transition: all 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.15);
        }
        .stat-number { font-size: 4rem; font-weight: bold; color: var(--primary); margin-bottom: 0.5rem; line-height: 1;}
        .stat-label { color: var(--text-muted); font-size: 1.2rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }
        .stat-icon { font-size: 3rem; margin-bottom: 1rem; }
        .notification-badge {
            position: absolute;
            top: 1rem;
            left: 1rem;
            min-width: 28px;
            height: 28px;
            padding: 0 6px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.875rem;
            font-weight: bold;
            color: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
            <div style="display: flex; align-items: center; gap: 1rem;">
                <img src="Images/Ubix_Logo.png" alt="Ubix Logo" style="height: 50px;">
                <h2 style="margin: 0;">Welcome, <?php echo htmlspecialchars($_SESSION['user_name']); ?></h2>
            </div>
            <div style="display: flex; gap: 1rem;">
                <a href="logout.php" class="btn" style="background: var(--danger);">Logout</a>
            </div>
        </div>

        <div class="stats-grid">
            
            <a href="list.php?status=all" class="stat-card" style="border-top: 6px solid var(--primary); position: relative;">
                <div class="stat-icon">📄</div>
                <div class="stat-label" style="color: var(--primary);">Requests</div>
            </a>

            <a href="list.php?status=pending" class="stat-card" style="border-top: 6px solid #D97706; position: relative;">
                <?php if($total_pending_action > 0): ?>
                    <div class="notification-badge" style="background: #D97706;"><?php echo $total_pending_action; ?></div>
                <?php endif; ?>
                <div class="stat-icon">⏳</div>
                <div class="stat-label">Pending Requests</div>
            </a>

            <a href="list.php?status=approved" class="stat-card" style="border-top: 6px solid var(--success); position: relative;">
                <div class="stat-icon">✅</div>
                <div class="stat-label">Approved Requests</div>
            </a>

            <a href="list.php?status=rejected" class="stat-card" style="border-top: 6px solid var(--danger); position: relative;">
                <div class="stat-icon">❌</div>
                <div class="stat-label">Rejected Requests</div>
            </a>

        </div>
    </div>
</body>
</html>
