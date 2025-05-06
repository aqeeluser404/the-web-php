<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/src/controllers/callLogController.php';
require_once __DIR__ . '/src/models/unitModel.php';
require_once __DIR__ . '/src/models/rentalModel.php';
require_once __DIR__ . '/src/services/rentalService.php';

use Slim\Psr7\Response;
use Slim\Factory\AppFactory;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Dotenv\Dotenv;
use App\Middleware\MongoResponseMiddleware;
use App\Middleware\SanitizationMiddleware;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$app = AppFactory::create();

// middleware ------------------------------------------------------------------

$app->addBodyParsingMiddleware();
$app->add(new SanitizationMiddleware());
$app->add(new MongoResponseMiddleware());

// cpanel setup ----------------------------------------------------------------

// Set the base path use for cpanel hosting
$app->setBasePath('/backend/server');

// cd into server before serving this
// $app->setBasePath('');

// cors setup -----------------------------------------------------------------

$allowedOrigins = [$_ENV['HOST_LINK']];
$app->add(function ($request, $handler) use ($allowedOrigins) {

    if ($request->getMethod() === 'OPTIONS') {
        $response = new Response();
        $origin = $request->getHeaderLine('Origin');

        if (in_array($origin, $allowedOrigins) || empty($origin)) {
            return $response
                ->withHeader('Access-Control-Allow-Origin', $origin) // Dynamic origin
                ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With')
                ->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        $response = new Response();
        $response->getBody()->write('Not allowed by CORS');
        return $response
            ->withStatus(403)
            ->withHeader('Content-Type', 'text/plain');
    }

    $response = $handler->handle($request);
    $origin = $request->getHeaderLine('Origin');

    if (in_array($origin, $allowedOrigins) || empty($origin)) {
        return $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Credentials', 'true');
    }

    $response = new Response();
    $response->getBody()->write('Not allowed by CORS');
    return $response
        ->withStatus(403)
        ->withHeader('Content-Type', 'text/plain');
});

// token and authentication routes ----------------------------------------------------------------

$app->get('/api', function ($request, $response, $args) {
    $response->getBody()->write('Backend is running');
    return $response;
});

$app->get('/api/health', function ($request, $response, $args) {
    $data = ['status' => 'UP'];
    $response->getBody()->write(json_encode($data));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/api/check-token', function ($request, $response, $args) {
    $token = $request->getCookieParams()['token'] ?? null;
    $data = ['exists' => $token ? true : false];
    $response->getBody()->write(json_encode($data));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/api/get-token', function ($request, $response, $args) {
    $token = $request->getCookieParams()['token'] ?? null;
    if (!$token) {
        $data = ['message' => 'No token found in cookie'];
        $response->getBody()->write(json_encode($data));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }
    try {
        $decoded = JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));
        $data = ['token' => $token];
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    } catch (Exception $e) {
        $data = ['message' => 'Invalid or expired token'];
        $response->getBody()->write(json_encode($data));
        return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
    }
});

$app->post('/api/remove-token', function ($request, $response, $args) {
    $isProduction = $_ENV['NODE_ENV'] === 'production';
    setcookie('token', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => $isProduction,
        'httponly' => true,
        'samesite' => 'None'
    ]);
    $data = ['message' => 'Token removed'];
    $response->getBody()->write(json_encode($data));
    return $response->withHeader('Content-Type', 'application/json');
});

// Routes ----------------------------------------------------------------

$userRoutes = require_once __DIR__ . '/routes/userRoutes.php';
$userRoutes($app);

$callLogRoutes = require_once __DIR__ . '/routes/callLogRoutes.php';
$callLogRoutes($app);

$unitRoutes = require_once __DIR__ . '/routes/unitRoutes.php';
$unitRoutes($app);

$rentalRoutes = require_once __DIR__ . '/routes/rentalRoutes.php';
$rentalRoutes($app);

$emailRoutes = require_once __DIR__ . '/routes/emailRoutes.php';
$emailRoutes($app);

$app->addErrorMiddleware(true, true, true);
$app->run();