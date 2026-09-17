<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once 'PHPMailer/Exception.php';
require_once 'PHPMailer/PHPMailer.php';
require_once 'PHPMailer/SMTP.php';
require_once __DIR__ . '/env.php';

function sendMagicLinkEmail($toEmail, $toName, $token, $requestTitle, $tierLabel) {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = $_ENV['SMTP_HOST'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['SMTP_USERNAME'];
        $mail->Password   = $_ENV['SMTP_PASSWORD'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $_ENV['SMTP_PORT'];

        // Recipients
        $mail->setFrom($_ENV['SMTP_FROM_EMAIL'], $_ENV['SMTP_FROM_NAME']);
        $mail->addAddress($toEmail, $toName);

        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Action Required: Approval for ' . $requestTitle;
        $appUrl = rtrim($_ENV['APP_URL'], '/');
        $link = $appUrl . "/approve.php?token=" . $token;
        // Embed Logo Image
        $logoPath = __DIR__ . '/Images/Ubix_Logo.png';
        if (file_exists($logoPath)) {
            $mail->addEmbeddedImage($logoPath, 'ubix_logo');
            $logoHtml = "<img src='cid:ubix_logo' alt='Ubix Logo' style='max-height: 60px;'>";
        } else {
            $logoHtml = "<img src='{$appUrl}/Images/Ubix_Logo.png' alt='Ubix Logo' style='max-height: 60px;'>"; // Fallback
        }
        
        $mail->Body    = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px;'>
                <div style='text-align: center; margin-bottom: 20px;'>
                    $logoHtml
                </div>
                <h3 style='color: #4F46E5;'>Disposal Request Approval Required</h3>
                <p>Hello $toName,</p>
                <p>You are requested to review and sign as <strong>$tierLabel</strong> for the following disposal request:</p>
                <div style='background-color: #f9fafb; padding: 15px; border-radius: 5px; margin: 15px 0;'>
                    <p style='margin: 0;'><strong>Title:</strong> $requestTitle</p>
                </div>
                <p>Please click the secure link below to review and approve. <strong>No password is required.</strong></p>
                <p style='text-align: center; margin: 30px 0;'>
                    <a href='$link' style='padding: 12px 24px; background-color: #4F46E5; color: #ffffff; text-decoration: none; border-radius: 5px; font-weight: bold;'>Review & Sign Request</a>
                </p>
                <p style='color: #6b7280; font-size: 12px;'>Or copy and paste this link: <br>$link</p>
                <hr style='border: 0; border-top: 1px solid #e5e7eb; margin: 20px 0;'>
                <p style='color: #6b7280; font-size: 12px; margin: 0;'>Thank you,<br>Disposal App System</p>
            </div>
        ";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}

function sendFinalApprovalEmail($pdo, $request_id) {
    // 1. Fetch final recipients
    $stmt = $pdo->query("SELECT u.email, u.name FROM workflow_final_emails f JOIN users u ON f.user_id = u.id");
    $recipients = $stmt->fetchAll();
    
    if (count($recipients) === 0) return true; // No recipients
    
    // 2. Fetch request details for email subject/body
    $stmt = $pdo->prepare("SELECT title, memo_number, attachment_path FROM requests WHERE id = ?");
    $stmt->execute([$request_id]);
    $request = $stmt->fetch();
    
    if (!$request) return false;
    
    // 4. Send Email
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = $_ENV['SMTP_HOST'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['SMTP_USERNAME'];
        $mail->Password   = $_ENV['SMTP_PASSWORD'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $_ENV['SMTP_PORT'];

        $mail->setFrom($_ENV['SMTP_FROM_EMAIL'], $_ENV['SMTP_FROM_NAME']);
        
        foreach ($recipients as $recipient) {
            $mail->addAddress($recipient['email'], $recipient['name']);
        }

        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Final Approved: ' . $request['title'];
        $appUrl = rtrim($_ENV['APP_URL'], '/');
        
        // Embed Logo Image
        $logoPath = __DIR__ . '/Images/Ubix_Logo.png';
        if (file_exists($logoPath)) {
            $mail->addEmbeddedImage($logoPath, 'ubix_logo');
            $logoHtml = "<img src='cid:ubix_logo' alt='Ubix Logo' style='max-height: 60px;'>";
        } else {
            $logoHtml = "<img src='{$appUrl}/Images/Ubix_Logo.png' alt='Ubix Logo' style='max-height: 60px;'>";
        }
        
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px;'>
                <div style='text-align: center; margin-bottom: 20px;'>
                    $logoHtml
                </div>
                <h3 style='color: #059669;'>Disposal Request Fully Approved</h3>
                <p>Hello,</p>
                <p>The request form of <strong>{$request['title']}</strong> is now fully approved.</p>
                <p>Click this button to go to the completed request form:</p>
                <p style='text-align: center; margin: 30px 0;'>
                    <a href='{$appUrl}/approve.php?id={$request_id}' style='padding: 12px 24px; background-color: #059669; color: #ffffff; text-decoration: none; border-radius: 5px; font-weight: bold;'>View Approved Request</a>
                </p>
                <hr style='border: 0; border-top: 1px solid #e5e7eb; margin: 20px 0;'>
                <p style='color: #6b7280; font-size: 12px; margin: 0;'>Thank you,<br>Disposal App System</p>
            </div>
        ";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Final email could not be sent. Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}
?>
