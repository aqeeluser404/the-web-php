<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/controllers/callLogController.php';

use Slim\App;
use App\Middleware\AuthenticationMiddleware;
use App\Middleware\AdminAuthorizationMiddleware;

return function (App $app) {

    // Public routes ----------------------------------------------------------------

    $app->post('/api/call-log', [CallLogController::class, 'createCallLogController']) // POST CREATE->CALL-LOG
        ->add(new AuthenticationMiddleware());

    $app->get('/api/call-log/{id}', [CallLogController::class, 'findCallLogByIdController']) // GET FIND->CALL-LOG->ID
        ->add(new AuthenticationMiddleware());

    $app->get('/api/users/{id}/call-logs', [CallLogController::class, 'findAllMyCallLogsController']) // GET FIND->ALL-MY->CALL-LOGS
        ->add(new AuthenticationMiddleware());

    $app->delete('/api/call-log/{id}', [CallLogController::class, 'deleteCallLogController']) // DELETE DELETE->CALL-LOG->ID
        ->add(new AuthenticationMiddleware());

    // Admin routes -----------------------------------------------------------------

    $app->get('/api/call-logs', [CallLogController::class, 'findAllCallLogsController']) // GET FIND->ALL->CALL-LOGS
        ->add(new AuthenticationMiddleware());

    $app->put('/api/call-log/{id}', [CallLogController::class, 'updateCallLogStatusController']) // PUT UPDATE->CALL-LOG->ID
        ->add(new AuthenticationMiddleware());

    $app->put('/api/call-log/{id}/delete-update', [CallLogController::class, 'deleteCallLogUpdateController'])
        ->add(new AuthenticationMiddleware());
};