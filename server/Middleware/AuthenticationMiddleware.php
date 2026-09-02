<?php
// src/Middleware/AuthenticationMiddleware.php

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Slim\Psr7\Response;
use Slim\Psr7\Factory\StreamFactory;

class AuthenticationMiddleware implements MiddlewareInterface {
    public function __construct() {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
        $token = $request->getCookieParams()['token'] ?? null;
    
        if (!$token) {
            $authHeader = $request->getHeaderLine('Authorization');
            
            if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
                $token = $matches[1];
            }
        }
    
        if (!$token) {
            $response = new Response();
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => 'Access Denied - Token not provided'
            ]));
            return $response->withStatus(401)
                           ->withHeader('Content-Type', 'application/json');
        }
    
        try {
            $decoded = JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));
            $request = $request->withAttribute('user', $decoded);
            $testUser = $request->getAttribute('user');

            return $handler->handle($request);
        } catch (\Firebase\JWT\ExpiredException $e) {
            $response = new Response();
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => 'Token expired'
            ]));
            return $response->withStatus(401)
                           ->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response = new Response();
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => 'Invalid Token: ' . $e->getMessage()
            ]));
            return $response->withStatus(401)
                           ->withHeader('Content-Type', 'application/json');
        }
    }
}