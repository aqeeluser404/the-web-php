<?php
require_once __DIR__ . '/../../../vendor/autoload.php';

use Aws\Ses\SesClient;
use Aws\Exception\AwsException;

class AmazonBypassTransporter {
    private $client;

    public function __construct() {
        $this->client = new SesClient([
            'version' => 'latest',
            'region' => $_ENV['AWS_REGION'],
            'credentials' => [
                'key' => $_ENV['AWS_ACCESS_KEY_ID'],
                'secret' => $_ENV['AWS_SECRET_ACCESS_KEY'], 
            ]
        ]);
    }

    public function sendEmail($sourceEmail, $recipientEmail, $subject, $htmlBody) {
        try {
            $result = $this->client->sendEmail([
                'Source' => $sourceEmail, 
                'Destination' => [
                    'ToAddresses' => [$recipientEmail],
                ],
                'Message' => [
                    'Subject' => [
                        'Data' => $subject,
                    ],
                    'Body' => [
                        'Html' => [
                            'Data' => $htmlBody,
                        ]
                    ]
                ],
            ]);

            return [
                'status' => 'success',
                'messageId' => $result['MessageId'],
            ];
        } catch (AwsException $e) {
            return [
                'status' => 'error',
                'message' => $e->getAwsErrorMessage(),
            ];
        }
    }
}