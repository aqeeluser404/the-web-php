<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/controllers/exportController.php';

use Slim\App;
use App\Middleware\AuthenticationMiddleware;
use App\Middleware\AdminAuthorizationMiddleware;

return function (App $app) {
    $app->get('/api/admin/export-data', [exportController::class, 'exportAllCollections']) 
    ->add(new AdminAuthorizationMiddleware())
    ->add(new AuthenticationMiddleware());
};