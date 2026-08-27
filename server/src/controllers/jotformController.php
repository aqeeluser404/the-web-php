<?php
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../database/db.php';
require_once __DIR__ . '/../utils/LocalFileHelper.php';

use Dotenv\Dotenv;
use Slim\Psr7\Request;
use Slim\Psr7\Response;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Firebase\JWT\JWT;
use Slim\Psr7\UploadedFile;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../../');
$dotenv->load();

class JotformController
{
    private $integrationKey;
    private $userId;
    private $accountId;
    private $privateKeyPath;
    private $authServer;
    private $baseUri;
    private $templateId;
    private $appBaseUrl;
    private $client;
    private $rentalCollection;
    private $localFileHelper;

    public function __construct()
    {
        $this->integrationKey = $_ENV['DOCUSIGN_INTEGRATION_KEY'] ?? null;
        $this->userId = $_ENV['DOCUSIGN_USER_ID'] ?? null;
        $this->accountId = $_ENV['DOCUSIGN_ACCOUNT_ID'] ?? null;
        $this->privateKeyPath = $_ENV['DOCUSIGN_PRIVATE_KEY_PATH'] ?? __DIR__ . '/../../../keys/docusign_private.key';
        $this->authServer = $_ENV['DOCUSIGN_AUTH_SERVER'] ?? 'account-d.docusign.com';
        $this->baseUri = rtrim($_ENV['DOCUSIGN_BASE_URI'] ?? '', '/');
        $this->templateId = $_ENV['DOCUSIGN_TEMPLATE_ID'] ?? null;
        $this->appBaseUrl = rtrim($_ENV['BACKEND_HOST_LINK'] ?? '', '/');
        $this->client = new Client(['timeout' => 30.0]);

        $db = Database::getDb();
        $this->rentalCollection = $db->Rental;
        $this->localFileHelper = new LocalFileHelper();
    }

    protected function respond(Response $response, $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }

    private function getAccessToken(): string
    {
        $privateKey = file_get_contents($this->privateKeyPath);

        $payload = [
            'iss' => $this->integrationKey,
            'sub' => $this->userId,
            'aud' => $this->authServer,
            'iat' => time(),
            'exp' => time() + 3600,
            'scope' => 'signature impersonation'
        ];

        $assertion = JWT::encode($payload, $privateKey, 'RS256');

        $response = $this->client->post("https://{$this->authServer}/oauth/token", [
            'form_params' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion
            ]
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        return $data['access_token'];
    }

    public function createSessionController(Request $req, Response $res): Response
    {
        try {
            $body = $req->getParsedBody();
            $tenant = $body['tenant'] ?? null;
            $rentalId = $body['rentalId'] ?? null;
            $agentEmail = $body['agentEmail'] ?? 'admin@the-web.co.za';
            $ownerEmail = $body['ownerEmail'] ?? 'aqeelhanslo@gmail.com';

            if (!$tenant || !$rentalId) {
                return $this->respond($res, ['success' => false, 'error' => 'Missing required fields: tenant and rentalId'], 400);
            }
            if (empty($tenant['email']) || empty($tenant['firstName']) || empty($tenant['lastName'])) {
                return $this->respond($res, ['success' => false, 'error' => 'Tenant must have email, firstName, and lastName'], 400);
            }

            $accessToken = $this->getAccessToken();
            $tenantName = $tenant['firstName'] . ' ' . $tenant['lastName'];
            $tenantClientUserId = (string) ($tenant['_id'] ?? 'tenant-' . $rentalId);
            $agentClientUserId = 'agent-' . $rentalId;
            $ownerClientUserId = 'owner-' . $rentalId;

            try {
                $envelopeResponse = $this->client->post(
                    "{$this->baseUri}/restapi/v2.1/accounts/{$this->accountId}/envelopes",
                    [
                        'headers' => [
                            'Authorization' => "Bearer {$accessToken}",
                            'Content-Type' => 'application/json'
                        ],
                        'json' => [
                            'templateId' => $this->templateId,
                            'templateRoles' => [
                                [
                                    'roleName' => 'Tenant',
                                    'recipientId' => '1',
                                    'routingOrder' => '1',
                                    'name' => $tenantName,
                                    'email' => $tenant['email'],
                                    'clientUserId' => $tenantClientUserId
                                ],
                                [
                                    'roleName' => 'Agent',
                                    'recipientId' => '2',
                                    'routingOrder' => '1',
                                    'name' => 'Agent',
                                    'email' => $agentEmail,
                                    'clientUserId' => $agentClientUserId
                                ],
                                [ 
                                    'roleName' => 'Owner',
                                    'recipientId' => '3',
                                    'routingOrder' => '1',
                                    'name' => 'Owner',
                                    'email' => $ownerEmail,
                                    'clientUserId' => $ownerClientUserId
                                ]
                            ],

                            // It's just another item inside this same array,
                            // sitting between templateRoles and status.
                            'customFields' => [
                                'textCustomFields' => [
                                    ['name' => 'rentalId', 'value' => $rentalId, 'show' => 'false'],
                                    ['name' => 'tenantId', 'value' => (string) ($tenant['_id'] ?? ''), 'show' => 'false']
                                ]
                            ],
                            'status' => 'sent'
                        ]
                    ]
                );
            } catch (GuzzleException $e) {
                return $this->respond($res, ['success' => false, 'error' => 'DocuSign envelope error: ' . $e->getMessage()], 500);
            }

            $envelopeData = json_decode($envelopeResponse->getBody()->getContents(), true);
            $envelopeId = $envelopeData['envelopeId'] ?? null;

            if (!$envelopeId) {
                return $this->respond($res, ['success' => false, 'error' => 'No envelope ID returned'], 500);
            }

            $tenantLink = "{$this->appBaseUrl}/jotform/sign-view"
                . "?envelopeId=" . urlencode($envelopeId)
                . "&rentalId=" . urlencode($rentalId)
                . "&role=tenant"
                . "&email=" . urlencode($tenant['email'])
                . "&name=" . urlencode($tenantName)
                . "&clientUserId=" . urlencode($tenantClientUserId);

            $agentLink = "{$this->appBaseUrl}/jotform/sign-view"
                . "?envelopeId=" . urlencode($envelopeId)
                . "&rentalId=" . urlencode($rentalId)
                . "&role=agent"
                . "&email=" . urlencode($agentEmail)
                . "&name=Agent"
                . "&clientUserId=" . urlencode($agentClientUserId);

            $ownerLink = "{$this->appBaseUrl}/jotform/sign-view" 
                . "?envelopeId=" . urlencode($envelopeId)
                . "&rentalId=" . urlencode($rentalId)
                . "&role=owner"
                . "&email=" . urlencode($ownerEmail)
                . "&name=Owner"
                . "&clientUserId=" . urlencode($ownerClientUserId);

            return $this->respond($res, [
                'success' => true,
                'tenantLink' => $tenantLink,
                'agentLink' => $agentLink,
                'ownerLink' => $ownerLink,
                'envelopeId' => $envelopeId,
                'tenantId' => $tenant['_id'] ?? null,
                'rentalId' => $rentalId
            ], 200);

        } catch (Exception $e) {
            return $this->respond($res, ['success' => false, 'error' => 'Server error: ' . $e->getMessage()], 500);
        }
    }

    public function signViewController(Request $req, Response $res): Response
    {
        $params = $req->getQueryParams();
        $envelopeId = $params['envelopeId'] ?? null;
        $rentalId = $params['rentalId'] ?? null;
        $role = $params['role'] ?? null;
        $email = $params['email'] ?? null;
        $name = $params['name'] ?? null;
        $clientUserId = $params['clientUserId'] ?? null;

        if (!$envelopeId || !$email || !$clientUserId) {
            $response = $res->withStatus(400);
            $response->getBody()->write('Missing required parameters.');
            return $response;
        }

        $accessToken = $this->getAccessToken();

        try {
            $viewResponse = $this->client->post(
                "{$this->baseUri}/restapi/v2.1/accounts/{$this->accountId}/envelopes/{$envelopeId}/views/recipient",
                [
                    'headers' => [
                        'Authorization' => "Bearer {$accessToken}",
                        'Content-Type' => 'application/json'
                    ],
                    'json' => [
                        'authenticationMethod' => 'none',
                        'email' => $email,
                        'userName' => $name,
                        'clientUserId' => $clientUserId,
                        'returnUrl' => "{$this->appBaseUrl}/lease-signed?rentalId={$rentalId}&role={$role}"
                    ]
                ]
            );

            $viewData = json_decode($viewResponse->getBody()->getContents(), true);
            return $res->withStatus(302)->withHeader('Location', $viewData['url']);

        } catch (GuzzleException $e) {
            $response = $res->withStatus(500);
            $response->getBody()->write('Could not open signing session: ' . $e->getMessage());
            return $response;
        }
    }

    public function leaseSignedController(Request $req, Response $res): Response {
        $params = $req->getQueryParams();
        $rentalId = $params['rentalId'] ?? null;
        $role = $params['role'] ?? null;

        // Redirect to your frontend success page
        $frontendUrl = $_ENV['HOST_LINK_0'] ?? 'https://www.the-web.co.za';
        $redirectUrl = "{$frontendUrl}/lease-signed?rentalId={$rentalId}&role={$role}";
    
        return $res->withStatus(302)->withHeader('Location', $redirectUrl);
    }

    // added as a third method in this same class,
    // right after signViewController, before the final closing brace of the class.
    public function docusignWebhookController(Request $req, Response $res): Response
    {
        $rawBody = (string) $req->getBody();

        $signatureHeader = $req->getHeaderLine('X-DocuSign-Signature-1');
        $expected = base64_encode(hash_hmac('sha256', $rawBody, $_ENV['DOCUSIGN_CONNECT_HMAC_KEY'], true));
        if (!$signatureHeader || !hash_equals($expected, $signatureHeader)) {
            return $res->withStatus(401);
        }

        $payload = json_decode($rawBody, true);
        error_log('DocuSign webhook payload: ' . $rawBody); // temporary, for checking real shape

        $envelopeId = $payload['data']['envelopeId'] ?? null;
        $status = $payload['data']['envelopeSummary']['status'] ?? null;
        $customFields = $payload['data']['envelopeSummary']['customFields']['textCustomFields'] ?? [];

        if ($status !== 'completed' || !$envelopeId) {
            return $res->withStatus(200);
        }

        $rentalId = null;
        foreach ($customFields as $field) {
            if (($field['name'] ?? '') === 'rentalId') {
                $rentalId = $field['value'];
            }
        }
        if (!$rentalId) {
            return $res->withStatus(200);
        }

        try {

            $rental = $this->rentalCollection->findOne(['_id' => new MongoDB\BSON\ObjectId($rentalId)]);
            if (!$rental) {
                error_log('Rental not found for ID: ' . $rentalId);
                return $res->withStatus(200);
            }

            $userId = (string) $rental['user'];
            $user = $this->userCollection->findOne(['_id' => new MongoDB\BSON\ObjectId($userId)]);
            if (!$user) {
                error_log('User not found for ID: ' . $userId);
                return $res->withStatus(200);
            }
            $firstName = $user['firstName'] ?? 'Unknown';
            $lastName = $user['lastName'] ?? 'User';
            $docType = 'Signed And Filled Lease Form';

            $fileName = $firstName . '_' . $lastName . '_' . $docType . '_document.pdf';
            $fileName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $fileName);

            $accessToken = $this->getAccessToken();

            $pdfResponse = $this->client->get(
                "{$this->baseUri}/restapi/v2.1/accounts/{$this->accountId}/envelopes/{$envelopeId}/documents/combined",
                [
                    'headers' => [
                        'Authorization' => "Bearer {$accessToken}",
                        'Accept' => 'application/pdf'
                    ]
                ]
            );
            $pdfBytes = $pdfResponse->getBody()->getContents();

            $tempPath = sys_get_temp_dir() . '/' . uniqid('lease_') . '.pdf';
            file_put_contents($tempPath, $pdfBytes);

            $uploadedFile = new UploadedFile(
                $tempPath,
                $fileName,
                'application/pdf',
                filesize($tempPath),
                UPLOAD_ERR_OK,
                false // not a real HTTP upload, so moveTo() uses rename() instead of move_uploaded_file()
            );

            $uploadResult = $this->localFileHelper->uploadDocument($uploadedFile);

            $this->rentalCollection->updateOne(
                ['_id' => new MongoDB\BSON\ObjectId($rentalId)],
                [
                    '$push' => [
                        'documents' => [
                            'documentUrl' => $uploadResult['documentUrl'],
                            'fileId' => $uploadResult['fileId'],
                            'uploadDate' => new MongoDB\BSON\UTCDateTime(),
                            'docType' => 'Signed And Filled Lease Form'
                        ]
                    ]
                ]
            );

            @unlink($tempPath); // harmless even if moveTo() already relocated it

            return $res->withStatus(200);

        } catch (Exception $e) {
            error_log('DocuSign webhook error: ' . $e->getMessage());
            return $res->withStatus(500);
        }
    }
}

// use Dotenv\Dotenv;
// use Slim\Psr7\Request;
// use Slim\Psr7\Response;
// use GuzzleHttp\Client;
// use GuzzleHttp\Exception\GuzzleException;
// use Firebase\JWT\JWT;

// $dotenv = Dotenv::createImmutable(__DIR__ . '/../../../');
// $dotenv->load();

// class JotformController
// {
//     private $integrationKey;
//     private $userId;
//     private $accountId;
//     private $privateKeyPath;
//     private $authServer;
//     private $baseUri;
//     private $templateId;
//     private $appBaseUrl;
//     private $client;

//     public function __construct()
//     {
//         $this->integrationKey = $_ENV['DOCUSIGN_INTEGRATION_KEY'] ?? null;
//         $this->userId = $_ENV['DOCUSIGN_USER_ID'] ?? null;
//         $this->accountId = $_ENV['DOCUSIGN_ACCOUNT_ID'] ?? null;
//         $this->privateKeyPath = $_ENV['DOCUSIGN_PRIVATE_KEY_PATH'] ?? __DIR__ . '/../../../keys/docusign_private.key';
//         $this->authServer = $_ENV['DOCUSIGN_AUTH_SERVER'] ?? 'account-d.docusign.com';
//         $this->baseUri = rtrim($_ENV['DOCUSIGN_BASE_URI'] ?? '', '/');
//         $this->templateId = $_ENV['DOCUSIGN_TEMPLATE_ID'] ?? null;
//         $this->appBaseUrl = rtrim($_ENV['BACKEND_HOST_LINK'] ?? '', '/');
//         $this->client = new Client(['timeout' => 30.0]);
//     }

//     protected function respond(Response $response, $data, int $status = 200): Response
//     {
//         $response->getBody()->write(json_encode($data));
//         return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
//     }

//     private function getAccessToken(): string
//     {
//         $privateKey = file_get_contents($this->privateKeyPath);

//         $payload = [
//             'iss' => $this->integrationKey,
//             'sub' => $this->userId,
//             'aud' => $this->authServer,
//             'iat' => time(),
//             'exp' => time() + 3600,
//             'scope' => 'signature impersonation'
//         ];

//         $assertion = JWT::encode($payload, $privateKey, 'RS256');

//         $response = $this->client->post("https://{$this->authServer}/oauth/token", [
//             'form_params' => [
//                 'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
//                 'assertion' => $assertion
//             ]
//         ]);

//         $data = json_decode($response->getBody()->getContents(), true);
//         return $data['access_token'];
//     }

//     public function createSessionController(Request $req, Response $res): Response
//     {
//         try {
//             $body = $req->getParsedBody();
//             $tenant = $body['tenant'] ?? null;
//             $rentalId = $body['rentalId'] ?? null;
//             $agentEmail = $body['agentEmail'] ?? 'agent@example.com';

//             if (!$tenant || !$rentalId) {
//                 return $this->respond($res, ['success' => false, 'error' => 'Missing required fields: tenant and rentalId'], 400);
//             }
//             if (empty($tenant['email']) || empty($tenant['firstName']) || empty($tenant['lastName'])) {
//                 return $this->respond($res, ['success' => false, 'error' => 'Tenant must have email, firstName, and lastName'], 400);
//             }

//             $accessToken = $this->getAccessToken();
//             $tenantName = $tenant['firstName'] . ' ' . $tenant['lastName'];
//             $tenantClientUserId = (string) ($tenant['_id'] ?? 'tenant-' . $rentalId);
//             $agentClientUserId = 'agent-' . $rentalId;

//             try {
//                 $envelopeResponse = $this->client->post(
//                     "{$this->baseUri}/restapi/v2.1/accounts/{$this->accountId}/envelopes",
//                     [
//                         'headers' => [
//                             'Authorization' => "Bearer {$accessToken}",
//                             'Content-Type' => 'application/json'
//                         ],
//                         'json' => [
//                             'templateId' => $this->templateId,
//                             // 'templateRoles' => [
//                             //     [
//                             //         'roleName' => 'Tenant',
//                             //         'recipientId' => '1',
//                             //         'routingOrder' => '1',
//                             //         'name' => $tenantName,
//                             //         'email' => $tenant['email'],
//                             //         'clientUserId' => $tenantClientUserId
//                             //     ],
//                             //     [
//                             //         'roleName' => 'Agent',
//                             //         'recipientId' => '2',
//                             //         'routingOrder' => '2',
//                             //         'name' => 'Agent',
//                             //         'email' => $agentEmail,
//                             //         'clientUserId' => $agentClientUserId
//                             //     ]
//                             // ],

//                             'templateRoles' => [
//                                 [
//                                     'roleName' => 'Tenant',
//                                     'recipientId' => '1',
//                                     'routingOrder' => '1',
//                                     'name' => $tenantName,
//                                     'email' => $tenant['email'],
//                                     'clientUserId' => $tenantClientUserId
//                                 ],
//                                 [
//                                     'roleName' => 'Agent',
//                                     'recipientId' => '2',
//                                     'routingOrder' => '1',   // ← changed from '2' to '1'
//                                     'name' => 'Agent',
//                                     'email' => $agentEmail,
//                                     'clientUserId' => $agentClientUserId
//                                 ]
//                             ],
//                             'status' => 'sent'
//                         ]
//                     ]
//                 );
//             } catch (GuzzleException $e) {
//                 return $this->respond($res, ['success' => false, 'error' => 'DocuSign envelope error: ' . $e->getMessage()], 500);
//             }

//             $envelopeData = json_decode($envelopeResponse->getBody()->getContents(), true);
//             $envelopeId = $envelopeData['envelopeId'] ?? null;

//             if (!$envelopeId) {
//                 return $this->respond($res, ['success' => false, 'error' => 'No envelope ID returned'], 500);
//             }

//             // Everything sign-view needs is passed straight in the URL —
//             // no DB table required to get this working today.
//             $tenantLink = "{$this->appBaseUrl}/jotform/sign-view"
//                 . "?envelopeId=" . urlencode($envelopeId)
//                 . "&rentalId=" . urlencode($rentalId)
//                 . "&role=tenant"
//                 . "&email=" . urlencode($tenant['email'])
//                 . "&name=" . urlencode($tenantName)
//                 . "&clientUserId=" . urlencode($tenantClientUserId);

//             $agentLink = "{$this->appBaseUrl}/jotform/sign-view"
//                 . "?envelopeId=" . urlencode($envelopeId)
//                 . "&rentalId=" . urlencode($rentalId)
//                 . "&role=agent"
//                 . "&email=" . urlencode($agentEmail)
//                 . "&name=Agent"
//                 . "&clientUserId=" . urlencode($agentClientUserId);

//             return $this->respond($res, [
//                 'success' => true,
//                 'tenantLink' => $tenantLink,
//                 'agentLink' => $agentLink,
//                 'envelopeId' => $envelopeId,
//                 'tenantId' => $tenant['_id'] ?? null,
//                 'rentalId' => $rentalId
//             ], 200);

//         } catch (Exception $e) {
//             return $this->respond($res, ['success' => false, 'error' => 'Server error: ' . $e->getMessage()], 500);
//         }
//     }

//     public function signViewController(Request $req, Response $res): Response
//     {
//         $params = $req->getQueryParams();
//         $envelopeId = $params['envelopeId'] ?? null;
//         $rentalId = $params['rentalId'] ?? null;
//         $role = $params['role'] ?? null;
//         $email = $params['email'] ?? null;
//         $name = $params['name'] ?? null;
//         $clientUserId = $params['clientUserId'] ?? null;

//         if (!$envelopeId || !$email || !$clientUserId) {
//             $response = $res->withStatus(400);
//             $response->getBody()->write('Missing required parameters.');
//             return $response;
//         }

//         $accessToken = $this->getAccessToken();

//         try {
//             $viewResponse = $this->client->post(
//                 "{$this->baseUri}/restapi/v2.1/accounts/{$this->accountId}/envelopes/{$envelopeId}/views/recipient",
//                 [
//                     'headers' => [
//                         'Authorization' => "Bearer {$accessToken}",
//                         'Content-Type' => 'application/json'
//                     ],
//                     'json' => [
//                         'authenticationMethod' => 'none',
//                         'email' => $email,
//                         'userName' => $name,
//                         'clientUserId' => $clientUserId,
//                         'returnUrl' => "{$this->appBaseUrl}/lease-signed?rentalId={$rentalId}&role={$role}"
//                     ]
//                 ]
//             );

//             $viewData = json_decode($viewResponse->getBody()->getContents(), true);
//             return $res->withStatus(302)->withHeader('Location', $viewData['url']);

//         } catch (GuzzleException $e) {
//             $response = $res->withStatus(500);
//             $response->getBody()->write('Could not open signing session: ' . $e->getMessage());
//             return $response;
//         }
//     }
// }