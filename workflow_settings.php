<?php
require 'auth.php';
require 'db.php';

// Only allow admin
if ($_SESSION['user_role'] !== 'admin') {
    die("Access Denied: Admins only.");
}

$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_workflow'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Invalid CSRF token. Please try again.";
    } else {
        try {
            $pdo->beginTransaction();

            // Clear current workflow and final emails
            $pdo->exec("DELETE FROM workflow_routing");
            $pdo->exec("DELETE FROM workflow_final_emails");
            
            // Insert workflow routing
            if (isset($_POST['step_user_id'])) {
                $stmt = $pdo->prepare("INSERT INTO workflow_routing (sort_number, user_id, tier_label) VALUES (?, ?, ?)");
                $sort = 1;
                for ($i = 0; $i < count($_POST['step_user_id']); $i++) {
                    $user_id = $_POST['step_user_id'][$i];
                    $tier = $_POST['step_tier_label'][$i];
                    if (!empty($user_id) && !empty($tier)) {
                        $stmt->execute([$sort, $user_id, $tier]);
                        $sort++;
                    }
                }
            }

            // Insert final emails
            if (isset($_POST['final_email_user_id'])) {
                $stmtFinal = $pdo->prepare("INSERT INTO workflow_final_emails (user_id) VALUES (?)");
                foreach ($_POST['final_email_user_id'] as $f_user_id) {
                    if (!empty($f_user_id)) {
                        $stmtFinal->execute([$f_user_id]);
                    }
                }
            }

            $pdo->commit();
            $message = "Workflow settings updated successfully.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Failed to update workflow: " . $e->getMessage();
        }
    }
}

$users = $pdo->query("SELECT * FROM users ORDER BY name ASC")->fetchAll();
$workflow = $pdo->query("SELECT w.*, u.name as user_name FROM workflow_routing w JOIN users u ON w.user_id = u.id ORDER BY w.sort_number ASC")->fetchAll();
$final_emails = $pdo->query("SELECT f.*, u.name as user_name FROM workflow_final_emails f JOIN users u ON f.user_id = u.id")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="Images/favicon.png">
    <meta charset="UTF-8">
    <title>Workflow Settings - Disposal App</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .workflow-row { display: flex; gap: 1rem; margin-bottom: 1rem; align-items: center; background: #FAFAFA; padding: 1rem; border: 1px solid var(--border); border-radius: 8px;}
        .workflow-row select { flex: 1; }
        .remove-btn { background: var(--danger); }
        .remove-btn:hover { background: #DC2626; }
        .sort-badge { background: var(--primary); color: white; padding: 0.5rem 1rem; border-radius: 6px; font-weight: bold; font-size: 1.2rem; min-width: 30px; text-align: center; }
    </style>
</head>
<body>
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
            <h2>Approval Workflow Settings</h2>
            <div>
                <a href="users.php" class="btn" style="background: var(--primary);">Manage Users</a>
                <a href="dashboard.php" class="btn" style="background: var(--text-muted);">Back to Dashboard</a>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="card" style="margin-bottom: 2rem; background: #D1FAE5; color: #065F46; padding: 1rem;">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error)): ?>
            <div class="card" style="margin-bottom: 2rem; background: #FEE2E2; color: #DC2626; padding: 1rem;">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Workflow Editor -->
        <div class="card">
            <h3 style="margin-top: 0;">Configure Approval Sequence</h3>
            <p class="text-muted" style="margin-bottom: 2rem;">Set the exact sequence of approvers. The Sort Number determines who receives the email first. You can assign multiple people to the same Tier Label (e.g., two "Checked By" approvers in sequence).</p>
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <div id="workflow-container">
                    <?php foreach ($workflow as $index => $step): ?>
                    <div class="workflow-row">
                        <div class="sort-badge"><span class="step-num"><?php echo $index + 1; ?></span></div>
                        <div style="flex: 1;">
                            <label class="form-label" style="font-size: 0.875rem;">Approver Name</label>
                            <select name="step_user_id[]" class="form-control" required>
                                <option value="">Select User...</option>
                                <?php foreach ($users as $u): ?>
                                    <option value="<?php echo $u['id']; ?>" <?php echo $u['id'] == $step['user_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($u['name']); ?> <?php echo $u['position'] ? '('.htmlspecialchars($u['position']).')' : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="flex: 1;">
                            <label class="form-label" style="font-size: 0.875rem;">Tier Label (For PDF)</label>
                            <select name="step_tier_label[]" class="form-control" required>
                                <option value="Prepared By" <?php echo $step['tier_label'] == 'Prepared By' ? 'selected' : ''; ?>>Prepared By</option>
                                <option value="Checked By" <?php echo $step['tier_label'] == 'Checked By' ? 'selected' : ''; ?>>Checked By</option>
                                <option value="Reviewed By" <?php echo $step['tier_label'] == 'Reviewed By' ? 'selected' : ''; ?>>Reviewed By</option>
                                <option value="Noted By" <?php echo $step['tier_label'] == 'Noted By' ? 'selected' : ''; ?>>Noted By</option>
                                <option value="Approved By" <?php echo $step['tier_label'] == 'Approved By' ? 'selected' : ''; ?>>Approved By</option>
                            </select>
                        </div>
                        <button type="button" class="btn" style="background: #9CA3AF; align-self: flex-end; margin-bottom: 3px; padding: 0.5rem 0.75rem;" onclick="moveUp(this)">↑</button>
                        <button type="button" class="btn" style="background: #9CA3AF; align-self: flex-end; margin-bottom: 3px; padding: 0.5rem 0.75rem;" onclick="moveDown(this)">↓</button>
                        <button type="button" class="btn remove-btn" style="align-self: flex-end; margin-bottom: 3px;" onclick="this.parentElement.remove(); updateStepNumbers();">Remove</button>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <div style="margin-top: 2rem; padding-top: 1.5rem;">
                    <button type="button" class="btn" style="background: var(--success);" onclick="addStep()">+ Add Next Step</button>
                </div>

                <div style="margin-top: 3rem; border-top: 1px solid var(--border); padding-top: 2rem;">
                    <h3 style="margin-top: 0;">Final Email Recipients</h3>
                    <p class="text-muted" style="margin-bottom: 1.5rem;">These users will receive an email with the final approved PDF attached once the entire workflow is completed.</p>
                    
                    <div id="final-email-container">
                        <?php foreach ($final_emails as $f_email): ?>
                        <div class="workflow-row final-email-row">
                            <div style="flex: 1;">
                                <label class="form-label" style="font-size: 0.875rem;">Recipient Name</label>
                                <select name="final_email_user_id[]" class="form-control" required>
                                    <option value="">Select User...</option>
                                    <?php foreach ($users as $u): ?>
                                        <option value="<?php echo $u['id']; ?>" <?php echo $u['id'] == $f_email['user_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($u['name']); ?> <?php echo $u['position'] ? '('.htmlspecialchars($u['position']).')' : ''; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="button" class="btn remove-btn" style="align-self: flex-end; margin-bottom: 3px;" onclick="this.parentElement.remove();">Remove</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div style="margin-top: 1rem;">
                        <button type="button" class="btn" style="background: var(--success);" onclick="addFinalEmail()">+ Add Recipient</button>
                    </div>
                </div>
                
                <div style="margin-top: 3rem; display: flex; justify-content: flex-end; border-top: 1px solid var(--border); padding-top: 1.5rem;">
                    <button type="submit" name="update_workflow" class="btn" style="padding-left: 3rem; padding-right: 3rem;">Save Settings</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Template for new steps -->
    <template id="step-template">
        <div class="workflow-row">
            <div class="sort-badge"><span class="step-num"></span></div>
            <div style="flex: 1;">
                <label class="form-label" style="font-size: 0.875rem;">Approver Name</label>
                <select name="step_user_id[]" class="form-control" required>
                    <option value="">Select User...</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?php echo $u['id']; ?>">
                            <?php echo htmlspecialchars($u['name']); ?> <?php echo $u['position'] ? '('.htmlspecialchars($u['position']).')' : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="flex: 1;">
                <label class="form-label" style="font-size: 0.875rem;">Tier Label (For PDF)</label>
                <select name="step_tier_label[]" class="form-control" required>
                    <option value="Prepared By">Prepared By</option>
                    <option value="Checked By">Checked By</option>
                    <option value="Reviewed By">Reviewed By</option>
                    <option value="Noted By">Noted By</option>
                    <option value="Approved By">Approved By</option>
                </select>
            </div>
            <button type="button" class="btn" style="background: #9CA3AF; align-self: flex-end; margin-bottom: 3px; padding: 0.5rem 0.75rem;" onclick="moveUp(this)">↑</button>
            <button type="button" class="btn" style="background: #9CA3AF; align-self: flex-end; margin-bottom: 3px; padding: 0.5rem 0.75rem;" onclick="moveDown(this)">↓</button>
            <button type="button" class="btn remove-btn" style="align-self: flex-end; margin-bottom: 3px;" onclick="this.parentElement.remove(); updateStepNumbers();">Remove</button>
        </div>
    </template>

    <!-- Template for final email recipients -->
    <template id="final-email-template">
        <div class="workflow-row final-email-row">
            <div style="flex: 1;">
                <label class="form-label" style="font-size: 0.875rem;">Recipient Name</label>
                <select name="final_email_user_id[]" class="form-control" required>
                    <option value="">Select User...</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?php echo $u['id']; ?>">
                            <?php echo htmlspecialchars($u['name']); ?> <?php echo $u['position'] ? '('.htmlspecialchars($u['position']).')' : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="button" class="btn remove-btn" style="align-self: flex-end; margin-bottom: 3px;" onclick="this.parentElement.remove();">Remove</button>
        </div>
    </template>

    <script>
        function addStep() {
            const container = document.getElementById('workflow-container');
            const template = document.getElementById('step-template');
            const clone = template.content.cloneNode(true);
            container.appendChild(clone);
            updateStepNumbers();
        }

        function moveUp(btn) {
            const row = btn.closest('.workflow-row');
            const prev = row.previousElementSibling;
            if (prev) {
                row.parentNode.insertBefore(row, prev);
                updateStepNumbers();
            }
        }

        function moveDown(btn) {
            const row = btn.closest('.workflow-row');
            const next = row.nextElementSibling;
            if (next) {
                row.parentNode.insertBefore(next, row);
                updateStepNumbers();
            }
        }

        function updateStepNumbers() {
            const rows = document.querySelectorAll('.workflow-row');
            rows.forEach((row, index) => {
                row.querySelector('.step-num').textContent = index + 1;
            });
        }
        
        // Auto-initialize if empty
        if(document.querySelectorAll('.workflow-row:not(.final-email-row)').length === 0) {
            addStep();
        }

        function addFinalEmail() {
            const container = document.getElementById('final-email-container');
            const template = document.getElementById('final-email-template');
            const clone = template.content.cloneNode(true);
            container.appendChild(clone);
        }
    </script>
</body>
</html>
