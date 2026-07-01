<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/controllers/visitorController.php';

use Slim\App;
use App\Middleware\AuthenticationMiddleware;
use App\Middleware\AdminAuthorizationMiddleware;

return function (App $app) {

    // Public routes ----------------------------------------------------------------

    $app->post('/api/visitor', [VisitorController::class, 'createVisitorController']) 
        ->add(new AuthenticationMiddleware());

    $app->get('/api/visitor/{id}', [VisitorController::class, 'findVisitorByIdController'])
        ->add(new AuthenticationMiddleware());

    $app->get('/api/users/{id}/visitors', [VisitorController::class, 'findAllMyVisitorsController'])
        ->add(new AuthenticationMiddleware());

    $app->delete('/api/visitor/{id}', [VisitorController::class, 'deleteVisitorController'])
        ->add(new AuthenticationMiddleware());

    // Admin routes -----------------------------------------------------------------

    $app->get('/api/visitors', [VisitorController::class, 'findAllVisitorsController'])
        ->add(new AuthenticationMiddleware());

    $app->put('/api/visitor/{id}', [VisitorController::class, 'updateVisitorController'])
        ->add(new AuthenticationMiddleware());

};