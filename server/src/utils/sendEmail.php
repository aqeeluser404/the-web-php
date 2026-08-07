<?php
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/createMailTransporter.php';
require_once __DIR__ . '/createBypassTransporter.php';

use PHPMailer\PHPMailer\Exception;
use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__ . '/../../../');
$dotenv->load();

class EmailService
{
    private $transporter;
    private $bypassTransporter;

    public function __construct()
    {
        $this->transporter = MailTransport::createMailTransporterWrapper();
        $this->bypassTransporter = new AmazonBypassTransporter();
    }

    public function sendOtpEmail($user, $otp)
    {
        $mail = $this->transporter;

        $mail->setFrom($_ENV['BUSINESS_EMAIL_ADDRESS'], 'The-WEB');
        $mail->addAddress($user['email']);
        $mail->Subject = 'Your One-Time Password (OTP)';
        $mail->isHTML(true);

        $mail->Body = "
            <p>Dear {$user['firstName']},</p>
            <p>We received a request to verify your login. Please use the OTP below:</p>
            <h2 style='color:#2c3e50;'>{$otp}</h2>
            <p>This OTP will expire in 5 minutes. Do not share it with anyone.</p>
            <p>If you did not request this, please ignore this email.</p>
            <p>Best regards,<br>The-WEB Team</p>
        ";

        try {
            $mail->send();
            error_log('OTP email sent successfully to ' . $user['email']);
        } catch (Exception $e) {
            error_log('Mailer Error: ' . $mail->ErrorInfo);
            throw new Exception('Failed to send OTP email');
        }
    }

    public function verifyEmail($user, $type = 'user')
    {
        $mail = $this->transporter;
        $mail->clearAddresses(); 

        $isGuardian = $type === 'guardian';

        $recipientEmail = $isGuardian ? ($user['guardianEmail'] ?? null) : $user['email'];
        if (!$recipientEmail) {
            error_log('verifyEmail: no ' . ($isGuardian ? 'guardian' : 'user') . ' email on file for user ' . $user['_id']);
            return;
        }

        $tokenField = $isGuardian ? 'guardianVerification' : 'verification';
        $verificationToken = $user[$tokenField]['verificationToken'] ?? null;
        $verificationLink = $_ENV['HOST_LINK_0'] . '/verify-email?token=' . $verificationToken;

        $greetingName = $isGuardian ? ($user['guardianName'] ?? 'Guardian') : $user['firstName'];
        $subject = $isGuardian ? 'Verify Guardian Email' : 'Verify Email';
        $intro = $isGuardian
            ? "We have received a request to verify the guardian email linked to <strong>{$user['firstName']} {$user['lastName']}</strong>'s rental account."
            : "We have received your request for email verification.";

        $mail->setFrom($_ENV['BUSINESS_EMAIL_ADDRESS'], 'The-WEB');
        $mail->addAddress($recipientEmail);
        $mail->Subject = $subject;
        $mail->Body = "
        <p>Dear {$greetingName},</p>
        <p>{$intro}</p>
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
            The-WEB Team
        </p>
    ";

        try {
            $mail->send();
            error_log(($isGuardian ? 'Guardian' : 'User') . ' verification email sent to: ' . $recipientEmail);
        } catch (Exception $e) {
            error_log('Mailer Error: ' . $mail->ErrorInfo);
            throw $e;
        }
    }

    public function sendResetEmail($user, $token)
    {
        $mail = $this->transporter;

        $resetLink = $_ENV['HOST_LINK_0'] . '/reset-password?token=' . $token;
        $mail->setFrom($_ENV['BUSINESS_EMAIL_ADDRESS'], 'The-WEB');
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
                The-WEB Team
            </p>
        ";

        try {
            $mail->send();
            echo 'Email sent successfully!';
        } catch (Exception $e) {
            echo 'Mailer Error: ' . $mail->ErrorInfo;
        }
    }

    public function getInContactEmail($userContact, $message)
    {
        $mail = $this->transporter;

        $mail->setFrom($_ENV['BUSINESS_EMAIL_ADDRESS'], 'The-WEB');
        $mail->addAddress($_ENV['BUSINESS_EMAIL_ADDRESS']);  // your preferred business receiving email

        $mail->Subject = "Contact Form Submission from {$userContact['firstName']}";
        $mail->Body = "
            <p>Dear The-WEB Team,</p>
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

        try {
            $mail->send();
            echo "Email sent successfully!";
        } catch (Exception $e) {
            echo "Mailer Error: " . $mail->ErrorInfo;
        }
    }

    public function rentalApplicationEmail($user, $documentUrls = [], $rental = null)
    {
        $mail = $this->transporter;

        $mail->setFrom($_ENV['BUSINESS_EMAIL_ADDRESS'], 'The-WEB');
        $mail->addAddress($_ENV['BUSINESS_EMAIL_ADDRESS']);

        $mail->Subject = "Application created by {$user['firstName']}";

        // Build document links section
        $documentsHtml = '';
        if (!empty($documentUrls)) {
            $documentsHtml = "<h3>📎 Attached Documents:</h3><ul>";
            foreach ($documentUrls as $index => $url) {
                if ($url) {
                    $documentsHtml .= "<li><a href='{$url}' target='_blank'>Document " . ($index + 1) . "</a></li>";
                }
            }
            $documentsHtml .= "</ul>";
        }

        $mail->Body = "
            <p>Dear The-WEB Team,</p>
            <p>You have received a new application from The-WEB.</p>
            <p>
                <strong>First Name:</strong> {$user['firstName']}<br>
                <strong>Last Name:</strong> {$user['lastName']}<br>
                <strong>Username:</strong> {$user['username']}<br>
                <strong>Email:</strong> {$user['email']}<br>
                " . ($rental ? "<strong>Rental ID:</strong> " . (string) $rental['_id'] . "<br>" : "") . "
            </p>
            {$documentsHtml}
            <p>
                Best regards,<br>
                The-WEB Team
            </p>
        ";

        try {
            $mail->send();
            echo "Email sent successfully!";
        } catch (Exception $e) {
            echo "Mailer Error: " . $mail->ErrorInfo;
        }
    }

    // public function rentalApplicationToUserEmail($user) {
    //     $mail = $this->transporter;

    //     $mail->setFrom($_ENV['BUSINESS_EMAIL_ADDRESS'], 'The-WEB');
    //     $mail->addAddress($user['email']);
    //     $mail->Subject = 'Rental Application Created';

    //     $applicationLink = $_ENV['HOST_LINK_2'] . '/digital-application?userId=' . $user['_id']; 

    //     $mail->Body = "
    //         <p>Dear {$user['firstName']},</p>
    //         <p>Your rental application at The-WEB has been created.</p>
    //         <p>
    //             We're excited to let you know that your rental application has been successfully submitted to The-WEB.<br>  
    //             Our team will review your details and get in touch with you shortly regarding the next steps.<br> 
    //             We appreciate your interest and look forward to helping you find the perfect rental solution.
    //         </p>
    //         <p>
    //             Please complete your credit check application here: <a href=\"{$applicationLink}\">Complete Application</a>
    //         </p>
    //         <p>
    //             For enquiries you can email us at <a href=\"mailto:" . $_ENV['BUSINESS_EMAIL_ADDRESS'] . "\">" . $_ENV['BUSINESS_EMAIL_ADDRESS'] . "</a>
    //         </p>
    //         <p>
    //             Best regards,<br>
    //             The-WEB Team
    //         </p>
    //     ";

    //     // $attachmentPath = $_SERVER['DOCUMENT_ROOT'] . '/backend/server/attachments/ApplicationForm.pdf';

    //     // if (file_exists($attachmentPath)) {
    //     //     $mail->addAttachment($attachmentPath, 'ApplicationForm.pdf');
    //     // }

    //     try {
    //         $mail->send();
    //         echo 'Email sent successfully!';
    //     } catch (Exception $e) {
    //         echo 'Mailer Error: ' . $mail->ErrorInfo;
    //     }
    // }


    public function rentalApplicationToUserEmail($user, $tenantLink = null, $guardianLink = null, $guardianEmail = null)
    {
        $mail = $this->transporter;
        $mail->clearAddresses();
        $mail->setFrom($_ENV['BUSINESS_EMAIL_ADDRESS'], 'The-WEB');
        $mail->addAddress($user['email']);
        $mail->Subject = 'Rental Application Created';

        $applicationLink = $tenantLink ?? $_ENV['HOST_LINK_0'] . '/digital-application?userId=' . $user['_id'];

        $mail->Body = "
            <p>Dear {$user['firstName']},</p>
            <p>Your rental application at The-WEB has been created.</p>
            <p>
                We're excited to let you know that your rental application has been successfully submitted to The-WEB.<br>  
                Our team will review your details and get in touch with you shortly regarding the next steps.<br> 
                We appreciate your interest and look forward to helping you find the perfect rental solution.
            </p>
            <p>
                Please complete your credit check application here: <a href=\"{$applicationLink}\">Complete Application</a>
            </p>

            <p><strong>Credit Check Payment</strong></p>
            <p>
                A fee of <strong>R250.00</strong> is required to process your credit check application.<br>
                Please use the following banking details to make your payment:
            </p>
            <p>
                <strong>Banking Details for Credit Check Payment</strong><br><br>
                <strong>Legal entity name:</strong> Trafalgar Property Management (Pty) Ltd.<br>
                <strong>Trading as:</strong> null<br>
                <strong>Registration number:</strong> 1989/003678/07<br>
                <strong>Name of the account:</strong> TRAFALGAR-TRUST ITOS54(1) OF P<br>
                <strong>Account number:</strong> 270739335<br>
                <strong>Type of account:</strong> Business Cheque Account<br>
                <strong>Branch name:</strong> Thibault square<br>
                <strong>Branch code:</strong> 020909<br>
                <strong>Reference:</strong> 400K0001005<br>
                <strong>Amount:</strong> R250.00
            </p>
            <p>
                <strong>Please use the reference number 400K0001005 when making your payment.</strong>
            </p>
            
            <p>
                For enquiries you can email us at <a href=\"mailto:" . $_ENV['BUSINESS_EMAIL_ADDRESS'] . "\">" . $_ENV['BUSINESS_EMAIL_ADDRESS'] . "</a>
            </p>
            <p>
                Best regards,<br>
                The-WEB Team
            </p>
        ";
        try {
            $mail->send();
            echo 'Email sent successfully!';
        } catch (Exception $e) {
            echo 'Mailer Error: ' . $mail->ErrorInfo;
        }
    }

    // $applicationLink = $_ENV['HOST_LINK_0'] . '/digital-application?userId=' . $user['_id']; 
    // " . ($guardianLink ? "
    // <p>
    //     <strong>Guardian Signature Required:</strong><br>
    //     Please forward this link to the guardian for their signature:<br>
    //     <a href=\"{$guardianLink}\">Guardian Signature Link</a>
    // </p>
    // " : "") . "

    // banking details
    // reference number: 400K0001005
    // Document upload for Credit Check of proof of payment total R250

    // they must upload proof of payment for the credit check of 250 after signing
    // application fee proof of payment

    public function sendGuardianInviteEmail($guardianEmail, $guardianLink, $guardianName, $tenantName)
    {
        $mail = $this->transporter;
        $mail->clearAddresses();
        $mail->setFrom($_ENV['BUSINESS_EMAIL_ADDRESS'], 'The-WEB');
        $mail->addAddress($guardianEmail);
        $mail->Subject = 'Guardian Signature Required - Rental Application';

        $mail->Body = "
            <p>Dear {$guardianName},</p>
            <p><strong>{$tenantName}</strong> has submitted a rental application at The-WEB and requires your signature as a guardian.</p>
            <p>
                Sign Application and complete application here: <a href=\"{$guardianLink}\">Complete Application</a>
            </p>
            <p>
                If the button doesn't work, copy and paste this link into your browser:<br>
                <a href=\"{$guardianLink}\">{$guardianLink}</a>
            </p>

            <p><strong>Credit Check Payment</strong></p>
            <p>
                A fee of <strong>R250.00</strong> is required to process your credit check application.<br>
                Please use the following banking details to make your payment:
            </p>
            <p>
                <strong>Banking Details for Credit Check Payment</strong><br><br>
                <strong>Legal entity name:</strong> Trafalgar Property Management (Pty) Ltd.<br>
                <strong>Trading as:</strong> null<br>
                <strong>Registration number:</strong> 1989/003678/07<br>
                <strong>Name of the account:</strong> TRAFALGAR-TRUST ITOS54(1) OF P<br>
                <strong>Account number:</strong> 270739335<br>
                <strong>Type of account:</strong> Business Cheque Account<br>
                <strong>Branch name:</strong> Thibault square<br>
                <strong>Branch code:</strong> 020909<br>
                <strong>Reference:</strong> 400K0001005<br>
                <strong>Amount:</strong> R250.00
            </p>
            <p>
                <strong>Please use the reference number 400K0001005 when making your payment.</strong>
            </p>
            <p>
                <strong>Important:</strong> Once your payment has been made, please upload your proof of payment to your profile under the documents section. This will allow us to confirm your payment and proceed with your application.
            </p>

            <p>
                For enquiries you can email us at <a href=\"mailto:" . $_ENV['BUSINESS_EMAIL_ADDRESS'] . "\">" . $_ENV['BUSINESS_EMAIL_ADDRESS'] . "</a>
            </p>
            <p>
                Best regards,<br>
                The-WEB Team
            </p>
        ";

        try {
            $mail->send();
            echo 'Email sent successfully!';
        } catch (Exception $e) {
            echo 'Mailer Error: ' . $mail->ErrorInfo;
        }
    }
    
    public function documentUploadToUserEmail($user)
    {
        $mail = $this->transporter;

        $mail->setFrom($_ENV['BUSINESS_EMAIL_ADDRESS'], 'The-WEB');
        $mail->addAddress($user['email']);

        $mail->Subject = "Your documents have been successfully uploaded";
        $mail->Body = "
            <p>Dear {$user['firstName']},</p>
            <p>
                Thank you for submitting your documents.<br>
                Our team will review them shortly as part of your rental application process.<br>
                If any additional information is required, we’ll reach out to you directly.<br>
                We appreciate your prompt response and look forward to assisting you further.
            </p>
            <p>
                For enquiries you can email us at <a href=\"mailto:" . $_ENV['BUSINESS_EMAIL_ADDRESS'] . "\">" . $_ENV['BUSINESS_EMAIL_ADDRESS'] . "</a>
            </p>
            <p>
                Best regards,<br>
                The-WEB Team
            </p>
        ";

        try {
            $mail->send();
            echo "Email sent successfully!";
        } catch (Exception $e) {
            echo "Mailer Error: " . $mail->ErrorInfo;
        }
    }

    public function documentUploadEmail($user)
    {
        $mail = $this->transporter;

        $mail->setFrom($_ENV['BUSINESS_EMAIL_ADDRESS'], 'The-WEB');
        $mail->addAddress($_ENV['BUSINESS_EMAIL_ADDRESS']);

        $mail->Subject = "Documents uploaded by {$user['firstName']}";
        $mail->Body = "
            <p>Dear The-WEB Team,</p>
            <p>A user has uploaded documents. Please log in to view them.</p>
            <p>
                <strong>Full Name:</strong> {$user['firstName']}<br>
                <strong>Username:</strong> {$user['username']}<br>
            </p>
            <p>
                Best regards,<br>
                The-WEB Team
            </p>
        ";

        try {
            $mail->send();
            echo "Email sent successfully!";
        } catch (Exception $e) {
            echo "Mailer Error: " . $mail->ErrorInfo;
        }
    }

    public function rentalNotificationEmail($user, $unit, $rental)
    {
        $mail = $this->transporter;

        // Format the rental start date
        if (isset($rental['rentalStartDate'])) {
            if ($rental['rentalStartDate'] instanceof MongoDB\BSON\UTCDateTime) {
                $formattedStartDate = $rental['rentalStartDate']->toDateTime()->format('d M Y');
            } else {
                $formattedStartDate = date('d M Y', strtotime($rental['rentalStartDate']));
            }
        } else {
            $formattedStartDate = '[Start Date Not Specified]';
        }

        $mail->setFrom($_ENV['BUSINESS_EMAIL_ADDRESS'], 'The-WEB');
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
                The-WEB Team
            </p>
        ";

        try {
            $mail->send();
            echo 'Email sent successfully!';
        } catch (Exception $e) {
            echo 'Mailer Error: ' . $mail->ErrorInfo;
        }
    }

    public function sendRentalActionReminderEmail($user, $message)
    {
        $mail = $this->transporter;

        $mail->setFrom($_ENV['BUSINESS_EMAIL_ADDRESS'], 'The-WEB');
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
                The-WEB Team
            </p>
        ";

        try {
            $mail->send();
            echo 'Action reminder email sent successfully!';
        } catch (Exception $e) {
            echo 'Mailer Error: ' . $mail->ErrorInfo;
        }
    }

    public function sendLeaseSigningLink($to, $name, $link, $rentalId, $role = 't76y')
    {
        $mail = $this->transporter;

        $mail->setFrom($_ENV['BUSINESS_EMAIL_ADDRESS'], 'The-WEB');
        $mail->addAddress($to);
        $mail->Subject = 'Complete Your Lease Signing - The-WEB';

        $roleDisplay = ucfirst($role);

        $mail->Body = "
            <p>Dear {$name},</p>
            <p>Your lease agreement is ready for signing.</p>
            <p>
                Please click the button below to review and sign the lease agreement as <strong>{$roleDisplay}</strong>:
            </p>
            <p style='text-align: center;'>
                <a href='{$link}' style='display: inline-block; padding: 12px 30px; background-color: #1976d2; color: #fff; text-decoration: none; border-radius: 4px;'>
                    Sign Lease Agreement
                </a>
            </p>
            <p>
                If the button doesn't work, copy and paste this link into your browser:<br>
                <a href='{$link}'>{$link}</a>
            </p>
            " . ($rentalId ? "<p><strong>Rental ID:</strong> {$rentalId}</p>" : "") . "
            <p>
                For enquiries you can email us at <a href=\"mailto:" . $_ENV['BUSINESS_EMAIL_ADDRESS'] . "\">" . $_ENV['BUSINESS_EMAIL_ADDRESS'] . "</a>
            </p>
            <p>
                Best regards,<br>
                The-WEB Team
            </p>
        ";

        try {
            $mail->send();
            error_log("Lease signing link sent to: {$to} as {$role}");
            return true;
        } catch (Exception $e) {
            error_log('Mailer Error: ' . $mail->ErrorInfo);
            throw $e;
        }
    }

    public function sendRentalRejectionEmail($user, $message)
    {
        $mail = $this->transporter;

        $mail->setFrom($_ENV['BUSINESS_EMAIL_ADDRESS'], 'The-WEB');
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
                The-WEB Team
            </p>
        ";

        try {
            $mail->send();
            echo 'Rejection email sent successfully!';
        } catch (Exception $e) {
            echo 'Mailer Error: ' . $mail->ErrorInfo;
        }
    }

    public function sendExtendedDateEmail($user, $message)
    {
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

        $mail->setFrom($_ENV['BUSINESS_EMAIL_ADDRESS'], 'The-WEB');
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
                The-WEB Team
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
    public function sendVendorEmail($user, $callLog)
    {
        $mail = $this->transporter;

        // Format the request creation date
        $formattedStartDate = isset($callLog['createdAt'])
            ? date('d M Y', strtotime($callLog['createdAt']))
            : '[Start Date Not Specified]';

        $mail->setFrom($_ENV['BUSINESS_EMAIL_ADDRESS'], 'The-WEB');
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
                The-WEB Team
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