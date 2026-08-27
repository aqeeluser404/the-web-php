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
            if ($file->getError() !== UPLOAD_ERR_OK) {
                throw new RuntimeException('File upload error occurred');
            }
    
            // Original filename
            $originalFilename = $file->getClientFilename();
            $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
            $filename = pathinfo($originalFilename, PATHINFO_FILENAME);
    
            // 1. Replace non-alphanum with space
            $normalized = preg_replace("/[^a-zA-Z0-9]/", " ", $filename);
    
            // 2. Capitalize words
            $capitalized = ucwords(strtolower($normalized));
    
            // 3. Replace spaces with underscores
            $finalName = str_replace(" ", "_", $capitalized);
            $finalName = $finalName ?: 'File'; // fallback if empty
    
            $fullFilename = $finalName . '.' . $extension;
    
            // Subdirectory logic
            $subdirectory = $type === 'images' ? 'images/' : 'documents/';
            $subdirectoryPath = $this->uploadBasePath . '/' . $subdirectory;
            if (!file_exists($subdirectoryPath)) {
                mkdir($subdirectoryPath, 0755, true);
            }
    
            // Prevent overwrite
            $relativePath = $subdirectory . $fullFilename;
            $fullPath = $this->uploadBasePath . '/' . $relativePath;
            $counter = 1;
            while (file_exists($fullPath)) {
                $fullFilename = $finalName . '_' . $counter . '.' . $extension;
                $relativePath = $subdirectory . $fullFilename;
                $fullPath = $this->uploadBasePath . '/' . $relativePath;
                $counter++;
            }
    
            // Move + permissions
            $file->moveTo($fullPath);
            chmod($fullPath, 0644);
    
            $actualPerms = substr(sprintf('%o', fileperms($fullPath)), -4);
            if ($actualPerms !== '0644') {
                error_log("Warning: File permissions not set correctly for $fullPath (got $actualPerms)");
            }
    
            return [
                ($type === 'images' ? 'imageUrl' : 'documentUrl') => $this->baseUrl . '/' . $relativePath,
                'fileId' => $relativePath,
                'fileName' => $fullFilename // Return formatted name
            ];
    
        } catch (Exception $e) {
            throw new RuntimeException('File upload failed: ' . $e->getMessage());
        }
    }
    
    // private function uploadFile(UploadedFile $file, string $type): array {
    //     try {
    //         // Validate the upload
    //         if ($file->getError() !== UPLOAD_ERR_OK) {
    //             throw new RuntimeException('File upload error occurred');
    //         }
    
    //         // Get original filename and extension
    //         $originalFilename = $file->getClientFilename();
    //         $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
    //         $filename = pathinfo($originalFilename, PATHINFO_FILENAME);
            
    //         // Sanitize the filename to remove special characters
    //         $sanitizedFilename = preg_replace("/[^a-zA-Z0-9\-_.]/", "", $filename);
    //         $sanitizedFilename = $sanitizedFilename ?: 'file'; // fallback if name becomes empty
            
    //         // Construct full filename with extension
    //         $fullFilename = $sanitizedFilename . '.' . $extension;
            
    //         $subdirectory = $type === 'images' ? 'images/' : 'documents/';
    //         $relativePath = $subdirectory . $fullFilename;
    //         $fullPath = $this->uploadBasePath . '/' . $relativePath;
    
    //         // Ensure subdirectory exists
    //         $subdirectoryPath = $this->uploadBasePath . '/' . $subdirectory;
    //         if (!file_exists($subdirectoryPath)) {
    //             mkdir($subdirectoryPath, 0755, true);
    //         }
    
    //         // Handle filename conflicts by adding a counter
    //         $counter = 1;
    //         while (file_exists($fullPath)) {
    //             $fullFilename = $sanitizedFilename . '_' . $counter . '.' . $extension;
    //             $relativePath = $subdirectory . $fullFilename;
    //             $fullPath = $this->uploadBasePath . '/' . $relativePath;
    //             $counter++;
    //         }
    
    //         // Move the file to permanent storage
    //         $file->moveTo($fullPath);
    
    //         // Set permissions
    //         chmod($fullPath, 0644);
            
    //         // Verify permissions
    //         $actualPerms = substr(sprintf('%o', fileperms($fullPath)), -4);
    //         if ($actualPerms != '0644') {
    //             error_log("Warning: File permissions not set correctly for $fullPath (got $actualPerms)");
    //         }
    
    //         return [
    //             ($type === 'images' ? 'imageUrl' : 'documentUrl') => $this->baseUrl . '/' . $relativePath,
    //             'fileId' => $relativePath,
    //             'fileName' => $originalFilename
    //         ];
            
    //     } catch (Exception $e) {
    //         throw new RuntimeException('File upload failed: ' . $e->getMessage());
    //     }
    // }
    
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


