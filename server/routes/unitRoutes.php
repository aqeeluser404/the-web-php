<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/controllers/unitController.php';

use Slim\App;
use App\Middleware\AuthenticationMiddleware;
use App\Middleware\AdminAuthorizationMiddleware;

return function (App $app) {

    // User routes ----------------------------------------------------------------------------------------------------

    $app->get('/api/units', [UnitController::class, 'findAllUnitsController']); // GET FIND->ALL->UNITS
    // ->add(new AuthenticationMiddleware());

    $app->get('/api/units/{id}', [UnitController::class, 'findUnitByIdController']) // GET FIND->UNIT->ID
    ->add(new AuthenticationMiddleware());

    // Admin routes ----------------------------------------------------------------------------------------------------

    $app->post('/api/admin/units', [UnitController::class, 'createUnitController']) // POST CREATE->UNIT
    ->add(new AdminAuthorizationMiddleware())
    ->add(new AuthenticationMiddleware());

    // $app->put('/api/admin/units/{id}', [UnitController::class, 'updateUnitController']) // PUT UPDATE->UNIT
    // ->add(new AdminAuthorizationMiddleware())
    // ->add(new AuthenticationMiddleware());

    // updated to post to fix multipart data to work in php
    $app->post('/api/admin/units/{id}', [UnitController::class, 'updateUnitController']) // PUT UPDATE->UNIT
    ->add(new AdminAuthorizationMiddleware())
    ->add(new AuthenticationMiddleware());

    $app->delete('/api/admin/units/{id}', [UnitController::class, 'deleteUnitController']) // DELETE DELETE->UNIT
    ->add(new AdminAuthorizationMiddleware())
    ->add(new AuthenticationMiddleware());

    // reserve
    $app->post('/api/units/reserve', [UnitController::class, 'reserveUnitController']);
    $app->post('/api/units/cancel-reservation', [UnitController::class, 'cancelReservationController']);
    $app->post('/api/units/rooms/reserve', [UnitController::class, 'reserveRoomController']);
    $app->post('/api/units/rooms/cancel-reservation', [UnitController::class, 'cancelReserveRoomController']);

};