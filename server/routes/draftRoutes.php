<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/controllers/applicationDraftController.php';

use Slim\App;
use App\Middleware\AuthenticationMiddleware;
use App\Middleware\AdminAuthorizationMiddleware;

return function (App $app) {

    $app->post('/api/application/draft', [ApplicationDraftController::class, 'saveApplicationDraftController']);

    $app->get('/api/application/draft/{id}', [ApplicationDraftController::class, 'getApplicationDraftController']);
    
    $app->get('/api/application/drafts', [ApplicationDraftController::class, 'getAllDraftsController']);

    $app->delete('/api/rentals/application-draft/{id}', [ApplicationDraftController::class, 'deleteApplicationDraftController']);

    // $app->post('/api/application/draft', [RentalController::class, 'saveRentalDocsDraftController']);

    // $app->get('/api/application/draft/{id}', [RentalController::class, 'getRentalDocsDraftController']);
};