<?php
namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use DateTime;
use Slim\Psr7\Factory\StreamFactory;

class MongoResponseMiddleware implements MiddlewareInterface {
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
        $response = $handler->handle($request);

        if (strpos($response->getHeaderLine('Content-Type'), 'application/json') === false) {
            return $response;
        }

        $rawBody = (string)$response->getBody();
        $cleanJson = preg_replace('/[^}]*$/', '', $rawBody);
        
        try {
            $data = json_decode($cleanJson, true, 512, JSON_THROW_ON_ERROR);
            $cleanedData = $this->stripMongoFormats($data);
            
            $streamFactory = new StreamFactory();
            return $response
                ->withBody($streamFactory->createStream(json_encode($cleanedData)))
                ->withHeader('Content-Type', 'application/json');
                
        } catch (\JsonException $e) {
            error_log('JSON error: ' . $e->getMessage());
            return $response;
        }
    }

    protected function stripMongoFormats($data) {
        if (is_array($data)) {
            
            // Convert $oid to plain string
            if (isset($data['$oid'])) {
                return (string)$data['$oid'];
            }
            
            // Convert $date to ISO string
            if (isset($data['$date']) && isset($data['$date']['$numberLong'])) {
                return $this->formatMongoDate((int)$data['$date']['$numberLong']);
            }
            
            // Recursively process arrays
            return array_map([$this, 'stripMongoFormats'], $data);
        }
        return $data;
    }

    protected function formatMongoDate(int $timestampMs): string {
        // Create DateTime object directly from milliseconds
        $dateTime = DateTime::createFromFormat('U.v', sprintf('%d.%03d', $timestampMs / 1000, $timestampMs % 1000));
        $dateTime->setTimezone(new \DateTimeZone('UTC'));
        
        // Format with milliseconds and timezone
        return $dateTime->format('Y-m-d\TH:i:s.vP');
    }
}