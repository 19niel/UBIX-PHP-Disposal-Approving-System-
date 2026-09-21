<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'db.php';

// Allow internal bypassing of session for emailer
// Removed login check to allow anyone with the link to print
// if (!isset($is_internal_include) || !$is_internal_include) {
//     if (!isset($_SESSION['user_id'])) {
//         die("Unauthorized access.");
//     }
// }

if (!isset($_GET['id'])) {
    die("No request ID specified.");
}

$request_id = $_GET['id'];

// Fetch Request
$stmt = $pdo->prepare("SELECT r.*, u.name as creator_name, u.position as creator_position FROM requests r JOIN users u ON r.creator_user_id = u.id WHERE r.id = ?");
$stmt->execute([$request_id]);
$request = $stmt->fetch();

if (!$request) {
    die("Request not found.");
}

if ($request['status'] !== 'Approved') {
    die("Only approved requests can be printed.");
}

// Fetch Approvals
$stmt = $pdo->prepare("
    SELECT a.*, u.name as approver_name, u.position as approver_position, admin_u.name as admin_name
    FROM request_approvals a
    JOIN users u ON a.user_id = u.id
    LEFT JOIN users admin_u ON a.admin_bypass_id = admin_u.id
    WHERE a.request_id = ?
    ORDER BY a.sort_number ASC
");
$stmt->execute([$request_id]);
$approvals = $stmt->fetchAll();

// Group approvals by tier_label
$grouped_approvals = [];
foreach ($approvals as $approval) {
    $tier = $approval['tier_label'];
    if (!isset($grouped_approvals[$tier])) {
        $grouped_approvals[$tier] = [];
    }
    $grouped_approvals[$tier][] = $approval;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="Images/favicon.png">
    <meta charset="UTF-8">
    <title>Print Request - <?php echo htmlspecialchars($request['memo_number']); ?></title>
    <style>
        body { 
            font-family: 'Arial', sans-serif; 
            margin: 0; 
            padding: 40px; 
            color: #000; 
            background: #fff;
            font-size: 14px;
        }
        .header-logo { text-align: right; margin-bottom: 30px; }
        .header-logo img { height: 60px; }
        .memo-header { margin-bottom: 40px; }
        .memo-header table { width: 100%; border-collapse: collapse; }
        .memo-header td { padding: 8px 0; vertical-align: top; }
        .memo-header td:first-child { width: 100px; font-weight: bold; }
        .memo-body { 
            margin-bottom: 40px; 
            text-align: justify; 
            white-space: pre-wrap; 
            line-height: 1.6; 
        }
        
        .signatures-wrapper { margin-top: 50px; }
        .signature-section-block { 
            margin-bottom: 40px; 
            page-break-inside: avoid;
            break-inside: avoid;
        }
        .section-title { font-weight: bold; margin-bottom: 15px; font-size: 15px; text-decoration: underline; }
        .signature-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 30px;
        }
        .signature-person { 
            page-break-inside: avoid; 
        }
        .signature-img-container { 
            height: 50px; 
            width: 100%;
            margin-bottom: 5px; 
            position: relative; 
        }
        .signature-img-container img { 
            max-height: 100%; 
            max-width: 100%; 
            position: absolute; 
            bottom: 0; 
            left: 0; 
        }
        .signature-name { 
            font-weight: bold; 
            font-size: 14px; 
            text-transform: uppercase;
        }
        .signature-title { 
            font-size: 12px; 
            color: #333;
        }
        .bypassed-text { 
            color: red; 
            font-size: 11px; 
            position: absolute; 
            bottom: 5px; 
            left: 0; 
            font-weight: bold; 
        }

        /* Print Specific Styles */
        @page { margin: 0; }
        @media print {
            body { padding: 40px; }
            .no-print { display: none !important; }
        }
        
        /* Controls */
        .controls {
            background: #f3f4f6;
            padding: 15px;
            text-align: center;
            margin-bottom: 30px;
            border-radius: 8px;
        }
        .btn {
            background: #2563eb;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            margin: 0 5px;
        }
        .btn-secondary { background: #6b7280; }
    </style>
</head>
<?php $onload = isset($_GET['noprint']) ? '' : 'onload="window.print()"'; ?>
<body <?php echo $onload; ?>>
    <!-- Print Controls -->
    <div class="controls no-print">
        <button onclick="window.print()" class="btn">Print / Save as PDF</button>
        <button onclick="window.close()" class="btn btn-secondary">Close Window</button>
    </div>

    <!-- Official Document Layout -->
    <table style="width: 100%; border-collapse: collapse; border: none;">
        <thead>
            <tr>
                <td style="padding: 0;">
                    <div class="print-header" style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 30px; border-bottom: 1px solid #000; padding-bottom: 15px;">
        <div class="header-text" style="text-align: left; padding-top: 10px;">
            <div style="font-weight: bold; font-size: 16px;">TRANSPORT AND WAREHOUSE DEPARTMENT</div>
            <div style="font-size: 14px; margin-top: 5px;">Inter-Office Memo <?php echo htmlspecialchars($request['memo_number']); ?></div>
        </div>
        <div class="header-logo" style="text-align: right; margin-bottom: 0;">
            <img src="Images/Ubix_Logo.png" alt="Ubix Logo" style="height: 60px;">
        </div>
                    </div>
                </td>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td style="padding: 0;">
                    <div class="memo-header">
        <table>
            <tr>
                <td>Date</td>
                <td>: <?php echo date('F d, Y', strtotime($request['created_at'])); ?></td>
            </tr>
            <tr>
                <td>TO</td>
                <td>: Emily R. Gepilano - SVP Operations</td>
            </tr>
            <tr>
                <td>CC</td>
                <td>: MMD/ACCOUNTING/ADMIN/AUDIT</td>
            </tr>
            <tr>
                <td>From</td>
                <td>: <?php echo htmlspecialchars($request['department']); ?></td>
            </tr>
            <tr>
                <td>SUBJECT</td>
                <td>: <strong><?php echo htmlspecialchars($request['title']); ?></strong></td>
            </tr>
        </table>
    </div>

    <div class="memo-body"><?php echo htmlspecialchars($request['message']); ?></div>

    <div class="signatures-wrapper">

        <!-- Dynamic Workflow Sections -->
        <?php foreach ($grouped_approvals as $tier => $people): ?>
        <div class="signature-section-block">
            <div class="section-title">
                <?php 
                    $title = htmlspecialchars($tier);
                    if (stripos($title, 'by') === false) {
                        $title .= ' By';
                    }
                    echo $title . ':';
                ?>
            </div>
            <div class="signature-grid">
                <?php foreach ($people as $person): ?>
                <div class="signature-person">
                    <div class="signature-img-container">
                        <?php if (!empty($person['signature_base64']) && $person['signature_base64'] !== 'BYPASSED'): ?>
                            <img src="<?php echo $person['signature_base64']; ?>" alt="Signature">
                        <?php endif; ?>
                    </div>
                    <div class="signature-name"><?php echo htmlspecialchars($person['approver_name']); ?></div>
                    <div class="signature-title"><?php echo htmlspecialchars($person['approver_position']); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

                    </div>
                </td>
            </tr>
        </tbody>
        <tfoot>
            <tr>
                <td style="padding: 0;">
                    <!-- Official Document Footer -->
                    <div class="print-footer" style="margin-top: 60px; border-top: 1px solid #000; padding-top: 15px; display: flex; justify-content: space-between; font-size: 12px; font-weight: normal;">
        <div style="text-align: left;">
            <div>Form # UBX-HRD-17</div>
            <div style="margin-top: 2px;">September 2026</div>
        </div>
        <div style="text-align: right; align-self: flex-end;">
            <div>Revision No.: 02</div>
        </div>
    </div>
                    </div>
                </td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
