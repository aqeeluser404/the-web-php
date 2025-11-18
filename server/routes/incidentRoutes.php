<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/controllers/IncidentController.php';

use Slim\App;
use App\Middleware\AuthenticationMiddleware;
use App\Middleware\AdminAuthorizationMiddleware;

return function (App $app) {

    // Public routes ----------------------------------------------------------------

    $app->post('/api/incident', [IncidentController::class, 'createIncidentController']);

    // Admin routes -----------------------------------------------------------------

    $app->get('/api/incident/{id}', [IncidentController::class, 'findIncidentByIdController']) // GET FIND->CALL-LOG->ID
        ->add(new AdminAuthorizationMiddleware())
        ->add(new AuthenticationMiddleware());

    $app->delete('/api/incident/{id}', [IncidentController::class, 'deleteIncidentController']) // DELETE DELETE->CALL-LOG->ID
        ->add(new AdminAuthorizationMiddleware())
        ->add(new AuthenticationMiddleware());

    $app->get('/api/incident', [IncidentController::class, 'findAllIncidentsController']) // GET FIND->ALL->CALL-LOGS
        ->add(new AdminAuthorizationMiddleware())
        ->add(new AuthenticationMiddleware());

    $app->put('/api/incident/{id}', [IncidentController::class, 'updateIncidentController']) // PUT UPDATE->CALL-LOG->ID
        ->add(new AdminAuthorizationMiddleware())
        ->add(new AuthenticationMiddleware());
};
