<?php
// Email utility class using PHPMailer for Gmail SMTP
require_once __DIR__ . '/../vendor/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/phpmailer/src/SMTP.php';
require_once __DIR__ . '/../vendor/phpmailer/src/Exception.php';
require_once __DIR__ . '/email_config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class EmailUtils {
    
    /**
     * Send invoice email with HTML content and/or PDF attachment
     */
    public static function sendInvoiceEmail($customer, $invoiceNumber, $startDate, $endDate, $totalAmount, $htmlContent = null, $pdfFile = null, $pdfFilename = null) {
        // Local / test: never send real email
        if (defined('MAIL_DRIVER') && MAIL_DRIVER === 'log') {
            $logDir = dirname(__DIR__) . '/logs';
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
            $line = sprintf(
                "[%s] MAIL_DRIVER=log invoice=%s customer=%s total=%s range=%s..%s\n",
                date('c'),
                $invoiceNumber,
                $customer['name'] ?? '',
                $totalAmount,
                $startDate,
                $endDate
            );
            @file_put_contents($logDir . '/mail.log', $line, FILE_APPEND | LOCK_EX);
            return true;
        }

        // Try OAuth first, fallback to SMTP
        require_once __DIR__ . '/gmail_oauth.php';
        
        if (GmailOAuth::isAuthorized()) {
            try {
                $subject = 'Invoice ' . $invoiceNumber . ' - ' . $customer['name'];
                $emailBody = self::generateEmailHTML($customer, $invoiceNumber, $startDate, $endDate, $totalAmount, $htmlContent);
                
                $attachments = [];
                if ($pdfFile && file_exists($pdfFile)) {
                    $attachments[] = ['path' => $pdfFile, 'name' => $pdfFilename];
                }
                
                return GmailOAuth::sendEmail('danny@sourflour.org', $subject, $emailBody, 'Sour Flour Bakery', $attachments);
                
            } catch (Exception $e) {
                error_log("OAuth email failed, trying SMTP fallback: " . $e->getMessage());
                // Fall through to SMTP method below
            }
        }
        
        // SMTP fallback method
        $mail = new PHPMailer(true);
        
        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USERNAME;
            $mail->Password   = SMTP_PASSWORD;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = SMTP_PORT;
            
            // Recipients
            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $mail->addAddress(SMTP_USERNAME); // Send to your own email
            $mail->addReplyTo(REPLY_TO_EMAIL, REPLY_TO_NAME);
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = 'Invoice ' . $invoiceNumber . ' - ' . $customer['name'];
            
            // Email body
            $emailBody = self::generateEmailHTML($customer, $invoiceNumber, $startDate, $endDate, $totalAmount, $htmlContent);
            $mail->Body = $emailBody;
            
            // Add PDF attachment if provided
            if ($pdfFile && file_exists($pdfFile)) {
                $mail->addAttachment($pdfFile, $pdfFilename);
            }
            
            $mail->send();
            return true;
            
        } catch (Exception $e) {
            error_log("Email sending failed: " . $mail->ErrorInfo);
            return false;
        }
    }
    
    /**
     * Generate HTML email content
     */
    private static function generateEmailHTML($customer, $invoiceNumber, $startDate, $endDate, $totalAmount, $invoiceHTML = null) {
        $html = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; color: #333; line-height: 1.6; }
                .email-header { 
                    background: #2c3e50; 
                    color: white; 
                    padding: 20px; 
                    border-radius: 5px; 
                    margin-bottom: 20px; 
                    text-align: center;
                }
                .email-details { 
                    background: #f8f9fa; 
                    padding: 20px; 
                    border: 1px solid #dee2e6; 
                    border-radius: 5px; 
                    margin-bottom: 20px; 
                }
                .amount { 
                    font-size: 24px; 
                    font-weight: bold; 
                    color: #28a745;
                    text-align: center;
                    padding: 10px;
                    background: #e8f5e8;
                    border-radius: 5px;
                    margin: 10px 0;
                }
                .invoice-content { 
                    border: 1px solid #ddd; 
                    border-radius: 5px; 
                    margin-top: 20px;
                    background: white;
                }
                .footer {
                    margin-top: 30px;
                    padding-top: 20px;
                    border-top: 1px solid #dee2e6;
                    color: #666;
                    font-size: 14px;
                    text-align: center;
                }
                .logo {
                    font-size: 28px;
                    font-weight: bold;
                    margin-bottom: 5px;
                }
                .tagline {
                    font-size: 14px;
                    opacity: 0.9;
                }
            </style>
        </head>
        <body>
            <div class='email-header'>
                <div class='logo'>🥖 Sour Flour Bakery</div>
                <div class='tagline'>Artisan Breads & Pastries</div>
                <h2 style='margin: 15px 0 5px 0;'>📧 Invoice Generated</h2>
                <p style='margin: 0;'>Invoice #{$invoiceNumber} for " . htmlspecialchars($customer['name']) . "</p>
            </div>
            
            <div class='email-details'>
                <h3 style='margin-top: 0; color: #2c3e50;'>📋 Invoice Summary</h3>
                <table style='width: 100%; border-collapse: collapse;'>
                    <tr>
                        <td style='padding: 8px 0; font-weight: bold;'>Customer:</td>
                        <td style='padding: 8px 0;'>" . htmlspecialchars($customer['name']) . "</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0; font-weight: bold;'>Period:</td>
                        <td style='padding: 8px 0;'>" . date('F j, Y', strtotime($startDate)) . " - " . date('F j, Y', strtotime($endDate)) . "</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0; font-weight: bold;'>Generated:</td>
                        <td style='padding: 8px 0;'>" . date('F j, Y g:i A') . "</td>
                    </tr>
                </table>
                
                <div class='amount'>💰 Total: $" . number_format($totalAmount, 2) . "</div>
            </div>";
        
        if ($invoiceHTML) {
            $html .= "
            <h3 style='color: #2c3e50;'>📄 Invoice Details</h3>
            <div class='invoice-content'>
                {$invoiceHTML}
            </div>";
        } else {
            $html .= "
            <p style='text-align: center; padding: 20px; background: #e3f2fd; border-radius: 5px;'>
                📎 The detailed invoice is attached as a PDF file.
            </p>";
        }
        
        $html .= "
            <div class='footer'>
                <p><strong>🍞 Thank you for choosing Sour Flour Bakery!</strong></p>
                <p><em>This invoice was automatically generated by the Sour Flour Bakery management system.</em></p>
                <p>Best regards,<br>Danny & The Sour Flour Bakery Team</p>
                <hr style='margin: 20px 0; border: none; border-top: 1px solid #dee2e6;'>
                <p style='font-size: 12px; color: #999;'>
                    📧 " . SMTP_FROM_EMAIL . " | 🌐 sourflour.org<br>
                    Questions? Reply to this email and Danny will get back to you!
                </p>
            </div>
        </body>
        </html>";
        
        return $html;
    }
    
    /**
     * Test email functionality
     */
    public static function testEmail() {
        $mail = new PHPMailer(true);
        
        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USERNAME;
            $mail->Password   = SMTP_PASSWORD;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = SMTP_PORT;
            
            // Recipients
            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $mail->addAddress(SMTP_USERNAME);
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = 'Test Email - Sour Flour Bakery System';
            $mail->Body    = '
                <h2>🎉 Email Configuration Test</h2>
                <p>This is a test email to verify that your Gmail SMTP configuration is working correctly.</p>
                <p><strong>Configuration Details:</strong></p>
                <ul>
                    <li>SMTP Host: ' . SMTP_HOST . '</li>
                    <li>Port: ' . SMTP_PORT . '</li>
                    <li>From: ' . SMTP_FROM_EMAIL . '</li>
                </ul>
                <p>If you received this email, your email system is working perfectly! 🎊</p>
                <hr>
                <p style="color: #666; font-size: 12px;">
                    <em>Generated on ' . date('F j, Y g:i A') . '</em>
                </p>
            ';
            
            $mail->send();
            return true;
            
        } catch (Exception $e) {
            return "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
        }
    }
}
?> 