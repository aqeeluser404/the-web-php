<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/controllers/sendEmailController.php';

use Slim\App;

return function (App $app) {

    $app->get('/api/verify-email', [SendEmailCOntroller::class, 'verifyEmailController']);

    $app->post('/api/resend-verification-email', [SendEmailCOntroller::class, 'resendVerificationEmailController']);

    $app->post('/api/forgot-password', [SendEmailCOntroller::class, 'forgotPasswordController']);

    $app->post('/api/reset-password', [SendEmailCOntroller::class, 'resetPasswordController']);

    $app->post('/api/contact', [SendEmailCOntroller::class, 'getInContactController']);

    $app->post('/api/user-request/{id}', [SendEmailCOntroller::class, 'sendUserRequestController']);

    $app->post('/api/approved-rental', [SendEmailCOntroller::class, 'rentalNotifcationController']);

    $app->post('/api/rejected-rental', [SendEmailCOntroller::class, 'sendRentalRejectionController']);

    $app->post('/api/extended-date/{id}', [SendEmailCOntroller::class, 'sendExtendedDateEmailController']);
};