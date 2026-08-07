<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/controllers/jotformController.php';

use Slim\App;

return function (App $app) {
    // $app->post('/api/jotform/create-session', [JotformController::class, 'createSessionController']);

    $app->post('/api/jotform/create-session', [JotformController::class, 'createSessionController']);
    $app->get('/api/jotform/sign-view', [JotformController::class, 'signViewController']);
    $app->post('/api/jotform/docusign-webhook', [JotformController::class, 'docusignWebhookController']);
    $app->get('/api/lease-signed', [JotformController::class, 'leaseSignedController']);
};