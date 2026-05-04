<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/controllers/rentalController.php';

use Slim\App;
use App\Middleware\AuthenticationMiddleware;
use App\Middleware\AdminAuthorizationMiddleware;

return function (App $app) {

    // Public routes -----------------------------------------------

    $app->post('/api/rentals', [RentalController::class, 'createRentalController']) // POST CREATE->RENTAL
    ->add(new AuthenticationMiddleware());
    
    $app->post('/api/sync/rentals', [RentalController::class, 'syncRentalController']) // POST SYNC RENTALS
    ->add(new AuthenticationMiddleware());

    $app->get('/api/rentals/{id}', [RentalController::class, 'findRentalByIdController']) // GET FIND->RENTAL->ID
    ->add(new AuthenticationMiddleware());

    $app->get('/api/users/{id}/rentals', [RentalController::class, 'findAllMyRentalsController']) // GET FINDALL->RENTALS->USER
    ->add(new AuthenticationMiddleware());

    $app->delete('/api/rentals/{id}', [RentalController::class, 'deleteRentalController']) // DELETE DELETE->RENTAL->ID
    ->add(new AuthenticationMiddleware());

    // Protected routes -----------------------------------------------

    $app->get('/api/admin/rentals', [RentalController::class, 'findAllRentalsController']) // GET FINDALL->RENTALS
    ->add(new AdminAuthorizationMiddleware())
    ->add(new AuthenticationMiddleware());

    $app->put('/api/admin/rentals/{id}', [RentalController::class, 'updateRentalController']) // PUT UPDATE->RENTAL->ID
    ->add(new AdminAuthorizationMiddleware())
    ->add(new AuthenticationMiddleware());

    $app->put('/api/admin/rentals/reassign/unit', [RentalController::class, 'reassignUnitController']) // PUT REASSIGN UNIT 
    ->add(new AdminAuthorizationMiddleware())
    ->add(new AuthenticationMiddleware());

    $app->put('/api/rentals/{id}/payer', [RentalController::class, 'verifyAndSavePayerController']) // PUT UPDATE->RENTAL->ID
    ->add(new AuthenticationMiddleware());

    $app->put('/api/admin/rentals/{id}/end', [RentalController::class, 'earlyEndRentalController']) // PUT UPDATE->RENTAL->ID
    ->add(new AdminAuthorizationMiddleware())
    ->add(new AuthenticationMiddleware());
};