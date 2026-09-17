<?php
// admin_dashboard.php
// Assumes auth.php and db.php are already required in dashboard.php
// Assumes $pdo, $user_id, $is_admin, and $_SESSION['user_name'] are available.

if (!$is_admin) {
    die("Access Denied");
}

function getCurrentApprover($pdo, $sort_number) {
    $stmt = $pdo->prepare("SELECT u.name, w.tier_label FROM workflow_routing w JOIN users u ON w.user_id = u.id WHERE w.sort_number = ?");
    $stmt->execute([$sort_number]);
    $res = $stmt->fetch();
    return $res ? $res['name'] . " (" . $res['tier_label'] . ")" : "Unknown";
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_request_id'])) {
    $del_id = $_POST['delete_request_id'];
    
    // Fetch attachment path to delete file if it exists
    $stmt = $pdo->prepare("SELECT attachment_path FROM requests WHERE id = ?");
    $stmt->execute([$del_id]);
    $req = $stmt->fetch();
    
    if ($req && !empty($req['attachment_path'])) {
        $paths = json_decode($req['attachment_path'], true);
        if (!is_array($paths)) {
            $paths = [$req['attachment_path']];
        }
        foreach ($paths as $p) {
            if (file_exists($p)) {
                unlink($p);
            }
        }
    }
    
    // Delete from DB (cascade handles approvals and magic links)
    $stmt = $pdo->prepare("DELETE FROM requests WHERE id = ?");
    $stmt->execute([$del_id]);
    
    $message = "Request deleted successfully.";
}

$all_requests = $pdo->query("SELECT r.*, u.name as creator_name FROM requests r JOIN users u ON r.creator_user_id = u.id ORDER BY r.created_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="Images/favicon.png">
    <meta charset="UTF-8">
    <title>Admin Dashboard - Disposal App</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        .table th, .table td { padding: 1rem; text-align: left; border-bottom: 1px solid var(--border); }
        .table th { background: #F9FAFB; font-weight: 600; color: var(--text-muted); }
        .badge { padding: 0.25rem 0.5rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; }
        .badge-pending { background: #FEF3C7; color: #D97706; }
        .badge-approved { background: #D1FAE5; color: #065F46; }
        .badge-rejected { background: #FEE2E2; color: #DC2626; }
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

        <?php if (!empty($message)): ?>
            <div class="card" style="margin-bottom: 2rem; background: #D1FAE5; color: #065F46; padding: 1rem;">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div style="display: flex; gap: 1rem; margin-bottom: 2rem;">
            <a href="users.php" class="btn" style="background: var(--primary);">Manage Users</a>
            <a href="workflow_settings.php" class="btn" style="background: var(--text-muted);">Workflow Settings</a>
            <a href="create_request.php" class="btn" style="background: var(--success);">+ New Request</a>
        </div>

        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <h3 style="margin: 0;">All System Requests</h3>
                <input type="text" id="searchInput" class="form-control" style="width: 300px;" placeholder="Search by Title or Memo Number..." onkeyup="filterTable()">
            </div>
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Title</th>
                        <th>Creator</th>
                        <th>Status</th>
                        <th>Current State</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_requests as $req): ?>
                    <tr class="searchable-row" data-search="<?php echo htmlspecialchars(strtolower($req['title'] . ' ' . $req['memo_number'])); ?>">
                        <td>#<?php echo $req['id']; ?></td>
                        <td><?php echo htmlspecialchars($req['title']); ?></td>
                        <td><?php echo htmlspecialchars($req['creator_name']); ?></td>
                        <td><span class="badge badge-<?php echo strtolower($req['status']); ?>"><?php echo $req['status']; ?></span></td>
                        <td>
                            <?php if ($req['status'] === 'Pending'): ?>
                                With: <?php echo htmlspecialchars(getCurrentApprover($pdo, $req['current_sort_number'])); ?>
                            <?php else: ?>
                                Completed
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display: flex; gap: 0.5rem;">
                                <a href="approve.php?id=<?php echo $req['id']; ?>" class="btn" style="background: var(--text-muted); padding: 0.25rem 0.5rem; font-size: 0.875rem;">View</a>
                                <form method="POST" style="margin: 0;" onsubmit="return confirm('Are you sure you want to delete this request? This action cannot be undone.');">
                                    <input type="hidden" name="delete_request_id" value="<?php echo $req['id']; ?>">
                                    <button type="submit" class="btn" style="background: var(--danger); padding: 0.25rem 0.5rem; font-size: 0.875rem;">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        function filterTable() {
            let input = document.getElementById('searchInput').value.toLowerCase();
            let rows = document.querySelectorAll('.searchable-row');
            
            rows.forEach(row => {
                let searchData = row.getAttribute('data-search');
                if (searchData.includes(input)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }
    </script>
</body>
</html>
