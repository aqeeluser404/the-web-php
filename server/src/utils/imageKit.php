<?php
require_once __DIR__ . '/../../../vendor/autoload.php';

use ImageKit\ImageKit;
use Slim\Psr7\UploadedFile;

class ImageKitService {
    private $imageKit;

    public function __construct() {
        $this->validateEnv();

        $this->imageKit = new ImageKit(
            $_ENV['IMAGEKIT_PUBLIC_KEY'],
            $_ENV['IMAGEKIT_PRIVATE_KEY'],
            $_ENV['IMAGEKIT_URL_ENDPOINT']
        );
    }

    private function validateEnv(): void {
        $required = ['IMAGEKIT_PUBLIC_KEY', 'IMAGEKIT_PRIVATE_KEY', 'IMAGEKIT_URL_ENDPOINT'];
        foreach ($required as $var) {
            if (empty($_ENV[$var])) {
                throw new RuntimeException("Missing required environment variable: $var");
            }
        }
    }

    public function uploadDocument(UploadedFile $file): array {
        try {
            // Validate the upload
            if ($file->getError() !== UPLOAD_ERR_OK) {
                throw new RuntimeException('File upload error occurred');
            }
    
            // Create temp file path
            $tempFilePath = sys_get_temp_dir() . '/' . uniqid('ik_');
            $file->moveTo($tempFilePath);
    
            // Upload to ImageKit
            $uploadResponse = $this->imageKit->upload([
                'file' => fopen($tempFilePath, 'r'),
                'fileName' => $file->getClientFilename(),
                'useUniqueFileName' => true
            ]);
    
            // Clean up temp file
            unlink($tempFilePath);
    
            // Check response structure
            if (!isset($uploadResponse->result)) {
                throw new RuntimeException('ImageKit upload failed - invalid response structure');
            }
    
            return [
                'documentUrl' => $uploadResponse->result->url,
                'fileId' => $uploadResponse->result->fileId,
                'fileName' => $file->getClientFilename()
            ];
            
        } catch (Exception $e) {
            if (isset($tempFilePath) && file_exists($tempFilePath)) {
                unlink($tempFilePath);
            }
            throw new RuntimeException('ImageKit upload failed: ' . $e->getMessage());
        }
    }

    public function deleteDocument(string $fileId): bool {
        try {
            $response = $this->imageKit->deleteFile($fileId);
            return true;
        } catch (Exception $e) {
            throw new RuntimeException('ImageKit delete failed: ' . $e->getMessage());
        }
    }

    public function uploadImage(UploadedFile $image): array {
        try {
            // Validate the upload
            if ($image->getError() !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Image upload error occurred');
            }
    
            // Create temp file path
            $tempFilePath = sys_get_temp_dir() . '/' . uniqid('ik_');
            $image->moveTo($tempFilePath);
    
            // Upload to ImageKit
            $uploadResponse = $this->imageKit->upload([
                'file' => fopen($tempFilePath, 'r'),
                'fileName' => $image->getClientFilename(),
                'useUniqueFileName' => true
            ]);
    
            // Clean up temp file
            unlink($tempFilePath);
    
            // Check response structure
            if (!isset($uploadResponse->result)) {
                throw new RuntimeException('ImageKit image upload failed - invalid response structure');
            }
    
            return [
                'imageUrl' => $uploadResponse->result->url,
                'fileId' => $uploadResponse->result->fileId,
                'fileName' => $image->getClientFilename()
            ];
            
        } catch (Exception $e) {
            if (isset($tempFilePath) && file_exists($tempFilePath)) {
                unlink($tempFilePath);
            }
            throw new RuntimeException('ImageKit image upload failed: ' . $e->getMessage());
        }
    }
    
    public function deleteImage(string $fileId): bool {
        try {
            $response = $this->imageKit->deleteFile($fileId);
            return true;
        } catch (Exception $e) {
            error_log("ImageKit deletion failed for $fileId: " . $e->getMessage());
            throw new RuntimeException('ImageKit image delete failed: ' . $e->getMessage());
        }
    }
}