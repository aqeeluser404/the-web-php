<?php
require_once __DIR__ . '/../../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class MailTransport {
    public static function createMailTransporter($config) {
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            if (isset($config['service'])) {
                $mail->Host = $config['service'];
            } else {
                $mail->Host = $config['host'];
                $mail->Port = $config['port'];
                $mail->SMTPSecure = $config['secure'] ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            }
            $mail->SMTPAuth = true;
            $mail->Username = $config['auth']['user'];
            $mail->Password = $config['auth']['pass'];

            $mail->SMTPDebug = 0;
            $mail->isHTML(true);

            return $mail;
        } catch (Exception $e) {
            error_log('MailTransporter Error: ' . $e->getMessage());
            throw $e;
        }
    }

    public static function createMailTransporterWrapper() {
        $emailService = $_ENV['EMAIL_SERVICE'] ?? 'custom'; // Default to custom if not specified
        
        $configs = [
            'gmail' => [
                'service' => 'smtp.gmail.com',
                'auth' => [
                    'user' => $_ENV['HOST_EMAIL_ADDRESS'],
                    'pass' => $_ENV['HOST_EMAIL_PASSWORD']
                ]
            ],
            'outlook' => [
                'service' => 'smtp.office365.com',
                'auth' => [
                    'user' => $_ENV['BUSINESS_EMAIL_ADDRESS'], // Using business email for Outlook
                    'pass' => $_ENV['HOST_EMAIL_PASSWORD']
                ],
                'port' => 587,
                'secure' => false // Outlook requires STARTTLS
            ],
            'custom' => [
                'host' => 'mail.the-web.co.za',
                'port' => 465,
                'secure' => true,
                'auth' => [
                    'user' => $_ENV['HOST_EMAIL_ADDRESS'],
                    'pass' => $_ENV['HOST_EMAIL_PASSWORD']
                ]
            ]
        ];

        if (!array_key_exists($emailService, $configs)) {
            throw new Exception("Unsupported email service: {$emailService}");
        }

        return self::createMailTransporter($configs[$emailService]);
    }
}