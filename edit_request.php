<?php
require 'auth.php';
require 'db.php';

if (!isset($_GET['id'])) {
    die("Request ID not provided");
}

$request_id = $_GET['id'];
$stmt = $pdo->prepare("SELECT * FROM requests WHERE id = ?");
$stmt->execute([$request_id]);
$request = $stmt->fetch();

if (!$request || $request['status'] !== 'Rejected') {
    die("Only rejected requests can be edited");
}

$authorized = $is_admin;
if (!$authorized && isset($_SESSION['user_id'])) {
    if ($_SESSION['user_id'] == $request['creator_user_id']) {
        $authorized = true;
    } else {
        $stmt_auth = $pdo->prepare("SELECT 1 FROM workflow_final_emails WHERE user_id = ?");
        $stmt_auth->execute([$_SESSION['user_id']]);
        if ($stmt_auth->fetchColumn()) $authorized = true;
    }
}

if (!$authorized) {
    die("Access Denied. You must be an admin, the creator, or a final email recipient to edit this request.");
}



$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title = $_POST['title'];
    $department = $_POST['department'];
    $memo = $_POST['memo_number'];
    $request_message = $_POST['message'];
    
    // File upload
    $attachment_path = $request['attachment_path'];
    if (isset($_FILES['attachments']) && !empty($_FILES['attachments']['name'][0])) {
        $attachment_paths = [];
        $safe_memo = preg_replace('/[^A-Za-z0-9_\-]/', '_', trim($memo));
        $uploadDir = 'uploads/' . $safe_memo . '/';
        
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        
        foreach ($_FILES['attachments']['tmp_name'] as $key => $tmp_name) {
            if ($_FILES['attachments']['error'][$key] === UPLOAD_ERR_OK) {
                $filename = time() . '_' . basename($_FILES['attachments']['name'][$key]);
                $targetFile = $uploadDir . $filename;
                if (move_uploaded_file($tmp_name, $targetFile)) {
                    $attachment_paths[] = $targetFile;
                }
            }
        }
        if (!empty($attachment_paths)) {
            $attachment_path = json_encode($attachment_paths);
        }
    }

    try {
        $pdo->beginTransaction();

        // 1. Update the request
        $stmt = $pdo->prepare("UPDATE requests SET title = ?, department = ?, memo_number = ?, message = ?, attachment_path = ?, status = 'Pending' WHERE id = ?");
        $stmt->execute([$title, $department, $memo, $request_message, $attachment_path, $request_id]);

        // 2. Delete the Rejected row in request_approvals for the current step
        $stmt = $pdo->prepare("DELETE FROM request_approvals WHERE request_id = ? AND sort_number = ? AND status = 'Rejected'");
        $stmt->execute([$request_id, $request['current_sort_number']]);

        // 3. Generate magic link for the current approver
        $stmt = $pdo->prepare("SELECT w.user_id, w.tier_label, u.email, u.name FROM workflow_routing w JOIN users u ON w.user_id = u.id WHERE w.sort_number = ?");
        $stmt->execute([$request['current_sort_number']]);
        $current_approver = $stmt->fetch();

        if ($current_approver) {
            $token = bin2hex(random_bytes(32));
            $stmt = $pdo->prepare("INSERT INTO magic_links (token, request_id, user_id) VALUES (?, ?, ?)");
            $stmt->execute([$token, $request_id, $current_approver['user_id']]);
            
            require_once 'emailer.php';
            $sent = sendMagicLinkEmail($current_approver['email'], $current_approver['name'], $token, $title, $current_approver['tier_label']);
            
            if($sent) {
                $pdo->commit();
                echo "<script>alert('Request updated successfully! The approver has been notified.'); window.location.href='dashboard.php';</script>";
                exit;
            } else {
                $pdo->commit();
                echo "<script>alert('Request updated, but the email failed to send to the approver.'); window.location.href='dashboard.php';</script>";
                exit;
            }
        } else {
            $pdo->commit();
            $error = "Request updated, but could not find the current approver to notify.";
        }
    } catch(PDOException $e) {
        $pdo->rollBack();
        $error = "Error updating request: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="Images/favicon.png">
    <meta charset="UTF-8">
    <title>Edit Request - Disposal App</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
            <h2>Edit Rejected Request #<?php echo $request['id']; ?></h2>
            <a href="dashboard.php" class="btn" style="background: var(--text-muted);">Back to Dashboard</a>
        </div>
        
        <?php if ($error): ?>
            <div class="card" style="margin-bottom: 2rem; background: #FEE2E2; color: #DC2626; padding: 1rem;">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <div class="card" style="max-width: 800px; margin: 0 auto;">
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label class="form-label">Request Title</label>
                    <input type="text" name="title" class="form-control" required value="<?php echo htmlspecialchars($request['title']); ?>">
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form-group">
                        <label class="form-label">Department Name</label>
                        <select name="department" class="form-control" required>
                            <option value="">Select Department...</option>
                            <option value="Transport & Warehouse" <?php echo ($request['department'] == 'Transport & Warehouse') ? 'selected' : ''; ?>>Transport & Warehouse</option>
                            <option value="MIS DEPARTMENT" <?php echo ($request['department'] == 'MIS DEPARTMENT') ? 'selected' : ''; ?>>MIS DEPARTMENT</option>
                            <option value="HR" <?php echo ($request['department'] == 'HR') ? 'selected' : ''; ?>>HR</option>
                            <option value="Finance" <?php echo ($request['department'] == 'Finance') ? 'selected' : ''; ?>>Finance</option>
                            <option value="Operations" <?php echo ($request['department'] == 'Operations') ? 'selected' : ''; ?>>Operations</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Memo Number</label>
                        <input type="text" name="memo_number" class="form-control" required value="<?php echo htmlspecialchars($request['memo_number']); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Message / Justification</label>
                    <textarea name="message" class="form-control" rows="5" required><?php echo htmlspecialchars($request['message']); ?></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Attachments (Upload new files to overwrite existing) - Optional</label>
                    <input type="file" name="attachments[]" id="attachmentInput" class="form-control" accept=".pdf,.doc,.docx,.jpg,.png,.xls,.xlsx" multiple>
                    <div id="filePreviewArea" style="display: flex; flex-wrap: wrap; gap: 1rem; margin-top: 1rem;"></div>
                    
                    <?php if ($request['attachment_path']): ?>
                        <div style="margin-top: 1rem; font-size: 0.875rem; color: var(--text-muted);">
                            <strong>Current Attachments:</strong><br>
                            <?php 
                            $attachments = json_decode($request['attachment_path'], true);
                            if (!is_array($attachments)) {
                                $attachments = [$request['attachment_path']];
                            }
                            foreach ($attachments as $att) {
                                echo htmlspecialchars(basename($att)) . "<br>";
                            }
                            ?>
                        </div>
                    <?php endif; ?>
                </div>

                <button type="submit" class="btn" style="width: 100%; padding: 1rem; font-size: 1.1rem; margin-top: 1rem;">Resubmit Request</button>
            </form>
        </div>
    </div>
    <script>
        document.getElementById('attachmentInput').addEventListener('change', function(event) {
            const previewArea = document.getElementById('filePreviewArea');
            previewArea.innerHTML = '';
            
            Array.from(event.target.files).forEach(file => {
                const fileBox = document.createElement('div');
                fileBox.style.cssText = 'border: 1px solid var(--border); border-radius: 6px; padding: 0.5rem; width: 120px; display: flex; flex-direction: column; align-items: center; background: #f9fafb;';
                
                if (file.type.startsWith('image/')) {
                    const img = document.createElement('img');
                    img.style.cssText = 'width: 100%; height: 80px; object-fit: cover; border-radius: 4px; margin-bottom: 0.5rem;';
                    img.src = URL.createObjectURL(file);
                    fileBox.appendChild(img);
                } else {
                    const icon = document.createElement('div');
                    icon.style.cssText = 'width: 100%; height: 80px; display: flex; align-items: center; justify-content: center; font-size: 2rem; background: #e5e7eb; border-radius: 4px; margin-bottom: 0.5rem;';
                    icon.innerText = '📄';
                    fileBox.appendChild(icon);
                }
                
                const name = document.createElement('div');
                name.style.cssText = 'font-size: 0.75rem; text-align: center; word-break: break-all; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;';
                name.innerText = file.name;
                fileBox.appendChild(name);
                
                previewArea.appendChild(fileBox);
            });
        });
    </script>
</body>
</html>
