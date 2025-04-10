<?php
namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use Slim\Psr7\Factory\StreamFactory;

class AdminAuthorizationMiddleware implements MiddlewareInterface {
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
        $user = $request->getAttribute('user');

        if (!$user) {
            $response = new Response();
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => 'Access Denied - User not authenticated'
            ]));
            return $response->withStatus(401)
                           ->withHeader('Content-Type', 'application/json');
        }

        // Check userType property exists and equals 'admin'
        if (!property_exists($user, 'userType') || $user->userType !== 'admin') {
            $response = new Response();
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => 'Admin access required'
            ]));
            return $response->withStatus(403)
                           ->withHeader('Content-Type', 'application/json');
        }
        return $handler->handle($request);
    }
}