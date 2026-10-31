<?php
/**
 * config/mailer.php
 * Defines send_customer_verification_code() used by signup_page_customer.php
 *
 * Requires PHPMailer:  composer require phpmailer/phpmailer
 * (run in C:\laragon\www\CLICKRESERVE)
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

// ---- EDIT THESE ----
const MAIL_HOST      = 'smtp.gmail.com';
const MAIL_PORT      = 587;
const MAIL_USERNAME  = 'your-email@gmail.com';
const MAIL_PASSWORD  = 'your-16-char-app-password'; // Gmail App Password, not your normal password
const MAIL_FROM      = 'your-email@gmail.com';
const MAIL_FROM_NAME = 'Creaty';
// --------------------

/**
 * Generates a 6-digit code, saves it on the customer row, and emails it.
 * Returns true on success, false on failure.
 *
 * Assumes these columns exist on the accounts table:
 *   verification_code        VARCHAR(10) NULL
 *   verification_expires_at  DATETIME NULL
 */
function send_customer_verification_code($conn, $table, $customerId, $email, $firstName)
{
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    $stmt = $conn->prepare("UPDATE {$table} SET verification_code = ?, verification_expires_at = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id = ?");
    $stmt->bind_param('si', $code, $customerId);
    $saved = $stmt->execute();
    $stmt->close();

    if (!$saved) {
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = MAIL_PORT;

        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($email, $firstName);

        $mail->isHTML(true);
        $mail->Subject = 'Your verification code';
        $safeName = htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8');
        $mail->Body    = "<p>Hi {$safeName},</p>"
                       . "<p>Your verification code is:</p>"
                       . "<h2 style=\"letter-spacing:4px;\">{$code}</h2>"
                       . "<p>It expires in 15 minutes.</p>";
        $mail->AltBody = "Hi {$firstName}, your verification code is {$code}. It expires in 15 minutes.";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Mailer error: ' . $mail->ErrorInfo);
        return false;
    }
}
