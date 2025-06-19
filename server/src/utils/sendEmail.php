<?php
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/createMailTransporter.php';
require_once __DIR__ . '/createBypassTransporter.php';

use PHPMailer\PHPMailer\Exception;
use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__ . '/../../../');
$dotenv->load();

class EmailService {
    private $transporter;
    private $bypassTransporter;
    
    public function __construct() {
        $this->transporter = MailTransport::createMailTransporterWrapper();
        $this->bypassTransporter = new AmazonBypassTransporter();
    }

    public function verifyEmail($user) {
        $mail = $this->transporter;

        $verificationLink = $_ENV['HOST_LINK_0'] . '/verify-email?token=' . $user['verification']['verificationToken'];
        $mail->setFrom($_ENV['BUSINESS_EMAIL_ADDRESS'], 'The Web');
        $mail->addAddress($user['email']);
        $mail->Subject = 'Verify Email';
        $mail->Body = "
            <p>Dear {$user['firstName']},</p>
            <p>We have received your request for email verification.</p>
            <p> 
                To complete the verification process, please click the link below:<br>
                <strong>Email Verification Link: </strong><a href=\"{$verificationLink}\">Verify Email</a><br>
                If you did not initiate this request, please disregard this message.
            </p>
            <p>
                For enquiries you can email us at <a href=\"mailto:" . $_ENV['BUSINESS_EMAIL_ADDRESS'] . "\">" . $_ENV['BUSINESS_EMAIL_ADDRESS'] . "</a>
            </p>
            <p>
                Best regards,<br>
                The Web Team
            </p>
        ";

        try {
            $mail->send();
            echo 'Email sent successfully!';
        } catch (Exception $e) {
            echo 'Mailer Error: ' . $mail->ErrorInfo;
        }
    }

    public function sendResetEmail($user, $token) {
        $mail = $this->transporter;

        $resetLink = $_ENV['HOST_LINK_0'] . '/reset-password?token=' . $token;
        $mail->setFrom($_ENV['BUSINESS_EMAIL_ADDRESS'], 'The Web');
        $mail->addAddress($user['email']);
        $mail->Subject = 'Reset Password';
        $mail->Body = "
            <p>Dear {$user['firstName']},</p>
            <p>We received a request to reset your password.</p>
            <p>
                To proceed with resetting your password, please click the link below:<br>
                <strong>Password Reset Link: </strong><a href=\"{$resetLink}\">Reset Password</a><br>
                If you did not initiate this request, please disregard this message.
            </p>
            <p>
                For enquiries you can email us at <a href=\"mailto:" . $_ENV['BUSINESS_EMAIL_ADDRESS'] . "\">" . $_ENV['BUSINESS_EMAIL_ADDRESS'] . "</a>
            </p>
            <p>
                Best regards,<br>
                The Web Team
            </p>
        ";

        try {
            $mail->send();
            echo 'Email sent successfully!';
        } catch (Exception $e) {
            echo 'Mailer Error: ' . $mail->ErrorInfo;
        }
    }
    
    public function getInContactEmail($userContact, $message) {
        $sourceEmail = $_ENV['BUSINESS_EMAIL_ADDRESS']; // Verified sender email in SES
        $recipientEmail = $_ENV['BUSINESS_EMAIL_ADDRESS']; // Receiver email
        $subject = "Contact Form Submission from {$userContact['firstName']}";
        $htmlBody = "
            <p>Dear The Web Team,</p>
            <p>You have received a new message from your contact form.</p>
            <p>
                <strong>Email received from:</strong> {$userContact['firstName']}<br>
                <strong>Message:</strong> \"{$message}\"<br>
                <strong>Email:</strong> {$userContact['email']}
            </p>
            <p>
                Best regards,<br>
                {$userContact['firstName']}
            </p>
        ";

        $response = $this->bypassTransporter->sendEmail($sourceEmail, $recipientEmail, $subject, $htmlBody);

        if ($response['status'] === 'success') {
            echo "Email sent successfully! Message ID: " . $response['messageId'];
        } else {
            echo "Error sending email: " . $response['message'];
        }
    }

    // public function getInContactEmail($userContact, $message) {
    //     $mail = $this->bypassTransporter;

    //     $mail->setFrom($_ENV['BUSINESS_EMAIL_ADDRESS'], 'The Web');
    //     $mail->addAddress($_ENV['BUSINESS_EMAIL_ADDRESS']);
    //     $mail->Subject = "Contact Form Submission from {$userContact['firstName']} ";
    //     $mail->Body = "
    //         <p>Dear The Web Team,</p>
    //         <p>You have received a new message from your contact form.</p>
    //         <p>
    //             <strong>Email received from: </strong>{$userContact['firstName']}<br>
    //             Message: \"{$message}\"<br>
    //             Email: {$userContact['email']}
    //         </p>
    //         <p>
    //             Best regards,<br>
    //             The Web Team
    //         </p>
    //     ";

    //     try {
    //         $mail->send();
    //         echo 'Email sent successfully!';
    //     } catch (Exception $e) {
    //         echo 'Mailer Error: ' . $mail->ErrorInfo;
    //     }
    // }


    public function rentalNotificationEmail($user, $unit, $rental) {
        $mail = $this->transporter;

        // Format the rental start date
        $formattedStartDate = isset($rental['rentalStartDate']) 
            ? date('d M Y', strtotime($rental['rentalStartDate'])) 
            : '[Start Date Not Specified]';

        $mail->setFrom($_ENV['BUSINESS_EMAIL_ADDRESS'], 'The Web');
        $mail->addAddress($user['email']);
        $mail->Subject = 'Rental Application Approved';
        $mail->Body = "
            <p>Dear {$user['firstName']},</p>
            <p>We are pleased to inform you that your rental application has been approved.</p>
            <p>
                Thank you for choosing us—we appreciate the opportunity to serve you.<br>
                We look forward to ensuring a smooth and enjoyable leasing experience.<br>
                <strong>Rental Identifier Number: </strong>{$rental['_id']}<br>
                <strong>Unit Number: </strong>{$unit['unitNumber']}<br>
                <strong>Unit Type: </strong>{$unit['unitType']}<br>
                <strong>Monthly Rent: </strong>R {$unit['unitPrice']}.00<br>
                <strong>Lease Start Date: </strong>{$formattedStartDate}
            </p>
            <p>
                For enquiries you can email us at <a href=\"mailto:" . $_ENV['BUSINESS_EMAIL_ADDRESS'] . "\">" . $_ENV['BUSINESS_EMAIL_ADDRESS'] . "</a>
            </p>
            <p>
                Best regards,<br>
                The Web Team
            </p>
        ";

        try {
            $mail->send();
            echo 'Email sent successfully!';
        } catch (Exception $e) {
            echo 'Mailer Error: ' . $mail->ErrorInfo;
        }
    }

    // NEW FUNCTION - ADD TO EXPRESS
    public function sendRentalActionReminderEmail($user, $message) {
        $mail = $this->transporter;

        $mail->setFrom($_ENV['BUSINESS_EMAIL_ADDRESS'], 'The Web');
        $mail->addAddress($user['email']);
        $mail->Subject = 'Action Required: Rental Application Update';
        $mail->Body = "
            <p>Dear {$user['firstName']},</p>
            <p>We want to remind you that further action is required to complete your rental application.</p>
            <p>{$message}</p>
            <p>Please review the necessary steps and complete them at your earliest convenience.</p>
            <p>
                For any questions, feel free to email us at 
                <a href=\"mailto:{$_ENV['BUSINESS_EMAIL_ADDRESS']}\">{$_ENV['BUSINESS_EMAIL_ADDRESS']}</a>.
            </p>
            <p>
                Best regards,<br>
                The Web Team
            </p>
        ";

        try {
            $mail->send();
            echo 'Action reminder email sent successfully!';
        } catch (Exception $e) {
            echo 'Mailer Error: ' . $mail->ErrorInfo;
        }
    }

    public function sendRentalRejectionEmail($user, $message) {
        $mail = $this->transporter;

        $mail->setFrom($_ENV['BUSINESS_EMAIL_ADDRESS'], 'The Web');
        $mail->addAddress($user['email']);
        $mail->Subject = 'Rental Application Rejected';
        $mail->Body = "
            <p>Dear {$user['firstName']},</p>
            <p>We regret to inform you that your rental application has been rejected due to the following reasons:</p>
            <p>{$message}</p>
            <p>Please review the documents and resubmit your application.</p>
            <p>
                For enquiries you can email us at <a href=\"mailto:{$_ENV['BUSINESS_EMAIL_ADDRESS']}\">{$_ENV['BUSINESS_EMAIL_ADDRESS']}</a>
            </p>
            <p>
                Best regards,<br>
                The Web Team
            </p>
        ";

        try {
            $mail->send();
            echo 'Rejection email sent successfully!';
        } catch (Exception $e) {
            echo 'Mailer Error: ' . $mail->ErrorInfo;
        }
    }

    public function sendExtendedDateEmail($user, $message) {
        $mail = $this->transporter;
    
        // Ensure $message is a string before using json_decode
        if (is_array($message)) {
            // Convert array to a JSON string
            $message = json_encode($message);
        }
    
        // Decode JSON message and extract the date
        $decodedMessage = json_decode($message, true);
        $date = isset($decodedMessage['message']) ? $decodedMessage['message'] : null;
    
        // Format the date into "31 Jan 2025"
        $formattedDate = $date ? date('d M Y', strtotime($date)) : 'Invalid date';
    
        $mail->setFrom($_ENV['BUSINESS_EMAIL_ADDRESS'], 'The Web');
        $mail->addAddress($user['email']);
        $mail->Subject = 'Rental Application Extended';
        $mail->Body = "
            <p>Dear {$user['firstName']},</p>
            <p>We are pleased to inform you that your request for a lease extension has been approved.</p>
            <p>
                <strong>Lease Extension Date: </strong>{$formattedDate}.
            </p>
            <p>We appreciate your continued residency and look forward to serving you in the future.</p>
            <p>
                For enquiries you can email us at <a href=\"mailto:{$_ENV['BUSINESS_EMAIL_ADDRESS']}\">{$_ENV['BUSINESS_EMAIL_ADDRESS']}</a>
            </p>
            <p>
                Best regards,<br>
                The Web Team
            </p>
        ";
    
        try {
            $mail->send();
            echo 'Extension email sent successfully!';
        } catch (Exception $e) {
            echo 'Mailer Error: ' . $mail->ErrorInfo;
        }
    }

    // NEW FUNCTION - ADD TO EXPRESS
    public function sendVendorEmail($user, $callLog) {
        $mail = $this->transporter;

        // Format the request creation date
        $formattedStartDate = isset($callLog['createdAt']) 
            ? date('d M Y', strtotime($callLog['createdAt'])) 
            : '[Start Date Not Specified]';

        $mail->setFrom($_ENV['BUSINESS_EMAIL_ADDRESS'], 'The Web');
        $mail->addAddress($callLog['vendorInfo']['vendorContact']);
        $mail->Subject = "Log Number {$callLog['logNumber']} | Request from {$user['firstName']}";

        $mail->Body = "
            <p>Dear {$callLog['vendorInfo']['vendorType']},</p>
            <p>We have received a call log request from {$user['firstName']} and require your response.</p>
            <p>
                <strong>Request Details:</strong><br>
                - <strong>Issued On:</strong> {$formattedStartDate}<br>
                - <strong>Call Log Number:</strong> {$callLog['logNumber']}<br>
                - <strong>Call Log Type:</strong> {$callLog['callType']}<br>
            </p>
            <p>
                Please reply to this email to receive the full request details.
            </p>
            <p>
                For inquiries, feel free to reach out to us at <a href=\"mailto:{$_ENV['BUSINESS_EMAIL_ADDRESS']}\">{$_ENV['BUSINESS_EMAIL_ADDRESS']}</a>.
            </p>
            <p>
                Best regards,<br>
                The Web Team
            </p>
        ";

        try {
            $mail->send();
            echo 'Email sent successfully!';
        } catch (Exception $e) {
            echo 'Mailer Error: ' . $mail->ErrorInfo;
        }
    }
    
}