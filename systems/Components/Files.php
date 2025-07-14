<?php

namespace Components;

/**
 * Files Class
 *
 * This class provides functionality to handle file operations such as folder creation and file uploads.
 *
 * @category  Utility
 * @package   Core
 * @author    Mohd Fahmy Izwan Zulkhafri <faizzul14@gmail.com>
 * @license   http://opensource.org/licenses/gpl-3.0.html GNU Public License
 * @link      -
 * @version   1.0.0
 */
class Files
{
    /**
     * @var string The path
     */
    private $path = '../../';

    /**
     * @var string The upload directory path.
     */
    private $uploadDir = 'public/upload';

    /**
     * @var int The maximum file size allowed in megabytes.
     */
    private $maxFileSize = 4;

    /**
     * @var string Allowed MIME types. Can be a string MIME type, or '*'.
     */
    private $allowedMimeTypes = 'image/jpeg, image/png, application/pdf';

    /**
     * Sets the upload directory.
     *
     * @param string $uploadDir The upload directory path.
     * @return void
     */
    public function setUploadDir(string $uploadDir, ?int $permission = 0775): void
    {
        $this->uploadDir = $uploadDir;
        $this->createFolder($permission);
    }

    /**
     * Sets the maximum file size allowed.
     *
     * @param int $maxFileSize The maximum file size allowed in megabytes.
     * @return void
     */
    public function setMaxFileSize(int $maxFileSize): void
    {
        $this->maxFileSize = $maxFileSize;
    }

    /**
     * Sets the allowed MIME types.
     *
     * @param string $allowedMimeTypes Allowed MIME types. Can be a string MIME type or '*'.
     * @return void
     * @throws \InvalidArgumentException If $allowedMimeTypes is not a string.
     */
    public function setAllowedMimeTypes(string $allowedMimeTypes): void
    {
        if (!is_string($allowedMimeTypes)) {
            throw new \InvalidArgumentException("Allowed MIME types must be a string.");
        }

        $this->allowedMimeTypes = $allowedMimeTypes;
    }

    /**
     * Uploads a file.
     *
     * @param array $file The file to upload.
     * @return array An array containing upload status and file details.
     */
    public function upload(array $file): array
    {
        $targetDir = $this->path . $this->uploadDir . '/';
        $this->createFolder();

        $originalName = $file["name"];
        $newFileName = $this->generateFileName($originalName);
        $targetFile = $targetDir . $newFileName;

        $response = [
            'code' => 400,
            'message' => "",
            'files' => [
                'original_name' => htmlspecialchars($originalName),
                'name' => htmlspecialchars($newFileName),
                'size' => $file["size"] ?? NULL,
                'path' => '',
                'folder' => $targetDir,
                'mime' => isset($file["tmp_name"]) && !empty($file["tmp_name"]) ? mime_content_type($file["tmp_name"]) : NULL,
            ],
            'isUpload' => false
        ];

        if ($file['error'] == UPLOAD_ERR_INI_SIZE) {
            $maxFileSize = ini_get('upload_max_filesize');
            $response['message'] = "The uploaded file exceeds the maximum file size limit of $maxFileSize. Please try uploading a smaller file.";
            return $response;
        }

        // Handle file upload
        $targetFile = $targetDir . basename($file["name"]);

        // Check file size
        if ($file["size"] > ($this->maxFileSize * 1024 * 1024)) {
            $response['message'] = "Sorry, your file exceeds the maximum file size of {$this->maxFileSize}MB.";
            return $response;
        }

        // Check file type
        $fileType = mime_content_type($file["tmp_name"]);
        if ($this->allowedMimeTypes !== '*') {
            $allowedTypes = $this->allowedMimeTypes;
            // Check if the file type is not in the allowed MIME types
            if (!in_array($fileType, explode(',', $allowedTypes))) {
                $response['message'] = "Sorry, only files of type(s) {$allowedTypes} are allowed.";
                return $response;
            }
        }

        // Attempt to move the uploaded file
        if (move_uploaded_file($file["tmp_name"], $targetFile)) {
            $response['code'] = 200;
            $response['message'] = "The file has been uploaded";
            $response['files']['path'] = $targetFile;
            $response['isUpload'] = true;
        } else {
            $response['message'] = "Sorry, there was an error uploading your file.";
        }

        return $response;
    }

    /**
     * Generates a unique file name to ensure security.
     *
     * @param string $originalName The original name of the file.
     * @return string The generated file name.
     */
    private function generateFileName(string $originalName): string
    {
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        return time() . bin2hex(random_bytes(5)) . '.' . $extension; // Concatenate timestamp and random string
    }

    /**
     * Creates a folder.
     *
     * This method creates a folder with the specified name within the provided upload directory.
     *
     * @param int|null $permission Optional. The permission mode for the created folder.
     * @return void
     */
    private function createFolder(?int $permission = 0755): void
    {
        $folderPath = $this->path . $this->uploadDir;

        // Check if folder already exists
        if (!is_dir($folderPath)) {
            // Create directory
            mkdir($folderPath, $permission, true);
        }
        touch($folderPath);
        chmod($folderPath, $permission);
    }
}
