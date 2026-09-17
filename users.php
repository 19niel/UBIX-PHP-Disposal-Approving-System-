<?php
require 'auth.php';
require 'db.php';

// Only allow admin
if ($_SESSION['user_role'] !== 'admin') {
    die("Access Denied: Admins only.");
}

$message = '';
$error = '';

// Handle actions (Create, Update, Delete)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_user'])) {
        $name = $_POST['name'];
        $username = $_POST['username'];
        $email = $_POST['email'];
        $position = $_POST['position'];
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $role = $_POST['role'];
        
        $stmt = $pdo->prepare("INSERT INTO users (name, username, email, position, password_hash, role) VALUES (?, ?, ?, ?, ?, ?)");
        try {
            $stmt->execute([$name, $username, $email, $position, $password, $role]);
            $message = "User added successfully.";
        } catch(PDOException $e) {
            $error = "Error adding user: " . $e->getMessage();
        }
    } elseif (isset($_POST['edit_user'])) {
        $id = $_POST['user_id'];
        $name = $_POST['name'];
        $username = $_POST['username'];
        $email = $_POST['email'];
        $position = $_POST['position'];
        $role = $_POST['role'];
        
        if (!empty($_POST['password'])) {
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET name=?, username=?, email=?, position=?, role=?, password_hash=? WHERE id=?");
            $params = [$name, $username, $email, $position, $role, $password, $id];
        } else {
            $stmt = $pdo->prepare("UPDATE users SET name=?, username=?, email=?, position=?, role=? WHERE id=?");
            $params = [$name, $username, $email, $position, $role, $id];
        }
        
        try {
            $stmt->execute($params);
            $message = "User updated successfully.";
        } catch(PDOException $e) {
            $error = "Error updating user: " . $e->getMessage();
        }
    } elseif (isset($_POST['delete_user'])) {
        $id = $_POST['user_id'];
        
        // Ensure not deleting self
        if ($id == $_SESSION['user_id']) {
            $error = "You cannot delete your own account.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id=?");
            try {
                $stmt->execute([$id]);
                $message = "User deleted successfully.";
            } catch(PDOException $e) {
                $error = "Error deleting user: " . $e->getMessage();
            }
        }
    }
}

$users = $pdo->query("SELECT * FROM users ORDER BY name ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Users - Disposal App</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        .table th, .table td { padding: 1rem; text-align: left; border-bottom: 1px solid var(--border); }
        .table th { background: #F9FAFB; font-weight: 600; color: var(--text-muted); }
        .action-btns { display: flex; gap: 0.5rem; }
        .btn-small { padding: 0.25rem 0.5rem; font-size: 0.75rem; }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5); }
        .modal-content { background-color: var(--card); margin: 10% auto; padding: 2rem; border-radius: 12px; width: 90%; max-width: 500px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); }
        .close { color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer; }
        .close:hover { color: #000; }
    </style>
</head>
<body>
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
            <h2>Manage Users</h2>
            <div>
                <button class="btn" style="background: var(--success);" onclick="openAddModal()">+ Add New User</button>
                <a href="dashboard.php" class="btn" style="background: var(--text-muted);">Back to Dashboard</a>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="card" style="margin-bottom: 2rem; background: #D1FAE5; color: #065F46; padding: 1rem;">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="card" style="margin-bottom: 2rem; background: #FEE2E2; color: #DC2626; padding: 1rem;">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Position</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                    <tr>
                        <td>#<?php echo $u['id']; ?></td>
                        <td><strong><?php echo htmlspecialchars($u['name']); ?></strong></td>
                        <td><?php echo htmlspecialchars($u['position'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($u['username'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($u['email']); ?></td>
                        <td>
                            <span class="badge" style="background: <?php echo $u['role']=='admin' ? 'var(--primary)' : 'var(--text-muted)'; ?>; color: white; padding: 0.25rem 0.5rem; border-radius: 999px; font-size: 0.75rem;">
                                <?php echo ucfirst($u['role']); ?>
                            </span>
                        </td>
                        <td class="action-btns">
                            <button class="btn btn-small" onclick='openEditModal(<?php echo json_encode($u); ?>)'>Edit</button>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this user?');">
                                <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                <button type="submit" name="delete_user" class="btn btn-small" style="background: var(--danger);">Delete</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Add/Edit User Modal -->
    <div id="userModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h3 id="modalTitle" style="margin-top: 0;">Add New User</h3>
            
            <form id="userForm" method="POST">
                <input type="hidden" name="user_id" id="userId">
                <input type="hidden" name="add_user" id="formAction" value="1">
                
                <div class="form-group">
                    <label class="form-label">Name</label>
                    <input type="text" name="name" id="userName" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Position</label>
                    <input type="text" name="position" id="userPosition" class="form-control" placeholder="e.g. IT Manager">
                </div>
                <div class="form-group">
                    <label class="form-label">Username</label>
                    <input type="text" name="username" id="userUsername" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" id="userEmail" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Password <small id="pwdHint" style="color: var(--text-muted); font-weight: normal;"></small></label>
                    <input type="password" name="password" id="userPassword" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Role</label>
                    <select name="role" id="userRole" class="form-control" required>
                        <option value="standard">Standard (Creator/Approver)</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                
                <button type="submit" id="submitBtn" class="btn" style="width: 100%; margin-top: 1rem;">Save User</button>
            </form>
        </div>
    </div>

    <script>
        const modal = document.getElementById('userModal');
        
        function openAddModal() {
            document.getElementById('modalTitle').innerText = 'Add New User';
            document.getElementById('formAction').name = 'add_user';
            document.getElementById('submitBtn').innerText = 'Add User';
            document.getElementById('userId').value = '';
            document.getElementById('userName').value = '';
            document.getElementById('userPosition').value = '';
            document.getElementById('userUsername').value = '';
            document.getElementById('userEmail').value = '';
            document.getElementById('userRole').value = 'standard';
            
            document.getElementById('userPassword').required = true;
            document.getElementById('pwdHint').innerText = '(Required)';
            
            modal.style.display = 'block';
        }

        function openEditModal(user) {
            document.getElementById('modalTitle').innerText = 'Edit User';
            document.getElementById('formAction').name = 'edit_user';
            document.getElementById('submitBtn').innerText = 'Update User';
            
            document.getElementById('userId').value = user.id;
            document.getElementById('userName').value = user.name;
            document.getElementById('userPosition').value = user.position || '';
            document.getElementById('userUsername').value = user.username || '';
            document.getElementById('userEmail').value = user.email;
            document.getElementById('userRole').value = user.role;
            
            document.getElementById('userPassword').required = false;
            document.getElementById('pwdHint').innerText = '(Leave blank to keep current)';
            
            modal.style.display = 'block';
        }

        function closeModal() {
            modal.style.display = 'none';
        }

        window.onclick = function(event) {
            if (event.target == modal) {
                closeModal();
            }
        }
    </script>
</body>
</html>
