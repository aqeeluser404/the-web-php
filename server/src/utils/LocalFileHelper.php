<?php
// require_once __DIR__ . '/../../../vendor/autoload.php';

// use Psr\Http\Message\UploadedFileInterface;

// class LocalFileHelper {
//     private $uploadPath;

//     public function __construct() {
//         // Set path to: /home/thewebco/public_html/backend/uploads
//         $this->uploadPath = __DIR__ . '/../../../../public_html/backend/uploads';
//         if (!file_exists($this->uploadPath)) {
//             mkdir($this->uploadPath, 0755, true);
//         }
//     }

//     /**
//      * Uploads a document to local storage
//      * @param array $file File data from Slim's getUploadedFiles()
//      * @return array ['documentUrl' => string, 'fileId' => string]
//      */
//     public function uploadDocument(UploadedFileInterface $file): array {

//         if ($file->getError() !== UPLOAD_ERR_OK) {
//             throw new RuntimeException('Upload error: ' . $file->getError());
//         }


//         // Generate unique filename
//         $extension = pathinfo($file->getClientFilename(), PATHINFO_EXTENSION);
//         $filename = uniqid('doc_') . '.' . $extension;
//         $filePath = $this->uploadPath . '/' . $filename;
    
//         // Move uploaded file
//         $file->moveTo($filePath);
    
//         return [
//             'documentUrl' => '/backend/uploads/' . $filename,
//             'fileId' => $filename
//         ];
//     }

//     /**
//      * Deletes a document from local storage
//      * @param string $fileId The filename to delete
//      * @return bool True if deleted, false if not found
//      */
//     public function deleteDocument(string $fileId): bool {
//         $filePath = $this->uploadPath . '/' . $fileId;
//         return file_exists($filePath) ? unlink($filePath) : false;
//     }
// }





