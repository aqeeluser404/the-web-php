<?php
require_once __DIR__ . '/../../../vendor/autoload.php';

use Dotenv\Dotenv;
use Slim\Psr7\UploadedFile;

class localFileHelper {
    private $uploadBasePath;
    private $baseUrl;

    public function __construct() {
        $envPath = __DIR__ . '/../../../';
        if (file_exists($envPath . '.env')) {
            $dotenv = Dotenv::createImmutable($envPath);
            $dotenv->load();
        }

        $this->uploadBasePath = $_SERVER['DOCUMENT_ROOT'] . '/backend/server/uploads';
        $this->baseUrl = $_ENV['FILE_STORAGE_URL'] ?? 'https://the-web.co.za/backend/server/uploads';

        if (!file_exists($this->uploadBasePath)) {
            mkdir($this->uploadBasePath, 0755, true);
        }
    }

    public function uploadDocument(UploadedFile $file): array {
        return $this->uploadFile($file, 'documents');
    }

    public function deleteDocument(string $fileId): bool {
        return $this->deleteFile($fileId);
    }

    public function uploadImage(UploadedFile $image): array {
        return $this->uploadFile($image, 'images');
    }
    
    public function deleteImage(string $fileId): bool {
        return $this->deleteFile($fileId);
    }

    private function uploadFile(UploadedFile $file, string $type): array {
        try {
            // Validate the upload
            if ($file->getError() !== UPLOAD_ERR_OK) {
                throw new RuntimeException('File upload error occurred');
            }

            // Generate unique filename
            $extension = pathinfo($file->getClientFilename(), PATHINFO_EXTENSION);
            $filename = uniqid('file_') . '.' . $extension;
            $subdirectory = $type === 'images' ? 'images/' : 'documents/';
            $relativePath = $subdirectory . $filename;
            $fullPath = $this->uploadBasePath . '/' . $relativePath;

            // Ensure subdirectory exists
            $subdirectoryPath = $this->uploadBasePath . '/' . $subdirectory;
            if (!file_exists($subdirectoryPath)) {
                mkdir($subdirectoryPath, 0755, true);
            }

            // Move the file to permanent storage
            $file->moveTo($fullPath);

            // Explicitly set permissions (0644 for files)
            chmod($fullPath, 0644);
            
            // Verify permissions were set correctly
            $actualPerms = substr(sprintf('%o', fileperms($fullPath)), -4);
            if ($actualPerms != '0644') {
                error_log("Warning: File permissions not set correctly for $fullPath (got $actualPerms)");
            }

            // Return the same structure as ImageKit for compatibility
            return [
                ($type === 'images' ? 'imageUrl' : 'documentUrl') => $this->baseUrl . '/' . $relativePath,
                'fileId' => $relativePath, // Using relative path as fileId for deletion
                'fileName' => $file->getClientFilename()
            ];
            
        } catch (Exception $e) {
            throw new RuntimeException('File upload failed: ' . $e->getMessage());
        }
    }

    private function deleteFile(string $fileId): bool {
        try {
            $fullPath = $this->uploadBasePath . '/' . $fileId;
            
            if (!file_exists($fullPath)) {
                return true; // Already deleted
            }

            if (!unlink($fullPath)) {
                throw new RuntimeException('Could not delete file');
            }

            return true;
        } catch (Exception $e) {
            error_log("File deletion failed for $fileId: " . $e->getMessage());
            throw new RuntimeException('File delete failed: ' . $e->getMessage());
        }
    }
}


