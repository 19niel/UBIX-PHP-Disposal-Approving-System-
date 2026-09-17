<?php
require 'auth.php';
require 'db.php';

$user_id = $_SESSION['user_id'];
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'pending';
$title_map = [
    'pending' => 'Pending Requests',
    'approved' => 'Approved Requests',
    'rejected' => 'Rejected Requests',
    'all' => 'All Requests'
];
$page_title = isset($title_map[$status_filter]) ? $title_map[$status_filter] : 'Requests';

if ($status_filter === 'all') {
    $stmt = $pdo->prepare("SELECT * FROM requests ORDER BY created_at DESC");
    $stmt->execute();
} else {
    $stmt = $pdo->prepare("SELECT * FROM requests WHERE status = ? ORDER BY created_at DESC");
    $stmt->execute([ucfirst($status_filter)]);
}
$requests = $stmt->fetchAll();

$requests_to_approve = [];
if ($status_filter === 'pending') {
    $stmt = $pdo->prepare("
        SELECT r.* 
        FROM requests r 
        JOIN workflow_routing w ON r.current_sort_number = w.sort_number 
        WHERE r.status = 'Pending' AND w.user_id = ?
        ORDER BY r.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $requests_to_approve = $stmt->fetchAll();
}

// Function to get current approver name
function getCurrentApprover($pdo, $sort_number) {
    $stmt = $pdo->prepare("SELECT u.name, w.tier_label FROM workflow_routing w JOIN users u ON w.user_id = u.id WHERE w.sort_number = ?");
    $stmt->execute([$sort_number]);
    $res = $stmt->fetch();
    return $res ? $res['name'] . " (" . $res['tier_label'] . ")" : "Unknown";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="Images/favicon.png">
    <meta charset="UTF-8">
    <title><?php echo $page_title; ?> - Disposal App</title>
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
            <h2><?php echo $page_title; ?></h2>
            <div>
                <?php if ($status_filter === 'all'): ?>
                    <a href="create_request.php" class="btn" style="background: var(--success); margin-right: 1rem;">+ Create New Request</a>
                <?php endif; ?>
                <a href="dashboard.php" class="btn" style="background: var(--text-muted);">Back to Menu</a>
            </div>
        </div>

        <div style="margin-bottom: 1rem;">
            <input type="text" id="searchInput" class="form-control" style="width: 100%; max-width: 400px;" placeholder="Search by Title or Memo Number..." onkeyup="filterTable()">
        </div>

        <?php if ($status_filter === 'pending'): ?>
            <div class="card">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Title / Memo</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(count($requests_to_approve) == 0): ?>
                            <tr><td colspan="4" style="text-align:center; color: var(--text-muted);">No requests currently need your approval.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($requests_to_approve as $req): ?>
                        <tr class="searchable-row" data-search="<?php echo htmlspecialchars(strtolower($req['title'] . ' ' . $req['memo_number'])); ?>">
                            <td><strong><?php echo htmlspecialchars($req['title']); ?></strong><br><small><?php echo htmlspecialchars($req['memo_number']); ?></small></td>
                            <td><span class="badge" style="background: var(--primary); color: white;">Action Required</span></td>
                            <td><?php echo date('M d, Y', strtotime($req['created_at'])); ?></td>
                            <td><a href="approve.php?id=<?php echo $req['id']; ?>" class="btn" style="background: var(--primary);">Review & Approve</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="card">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Title / Memo</th>
                            <th>Status</th>
                            <th>Current State</th>
                            <th>Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(count($requests) == 0): ?>
                            <tr><td colspan="5" style="text-align:center; color: var(--text-muted);">No requests found.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($requests as $req): ?>
                        <tr class="searchable-row" data-search="<?php echo htmlspecialchars(strtolower($req['title'] . ' ' . $req['memo_number'])); ?>">
                            <td><strong><?php echo htmlspecialchars($req['title']); ?></strong><br><small><?php echo htmlspecialchars($req['memo_number']); ?></small></td>
                            <td>
                                <span class="badge badge-<?php echo strtolower($req['status']); ?>"><?php echo $req['status']; ?></span>
                            </td>
                            <td>
                                <?php if ($req['status'] === 'Pending'): ?>
                                    Waiting for: <?php echo htmlspecialchars(getCurrentApprover($pdo, $req['current_sort_number'])); ?>
                                <?php else: ?>
                                    Completed
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($req['created_at'])); ?></td>
                            <td><a href="approve.php?id=<?php echo $req['id']; ?>" class="btn" style="background: var(--text-muted);">View</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
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
