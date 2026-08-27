<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/controllers/userController.php';

use Slim\App;
use App\Middleware\AuthenticationMiddleware;
use App\Middleware\AdminAuthorizationMiddleware;

return function (App $app) {

    // User profile routes -----------------------------------------------

    $app->get('/api/users/profile', [UserController::class, 'findUserByTokenController']) // GET FIND->USER->TOKEN
    ->add(new AuthenticationMiddleware());
    
    $app->get('/api/users/{id}', [UserController::class, 'findUserByIdController']) // GET FIND->USER->ID
    ->add(new AuthenticationMiddleware());
    
    $app->put('/api/users/{id}', [UserController::class, 'updateUserController']) // PUT UPDATE->USER->ID
    ->add(new AuthenticationMiddleware());

    // Authentication routes --------------------------------------------

    $app->post('/api/auth/login', [UserController::class, 'userLoginController']); // POST LOGIN

    $app->post('/api/auth/admin-login', [UserController::class, 'adminLoginController']);

    // $app->post('/api/auth/verify-otp', [UserController::class, 'validateOtpController']); // POST OTP

    $app->post('/api/auth/register', [UserController::class, 'userRegisterController']); // POST REGISTER
    
    $app->post('/api/auth/logout/{id}', [UserController::class, 'userLogoutController']); // POST LOGOUT->ID

    // Document routes --------------------------------------------------

    $app->post('/api/users/{id}/documents', [UserController::class, 'uploadUserDocsController']) // POST CREATE->DOCUMENT
    ->add(new AuthenticationMiddleware());

    $app->delete('/api/users/{id}/documents', [UserController::class, 'clearAllUserDocsController']) // DELETE DELETE->DOCUMENT
    ->add(new AuthenticationMiddleware());
    
    $app->delete('/api/users/{id}/documents/{doc}', [UserController::class, 'removeUserDocController']) // DELETE DELETE->ALL->DOCUMENTS
    ->add(new AuthenticationMiddleware());

    // Admin routes ----------------------------------------------------------------------------------------------------

    // Create a new user
    $app->post('/api/admin/users', [UserController::class, 'createUserController']) // POST CREATE->USER
    ->add(new AdminAuthorizationMiddleware())
    ->add(new AuthenticationMiddleware());
    
    // Get all users
    $app->get('/api/admin/users', [UserController::class, 'findAllUsersController']) // GET FIND->ALL->USERS
    ->add(new AdminAuthorizationMiddleware())
    ->add(new AuthenticationMiddleware());
    
    $app->post('/api/admin/users/batch', [UserController::class, 'findUsersByIdsController'])
    ->add(new AdminAuthorizationMiddleware())
    ->add(new AuthenticationMiddleware());

    // Get logged-in users
    $app->get('/api/admin/users/logged-in', [UserController::class, 'findUsersLoggedInController']) // GET FIND->LOGGED-IN->USERS
    ->add(new AdminAuthorizationMiddleware())
    ->add(new AuthenticationMiddleware());

    // Get frequent users
    $app->get('/api/admin/users/frequent', [UserController::class, 'findUsersFrequentlyLoggedInController']) // GET FIND->FREQUENT->USERS
    ->add(new AdminAuthorizationMiddleware())
    ->add(new AuthenticationMiddleware());

    // Delete user by identifier (id)
    $app->delete('/api/admin/users/{id}', [UserController::class, 'deleteUserController']) // DELETE DELETE->USER->ID
    ->add(new AdminAuthorizationMiddleware())
    ->add(new AuthenticationMiddleware());
    
    // For docs ----------------------------------------------------------------------------------------------------

    $app->get('/api/docs/users/{id}', [UserController::class, 'findUserByIdController']); // GET FIND->USER->ID
};