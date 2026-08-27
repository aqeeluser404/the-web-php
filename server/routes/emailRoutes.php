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
    
    $app->post('/api/create-rental-application', [SendEmailCOntroller::class, 'rentalApplicationEmailController']);
    
    $app->post('/api/create-rental-to-user-application', [SendEmailCOntroller::class, 'rentalApplicationToUserEmailController']);

    $app->post('/api/document-upload-to-user-email', [SendEmailCOntroller::class, 'documentUploadToUserEmailController']);

    $app->post('/api/document-upload-email', [SendEmailCOntroller::class, 'documentUploadEmailController']);


    // NEW FUNCTION - ADD TO EXPRESS
    $app->post('/api/rental-action-reminder', [SendEmailCOntroller::class, 'sendRentalActionReminderController']);

    $app->post('/api/rejected-rental', [SendEmailCOntroller::class, 'sendRentalRejectionController']);

    $app->post('/api/extended-date/{id}', [SendEmailCOntroller::class, 'sendExtendedDateEmailController']);

    // NEW FUNCTION - ADD TO EXPRESS
    $app->post('/api/send-vendor-email', [SendEmailCOntroller::class, 'sendVendorController']);
    
    $app->post('/api/send-lease-link', [SendEmailCOntroller::class, 'sendLeaseLinkController']);
};