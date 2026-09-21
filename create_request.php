<?php
require 'auth.php';
require 'db.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title = $_POST['title'];
    $department = $_POST['department'];
    $memo = $_POST['memo_number'];
    $request_message = $_POST['message'];
    
    // File upload
    $attachment_paths = [];
    if (isset($_FILES['attachments']) && !empty($_FILES['attachments']['name'][0])) {
        // Sanitize memo number to be safe for directory names
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
    }
    $attachment_path = !empty($attachment_paths) ? json_encode($attachment_paths) : '';

    try {
        // Insert request
        $stmt = $pdo->prepare("INSERT INTO requests (creator_user_id, title, department, memo_number, message, attachment_path, current_sort_number) VALUES (?, ?, ?, ?, ?, ?, 1)");
        $stmt->execute([$_SESSION['user_id'], $title, $department, $memo, $request_message, $attachment_path]);
        $request_id = $pdo->lastInsertId();

        // Generate magic link for the first approver (sort_number = 1)
        $stmt = $pdo->prepare("SELECT user_id, tier_label FROM workflow_routing WHERE sort_number = 1");
        $stmt->execute();
        $first_approver = $stmt->fetch();

        if ($first_approver) {
            $token = bin2hex(random_bytes(32));
            $stmt = $pdo->prepare("INSERT INTO magic_links (token, request_id, user_id) VALUES (?, ?, ?)");
            $stmt->execute([$token, $request_id, $first_approver['user_id']]);
            
            // Get user info for email
            $stmt = $pdo->prepare("SELECT email, name FROM users WHERE id = ?");
            $stmt->execute([$first_approver['user_id']]);
            $approver_user = $stmt->fetch();
            
            require_once 'emailer.php';
            $sent = sendMagicLinkEmail($approver_user['email'], $approver_user['name'], $token, $title, $first_approver['tier_label']);
            
            if($sent) {
                echo "<script>alert('Request created successfully! The first approver has been notified.'); window.location.href='dashboard.php';</script>";
                exit;
            } else {
                echo "<script>alert('Request created, but the email failed to send to the first approver.'); window.location.href='dashboard.php';</script>";
                exit;
            }
        } else {
            $error = "Request created, but no workflow is defined! Please contact admin.";
        }
    } catch(PDOException $e) {
        $error = "Error creating request: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="Images/favicon.png">
    <meta charset="UTF-8">
    <title>Create Request - Disposal App</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
            <h2>Create New Disposal Request</h2>
            <a href="dashboard.php" class="btn" style="background: var(--text-muted);">Back to Dashboard</a>
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

        <div class="card" style="max-width: 800px; margin: 0 auto;">
            <form method="POST" enctype="multipart/form-data" onsubmit="document.getElementById('submitBtn').disabled = true; document.getElementById('submitBtn').innerText = 'Submitting, please wait...'; document.getElementById('submitBtn').style.backgroundColor = '#6b7280';">
                <div class="form-group">
                    <label class="form-label">Request Title</label>
                    <input type="text" name="title" class="form-control" required placeholder="e.g. Disposal of 5 Old Hard Drives">
                </div>

                <div class="form-group" style="background: #F8FAFC; padding: 1rem; border-radius: 6px; border-left: 4px solid var(--primary); display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <label class="form-label" style="margin-bottom: 0; color: var(--text-muted); font-size: 0.875rem;">Request Date & Time</label>
                        <div style="font-size: 1.1rem; font-weight: 600; color: var(--text); margin-top: 0.25rem;">
                            <?php echo date('F d, Y - h:i A'); ?>
                        </div>
                    </div>
            
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form-group">
                        <label class="form-label">Department Name</label>
                        <select name="department" class="form-control" required>
                            <option value="">Select Department...</option>
                            <option value="Transport & Warehouse">Transport & Warehouse</option>
                            <option value="MIS DEPARTMENT">MIS DEPARTMENT</option>
                            <option value="HR">HR</option>
                            <option value="Finance">Finance</option>
                            <option value="Operations">Operations</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Memo Number</label>
                        <input type="text" name="memo_number" class="form-control" required placeholder="e.g. MEMO-2026-001">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Message / Justification</label>
                    <textarea name="message" class="form-control" rows="5" required placeholder="Describe why these items need to be disposed..."></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Attachments (PDF, Word, Excel, Images) - You can select multiple</label>
                    <input type="file" name="attachments[]" id="attachmentInput" class="form-control" accept=".pdf,.doc,.docx,.jpg,.png,.xls,.xlsx" multiple>
                    <div id="filePreviewArea" style="display: flex; flex-wrap: wrap; gap: 1rem; margin-top: 1rem;"></div>
                </div>

                <button type="submit" id="submitBtn" class="btn" style="width: 100%; padding: 1rem; font-size: 1.1rem; margin-top: 1rem;">Submit Request</button>
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
