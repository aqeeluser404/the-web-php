<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/controllers/shuttleController.php';

use Slim\App;
use App\Middleware\AuthenticationMiddleware;
use App\Middleware\AdminAuthorizationMiddleware;

return function (App $app) {

    // Public routes ----------------------------------------------------------------

    $app->post('/api/shuttle', [ShuttleController::class, 'createShuttleController']) 
        ->add(new AuthenticationMiddleware());

    $app->get('/api/shuttle/{id}', [ShuttleController::class, 'findShuttleByIdController'])
        ->add(new AuthenticationMiddleware());

    $app->get('/api/users/{id}/shuttles', [ShuttleController::class, 'findAllMyShuttlesController'])
        ->add(new AuthenticationMiddleware());

    $app->delete('/api/shuttle/{id}', [ShuttleController::class, 'deleteShuttleController'])
        ->add(new AuthenticationMiddleware());

    // Admin routes -----------------------------------------------------------------

    $app->get('/api/shuttles', [ShuttleController::class, 'findAllShuttlesController'])
        ->add(new AuthenticationMiddleware());

    $app->put('/api/shuttle/{id}', [ShuttleController::class, 'updateShuttleController'])
        ->add(new AuthenticationMiddleware());

};