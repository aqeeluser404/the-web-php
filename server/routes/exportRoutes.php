<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/controllers/exportController.php';

use Slim\App;
use App\Middleware\AuthenticationMiddleware;
use App\Middleware\AdminAuthorizationMiddleware;

return function (App $app) {
    $app->get('/api/admin/export-calllog-data', [exportController::class, 'exportCalllogsCollection']) 
    ->add(new AdminAuthorizationMiddleware())
    ->add(new AuthenticationMiddleware());

    $app->get('/api/admin/export-user-data', [exportController::class, 'exportUserCollection']) 
    ->add(new AdminAuthorizationMiddleware())
    ->add(new AuthenticationMiddleware());

    $app->get('/api/admin/export-unit-data', [exportController::class, 'exportUnitCollection']) 
    ->add(new AdminAuthorizationMiddleware())
    ->add(new AuthenticationMiddleware());

    $app->get('/api/admin/export-rental-data', [exportController::class, 'exportRentalCollection']) 
    ->add(new AdminAuthorizationMiddleware())
    ->add(new AuthenticationMiddleware());

    $app->get('/api/admin/export-data', [exportController::class, 'exportAllCollections']) 
    ->add(new AdminAuthorizationMiddleware())
    ->add(new AuthenticationMiddleware());
};