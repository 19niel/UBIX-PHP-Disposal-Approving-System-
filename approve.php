<?php
session_start();
require 'db.php';
require_once 'emailer.php';

$error = '';
$success = '';
$request = null;
$approver = null;
$magic_link = null;
$trigger_final_email = false;

// Validate Token or ID
if (isset($_GET['token'])) {
    $token = $_GET['token'];
    
    // Find token regardless of is_used status so they can still view the document
    $stmt = $pdo->prepare("SELECT m.*, u.name, u.email FROM magic_links m JOIN users u ON m.user_id = u.id WHERE m.token = ?");
    $stmt->execute([$token]);
    $magic_link = $stmt->fetch();
    
    if (!$magic_link) {
        $error = "This approval link is invalid.";
    } else {
        // Log them in via session for convenience
        $_SESSION['user_id'] = $magic_link['user_id'];
        $_SESSION['user_name'] = $magic_link['name'];
        if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
            $_SESSION['user_role'] = 'standard';
        }
        
        $request_id = $magic_link['request_id'];
        $user_id = $magic_link['user_id'];
        
        $stmt = $pdo->prepare("SELECT r.*, u.name as creator_name FROM requests r JOIN users u ON r.creator_user_id = u.id WHERE r.id = ?");
        $stmt->execute([$request_id]);
        $request = $stmt->fetch();
        
        if ($magic_link['is_used'] == 1) {
            $error = "This approval link has already been used (the request was approved or bypassed). You can still view the details below.";
        } else {
            $stmt = $pdo->prepare("SELECT * FROM workflow_routing WHERE sort_number = ? AND user_id = ?");
            $stmt->execute([$request['current_sort_number'], $user_id]);
            $approver = $stmt->fetch();
            
            if (!$approver) {
                $error = "It is not currently your turn to approve this request.";
            }
        }
    }
} elseif (isset($_GET['id'])) {
    $request_id = $_GET['id'];
    $stmt = $pdo->prepare("SELECT r.*, u.name as creator_name FROM requests r JOIN users u ON r.creator_user_id = u.id WHERE r.id = ?");
    $stmt->execute([$request_id]);
    $request = $stmt->fetch();
    
    if (!$request) {
        $error = "Request not found.";
    } else {
        // If logged in, check if they are the current approver
        if (isset($_SESSION['user_id'])) {
            $user_id = $_SESSION['user_id'];
            $stmt = $pdo->prepare("SELECT * FROM workflow_routing WHERE sort_number = ? AND user_id = ?");
            $stmt->execute([$request['current_sort_number'], $user_id]);
            $approver = $stmt->fetch();
        }
    }
} else {
    $error = "No request specified.";
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $signature = isset($_POST['signature_base64']) ? $_POST['signature_base64'] : '';
    $remarks = isset($_POST['remarks']) ? $_POST['remarks'] : null;
    $is_admin_bypass = ($action === 'admin_skip' && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin');
    
    if ($approver || $is_admin_bypass) {
        
        try {
            $pdo->beginTransaction();
            
            if ($is_admin_bypass) {
                $stmt = $pdo->prepare("SELECT * FROM workflow_routing WHERE sort_number = ?");
                $stmt->execute([$request['current_sort_number']]);
                $target_approver = $stmt->fetch();
                if (!$target_approver) {
                    throw new Exception("Could not find the current pending approver to skip.");
                }
                $target_user_id = $target_approver['user_id'];
                $target_tier_label = $target_approver['tier_label'];
                $admin_bypass_id = $_SESSION['user_id'];
                $signature = ''; // Blank signature
            } else {
                $target_user_id = $user_id;
                $target_tier_label = $approver['tier_label'];
                $admin_bypass_id = null;
            }
            
            // 1. Invalidate any existing Magic Links for this request step
            $stmt = $pdo->prepare("UPDATE magic_links SET is_used = 1 WHERE request_id = ? AND user_id = ?");
            $stmt->execute([$request['id'], $target_user_id]);
            
            if ($action === 'reject') {
                $stmt = $pdo->prepare("INSERT INTO request_approvals (request_id, sort_number, user_id, tier_label, signature_base64, admin_bypass_id, status, remarks) VALUES (?, ?, ?, ?, ?, ?, 'Rejected', ?)");
                $stmt->execute([$request['id'], $request['current_sort_number'], $target_user_id, $target_tier_label, $signature, $admin_bypass_id, $remarks]);
                
                $stmt = $pdo->prepare("UPDATE requests SET status = 'Rejected' WHERE id = ?");
                $stmt->execute([$request['id']]);
                
                $rejecter_name = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'An Approver';
                sendRejectionEmail($pdo, $request['id'], $rejecter_name, $remarks);
                
                $pdo->commit();
                echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script><style>body { font-family: sans-serif; }</style><script>document.addEventListener('DOMContentLoaded', function() { Swal.fire({ title: 'Success!', text: 'You have successfully rejected the request.', icon: 'success', confirmButtonColor: '#10b981' }).then(function() { window.location.href='dashboard.php'; }); });</script>";
                exit;
            } else {
                // 2. Save Signature to History
                $stmt = $pdo->prepare("INSERT INTO request_approvals (request_id, sort_number, user_id, tier_label, signature_base64, admin_bypass_id) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$request['id'], $request['current_sort_number'], $target_user_id, $target_tier_label, $signature, $admin_bypass_id]);
                
                // 3. Move to next step
                $next_sort = $request['current_sort_number'] + 1;
                
                $stmt = $pdo->prepare("SELECT w.*, u.email, u.name FROM workflow_routing w JOIN users u ON w.user_id = u.id WHERE w.sort_number = ?");
                $stmt->execute([$next_sort]);
                $next_approver = $stmt->fetch();
                
                if ($next_approver) {
                    $stmt = $pdo->prepare("UPDATE requests SET current_sort_number = ? WHERE id = ?");
                    $stmt->execute([$next_sort, $request['id']]);
                    
                    $new_token = bin2hex(random_bytes(32));
                    $stmt = $pdo->prepare("INSERT INTO magic_links (token, request_id, user_id) VALUES (?, ?, ?)");
                    $stmt->execute([$new_token, $request['id'], $next_approver['user_id']]);
                    
                    sendMagicLinkEmail($next_approver['email'], $next_approver['name'], $new_token, $request['title'], $next_approver['tier_label']);
                    
                    if ($is_admin_bypass) {
                        $msg = "You have successfully skipped the step. The next approver ({$next_approver['name']}) has been notified.";
                    } else {
                        $msg = "You have successfully approved the request. The next approver ({$next_approver['name']}) has been notified.";
                    }
                    $pdo->commit();
                    echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script><style>body { font-family: sans-serif; }</style><script>document.addEventListener('DOMContentLoaded', function() { Swal.fire({ title: 'Success!', text: ".json_encode($msg).", icon: 'success', confirmButtonColor: '#10b981' }).then(function() { window.location.href='dashboard.php'; }); });</script>";
                    exit;
                } else {
                    $stmt = $pdo->prepare("UPDATE requests SET status = 'Approved' WHERE id = ?");
                    $stmt->execute([$request['id']]);
                    
                    sendFinalApprovalEmail($pdo, $request['id']);
                    
                    if ($is_admin_bypass) {
                        $msg = "You have successfully skipped the step. This was the final step, and the request is now Fully Approved!";
                    } else {
                        $msg = "You have successfully approved the request. This was the final step, and the request is now Fully Approved!";
                    }
                    $pdo->commit();
                    echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script><style>body { font-family: sans-serif; }</style><script>document.addEventListener('DOMContentLoaded', function() { Swal.fire({ title: 'Success!', text: ".json_encode($msg).", icon: 'success', confirmButtonColor: '#10b981' }).then(function() { window.location.href='dashboard.php'; }); });</script>";
                    exit;
                }
            }
            
        } catch(Exception $e) {
            $pdo->rollBack();
            $error = "Failed to process: " . $e->getMessage();
        }
    }
}

// Fetch Full Workflow WITH signatures
if ($request) {
    $stmt = $pdo->prepare("
        SELECT w.*, u.name as approver_name, a.signature_base64, a.action_date, a.admin_bypass_id, admin_u.name as admin_name, a.status, a.remarks
        FROM workflow_routing w 
        JOIN users u ON w.user_id = u.id 
        LEFT JOIN request_approvals a ON w.user_id = a.user_id AND w.sort_number = a.sort_number AND a.request_id = ?
        LEFT JOIN users admin_u ON a.admin_bypass_id = admin_u.id
        ORDER BY w.sort_number ASC
    ");
    $stmt->execute([$request['id']]);
    $full_workflow = $stmt->fetchAll();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="Images/favicon.png">
    <meta charset="UTF-8">
    <title>Review Request - Disposal App</title>
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .signature-wrapper {
            border: 2px dashed var(--border);
            border-radius: 8px;
            background: #fff;
            position: relative;
            margin-bottom: 1rem;
        }
        canvas {
            width: 100%;
            height: 250px;
            border-radius: 8px;
            cursor: crosshair;
        }
        .meta-grid { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 1rem; margin-bottom: 2rem;}
        @media(max-width: 768px) { .meta-grid { grid-template-columns: 1fr 1fr; } }
        @media print {
            .no-print { display: none !important; }
            body { background: white; margin: 0; padding: 0; }
            .container { box-shadow: none; max-width: 100%; padding: 0; }
            .card { box-shadow: none; border: 1px solid #ccc; page-break-inside: avoid; margin-bottom: 1rem; }
        }
    </style>
</head>
<body>
    <div class="container" style="max-width: 900px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
            <h2>Disposal Request Details</h2>
            <div style="display: flex; gap: 1rem;" class="no-print">
                <?php 
                $can_edit = false;
                if ($request && $request['status'] === 'Rejected' && isset($_SESSION['user_id'])) {
                    if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
                        $can_edit = true;
                    } else if ($_SESSION['user_id'] == $request['creator_user_id']) {
                        $can_edit = true;
                    } else {
                        $stmt_edit = $pdo->prepare("SELECT 1 FROM workflow_final_emails WHERE user_id = ?");
                        $stmt_edit->execute([$_SESSION['user_id']]);
                        if ($stmt_edit->fetchColumn()) $can_edit = true;
                    }
                }
                ?>
                <?php if ($can_edit): ?>
                    <a href="edit_request.php?id=<?php echo $request['id']; ?>" class="btn" style="background: var(--primary);">Edit Request</a>
                <?php endif; ?>

                <?php if ($request && $request['status'] === 'Approved'): ?>
                    <a href="print_request.php?id=<?php echo $request['id']; ?>" target="_blank" class="btn" style="background: var(--success);">Print Document</a>
                <?php endif; ?>
                <?php if (isset($_SESSION['user_id'])): ?>
                    <a href="dashboard.php" class="btn" style="background: var(--text-muted);">Back to Dashboard</a>
                <?php else: ?>
                    <a href="login.php" class="btn" style="background: var(--primary);">Login</a>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if ($error): ?>
            <div class="card" style="margin-bottom: 2rem; background: #FEE2E2; color: #DC2626; padding: 1rem;">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="card" style="margin-bottom: 2rem; background: #D1FAE5; color: #065F46; padding: 1rem;">
                <strong>Success!</strong> <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <?php if ($request): ?>
        
        <!-- Request Details -->
        <div class="card" style="margin-bottom: 2rem;">
            <h3 style="margin-top: 0; border-bottom: 1px solid var(--border); padding-bottom: 1rem;"><?php echo htmlspecialchars($request['title']); ?></h3>
            
            <div class="meta-grid">
                <div>
                    <span class="text-muted" style="display: block; font-size: 0.875rem;">Memo Number</span>
                    <strong><?php echo htmlspecialchars($request['memo_number']); ?></strong>
                </div>
                <div>
                    <span class="text-muted" style="display: block; font-size: 0.875rem;">Department</span>
                    <strong><?php echo htmlspecialchars($request['department']); ?></strong>
                </div>
                <div>
                    <span class="text-muted" style="display: block; font-size: 0.875rem;">Status</span>
                    <strong style="color: <?php echo $request['status']=='Approved' ? 'var(--success)' : ($request['status']=='Rejected' ? 'var(--danger)' : 'var(--primary)'); ?>"><?php echo $request['status']; ?></strong>
                </div>
                <div>
                    <span class="text-muted" style="display: block; font-size: 0.875rem;">Created By</span>
                    <strong><?php echo htmlspecialchars($request['creator_name']); ?></strong>
                </div>
            </div>

            <div style="margin-top: 1.5rem;">
                <span class="text-muted" style="display: block; font-size: 0.875rem; margin-bottom: 0.5rem;">Justification / Message</span>
                <div style="background: #f9fafb; padding: 1rem; border-radius: 6px; white-space: pre-wrap; border-left: 4px solid var(--primary); font-size: 1.1rem; line-height: 1.6;"><?php echo htmlspecialchars($request['message']); ?></div>
            </div>

            <?php if ($request['attachment_path']): 
                $attachments = json_decode($request['attachment_path'], true);
                if (!is_array($attachments)) {
                    $attachments = [$request['attachment_path']];
                }
            ?>
                <div style="margin-top: 1.5rem;">
                    <span class="text-muted" style="display: block; font-size: 0.875rem; margin-bottom: 0.5rem;">Attachments</span>
                    
                    <div style="display: flex; flex-direction: column; gap: 1rem;">
                        <?php foreach ($attachments as $att_path): 
                            $ext = strtolower(pathinfo($att_path, PATHINFO_EXTENSION));
                            $is_image = in_array($ext, ['jpg', 'jpeg', 'png', 'gif']);
                            $is_pdf = ($ext === 'pdf');
                        ?>
                        <div style="display: flex; gap: 1.5rem; align-items: flex-start; background: #f9fafb; padding: 1rem; border-radius: 6px; border: 1px solid var(--border);">
                            
                            <!-- Preview Box -->
                            <div style="flex: 1; max-width: 400px; max-height: 400px; overflow: hidden; border: 1px solid #e5e7eb; border-radius: 4px; background: white; display: flex; align-items: center; justify-content: center;">
                                <?php if ($is_image): ?>
                                    <img src="<?php echo htmlspecialchars($att_path); ?>" alt="Attachment Preview" style="max-width: 100%; max-height: 400px; object-fit: contain;">
                                <?php elseif ($is_pdf): ?>
                                    <iframe src="<?php echo htmlspecialchars($att_path); ?>" style="width: 100%; height: 400px; border: none;"></iframe>
                                <?php else: ?>
                                    <div style="padding: 2rem; color: var(--text-muted); text-align: center;">
                                        <div style="font-size: 3rem; margin-bottom: 0.5rem;">📄</div>
                                        <div>Preview not available for this file type.</div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Action Button -->
                            <div>
                                <a href="<?php echo htmlspecialchars($att_path); ?>" target="_blank" class="btn" style="background: var(--primary); display: inline-flex; align-items: center; gap: 0.5rem;">
                                    <span>View Full File</span>
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($approver && !$success): ?>
                <hr style="border: 0; border-top: 1px solid var(--border); margin: 2rem 0;">
                
                <div id="approve-action-block" style="text-align: center;">
                    <div style="display: flex; justify-content: center; gap: 1rem; flex-wrap: wrap;">
                        <button type="button" class="btn" style="background: var(--success); font-size: 1.5rem; padding: 1rem 3rem; max-width: 400px; box-shadow: 0 4px 6px rgba(16, 185, 129, 0.2);" onclick="showSignaturePad()">Approve Request</button>
                        <button type="button" class="btn" style="background: var(--danger); font-size: 1.5rem; padding: 1rem 3rem; max-width: 400px; box-shadow: 0 4px 6px rgba(220, 38, 38, 0.2);" onclick="rejectRequest()">Reject Request</button>
                    </div>
                    <p class="text-muted" style="margin-top: 1rem; font-size: 0.875rem;">Clicking Approve will prompt you for your digital signature.</p>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin' && $request['status'] === 'Pending' && !$success && !$approver): ?>
                <hr style="border: 0; border-top: 1px solid var(--border); margin: 2rem 0;">
                <div id="admin-override-block" style="text-align: center; background: #fff5f5; padding: 1.5rem; border: 1px solid #fed7d7; border-radius: 8px;">
                    <h3 style="color: var(--danger); margin-top: 0;">Admin Override</h3>
                    <p class="text-muted" style="margin-bottom: 1.5rem;">The designated person is currently pending approval. As an admin, you can skip this step.</p>
                    <button type="button" class="btn" style="background: var(--danger); font-size: 1.2rem; padding: 0.75rem 2rem;" onclick="adminSkip()">Admin Skip</button>
                </div>
            <?php endif; ?>

            <form id="actionForm" method="POST" style="display: none;">
                <input type="hidden" name="action" id="actionInput">
                <input type="hidden" name="remarks" id="remarksInput">
            </form>

            <!-- Universal Signature Pad Container -->
            <div id="signature-container" style="display: none; border: 2px solid var(--success); padding: 2rem; border-radius: 8px; background: #f0fdf4; margin-top: 1rem;">
                <h3 style="margin-top: 0; color: var(--success); text-align: center;">Draw Your Signature</h3>
                <p style="font-size: 0.875rem; margin-bottom: 1.5rem; text-align: center;" id="signature-role-text">
                    <?php if ($approver) echo "Role: <strong>" . htmlspecialchars($approver['tier_label']) . "</strong>"; ?>
                </p>
                
                <form method="POST" id="signatureForm">
                    <input type="hidden" name="action" value="approve">
                    <div class="signature-wrapper">
                        <canvas id="signaturePad"></canvas>
                    </div>
                    <div style="display: flex; justify-content: space-between; gap: 1rem;">
                        <button type="button" class="btn" style="background: var(--text-muted);" onclick="signaturePad.clear()">Clear Signature</button>
                        <button type="button" class="btn" style="background: var(--success); font-size: 1.2rem; padding: 0.75rem 3rem;" onclick="submitSignature()">Save Signature</button>
                    </div>
                    <input type="hidden" name="signature_base64" id="signatureData" required>
                </form>
            </div>
        </div>

        <!-- Workflow Routing -->
        <div class="card" style="margin-bottom: 2rem;">
            <h3 style="margin-top: 0;">Approval Routing & Signatures</h3>
            <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                <?php foreach ($full_workflow as $step): ?>
                    <div style="padding: 1.5rem; border: 1px solid var(--border); border-radius: 6px; display: flex; justify-content: space-between; align-items: center; <?php echo ($step['sort_number'] == $request['current_sort_number'] && $request['status'] == 'Pending') ? 'border-color: var(--primary); background: #eff6ff; box-shadow: 0 4px 6px -1px rgba(37,99,235,0.1);' : ''; ?>">
                        <div>
                            <div style="color: var(--text-muted); font-size: 0.875rem; text-transform: uppercase; font-weight: 600; margin-bottom: 0.25rem;">
                                <?php echo htmlspecialchars($step['tier_label']); ?>
                            </div>
                            <strong style="font-size: 1.1rem;"><?php echo htmlspecialchars($step['approver_name']); ?></strong>
                        </div>
                        
                        <div style="display: flex; align-items: center; gap: 1.5rem; text-align: right;">
                            <?php if ($step['status'] === 'Rejected'): ?>
                                <div style="text-align: right; font-size: 0.75rem; color: var(--text-muted);">
                                    Rejected On:<br>
                                    <strong><?php echo date('M d, Y h:i A', strtotime($step['action_date'])); ?></strong>
                                </div>
                                <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 4px;">
                                    <span style="color: var(--danger); font-weight: bold;">Rejected</span>
                                    <small style="color: var(--text-muted); max-width: 200px; text-align: right;"><?php echo htmlspecialchars($step['remarks']); ?></small>
                                </div>
                            <?php elseif ($step['signature_base64']): ?>
                                <div style="text-align: right; font-size: 0.75rem; color: var(--text-muted);">
                                    Approved On:<br>
                                    <strong><?php echo date('M d, Y h:i A', strtotime($step['action_date'])); ?></strong>
                                </div>
                                <img src="<?php echo $step['signature_base64']; ?>" alt="Signature" style="height: 50px; background: white; border: 1px solid var(--border); border-radius: 4px; padding: 2px;">
                            <?php elseif ($step['admin_bypass_id']): ?>
                                <div style="text-align: right; font-size: 0.75rem; color: var(--text-muted);">
                                    Approved On:<br>
                                    <strong><?php echo date('M d, Y h:i A', strtotime($step['action_date'])); ?></strong>
                                </div>
                                <div style="width: 54px; height: 50px;"></div>
                            <?php endif; ?>

                            <?php if ($step['sort_number'] == $request['current_sort_number'] && $request['status'] == 'Pending'): ?>
                                <span class="badge" style="background: var(--primary); color: white; padding: 0.5rem 1rem;">Next Approver</span>
                            <?php elseif ($step['sort_number'] < $request['current_sort_number'] || $request['status'] == 'Approved' || $step['status'] === 'Rejected'): ?>
                                <span class="badge" style="background: <?php echo ($step['status'] === 'Rejected') ? 'var(--danger)' : 'var(--success)'; ?>; color: white; padding: 0.5rem 1rem;"><?php echo ($step['status'] === 'Rejected') ? 'Rejected' : 'Approved'; ?></span>
                            <?php else: ?>
                                <span class="badge" style="background: var(--text-muted); color: white; padding: 0.5rem 1rem;">Waiting</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php endif; ?>
    </div>

    <script>
        function rejectRequest() {
            Swal.fire({
                title: 'Reject Request',
                text: 'Please enter a remark or reason for rejecting this request:',
                input: 'textarea',
                inputPlaceholder: 'Enter reason here...',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                confirmButtonText: 'Reject',
                cancelButtonText: 'Cancel',
                inputValidator: (value) => {
                    if (!value || value.trim() === '') {
                        return 'Remarks are required to reject a request!';
                    }
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('actionInput').value = "reject";
                    document.getElementById('remarksInput').value = result.value;
                    document.getElementById('actionForm').submit();
                }
            });
        }
        
        function adminSkip() {
            Swal.fire({
                title: 'Admin Skip',
                text: 'Enter Admin Password to Skip this step:',
                input: 'password',
                inputPlaceholder: 'Password',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                confirmButtonText: 'Skip',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    if (result.value === 'adminpass') {
                        document.getElementById('actionInput').value = "admin_skip";
                        document.getElementById('actionForm').submit();
                    } else {
                        Swal.fire('Error', 'Incorrect Password.', 'error');
                    }
                }
            });
        }
    </script>

    <?php if (($approver || (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin')) && !$success): ?>
    <script>
        const canvas = document.getElementById('signaturePad');
        let signaturePad;
        
        function initSignaturePad() {
            if(signaturePad) return;
            const ratio =  Math.max(window.devicePixelRatio || 1, 1);
            canvas.width = canvas.offsetWidth * ratio;
            canvas.height = canvas.offsetHeight * ratio;
            canvas.getContext("2d").scale(ratio, ratio);
            
            signaturePad = new SignaturePad(canvas, {
                penColor: "rgb(0, 0, 0)",
                backgroundColor: "rgb(255,255,255)"
            });
        }
        
        window.addEventListener('resize', () => {
            if(document.getElementById('signature-container') && document.getElementById('signature-container').style.display === 'block') {
                const data = signaturePad ? signaturePad.toData() : null;
                initSignaturePad();
                if(data && signaturePad) signaturePad.fromData(data);
            }
        });

        function showSignaturePad() {
            if (document.getElementById('approve-action-block')) {
                document.getElementById('approve-action-block').style.display = 'none';
            }
            document.getElementById('signature-container').style.display = 'block';
            initSignaturePad();
            document.getElementById('signature-container').scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        
        function submitSignature() {
            if (!signaturePad || signaturePad.isEmpty()) {
                alert("Please provide a signature first.");
                return;
            }
            
            const dataURL = signaturePad.toDataURL();
            document.getElementById('signatureData').value = dataURL;
            document.getElementById('signatureForm').submit();
        }
    </script>
    <?php endif; ?>
</body>
</html>
