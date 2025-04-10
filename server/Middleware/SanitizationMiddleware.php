<?php
namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\StreamFactory;

class SanitizationMiddleware implements MiddlewareInterface {
    public function process(
        ServerRequestInterface $request, 
        RequestHandlerInterface $handler
    ): ResponseInterface {
        $response = $handler->handle($request);
        
        // Only process JSON responses
        if (strpos($response->getHeaderLine('Content-Type'), 'application/json') === false) {
            return $response;
        }
        
        $body = (string)$response->getBody();
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $response;
        }
        
        // Apply sanitization
        $sanitizedData = $this->sanitizeResponse($data);
        
        // Return new response with sanitized data
        return $response
            ->withBody((new StreamFactory())->createStream(json_encode($sanitizedData)));
    }
    
    protected function sanitizeResponse($data) {
        if (is_array($data)) {
            // Handle user data
            if (isset($data['password'])) {
                unset($data['password']);
            }
            
            // Handle arrays of users
            if (isset($data[0]['password'])) {
                foreach ($data as &$item) {
                    unset($item['password']);
                }
            }
            
            // Recursively sanitize nested data
            return array_map([$this, 'sanitizeResponse'], $data);
        }
        
        // Handle User objects that implement JsonSerializable
        if (is_object($data) && method_exists($data, 'toSafeArray')) {
            return $data->toSafeArray();
        }
        
        return $data;
    }
}