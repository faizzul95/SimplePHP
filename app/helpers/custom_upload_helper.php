<?php

function folder($foldername = 'directory', $folderid = NULL, $type = 'image')
{
    // $foldername = replaceFolderName($foldername);
    $type = replaceFolderName($type);

    if (empty($folderid)) {
        $folder = 'public/upload/' . $foldername . '/' . $type;
    } else {
        $folderid = replaceFolderName($folderid);
        $folder = 'public/upload/' . $foldername . '/' . $folderid . '/' . $type;
    }

    files()->setUploadDir($folder, 0755);

    return $folder;
}

function replaceFolderName($folderName)
{
    return security()->sanitizeStorageSegment((string) $folderName);
}

function get_mime_type($filename)
{
    $idx = pathinfo($filename, PATHINFO_EXTENSION);

    $mimet = array(
        'txt' => 'text/plain',
        'htm' => 'text/html',
        'html' => 'text/html',
        'php' => 'text/html',
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'swf' => 'application/x-shockwave-flash',
        'flv' => 'video/x-flv',
        'png' => 'image/png',
        'jpe' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'gif' => 'image/gif',
        'bmp' => 'image/bmp',
        'ico' => 'image/vnd.microsoft.icon',
        'tiff' => 'image/tiff',
        'tif' => 'image/tiff',
        'svg' => 'image/svg+xml',
        'svgz' => 'image/svg+xml',
        'zip' => 'application/zip',
        'rar' => 'application/x-rar-compressed',
        'exe' => 'application/x-msdownload',
        'msi' => 'application/x-msdownload',
        'cab' => 'application/vnd.ms-cab-compressed',
        'mp3' => 'audio/mpeg',
        'qt' => 'video/quicktime',
        'mov' => 'video/quicktime',
        'pdf' => 'application/pdf',
        'psd' => 'image/vnd.adobe.photoshop',
        'ai' => 'application/postscript',
        'eps' => 'application/postscript',
        'ps' => 'application/postscript',
        'doc' => 'application/msword',
        'rtf' => 'application/rtf',
        'xls' => 'application/vnd.ms-excel',
        'ppt' => 'application/vnd.ms-powerpoint',
        'docx' => 'application/msword',
        'xlsx' => 'application/vnd.ms-excel',
        'pptx' => 'application/vnd.ms-powerpoint',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
    );

    if (isset($mimet[$idx])) {
        return $mimet[$idx];
    } else {
        return 'application/octet-stream';
    }
}

function upload($files, $folder, $data = NULL, $index = false, $compress = false, $file_compression = 1)
{
    $normalizedFile = $index === false
        ? $files
        : [
            'name' => $files['name'][$index] ?? null,
            'type' => $files['type'][$index] ?? null,
            'tmp_name' => $files['tmp_name'][$index] ?? null,
            'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$index] ?? 0,
        ];

    $uploader = files();
    $uploader->setUploadDir($folder, 0755);
    $uploader->setMaxFileSize(max(1, (int) ceil(min(
        convertPHPSizeToBytes(ini_get('upload_max_filesize')),
        convertPHPSizeToBytes(ini_get('post_max_size'))
    ) / (1024 * 1024))));
    $uploader->setAllowedMimeTypes('*');

    $result = $uploader->upload($normalizedFile, [
        'compress' => (bool) $compress,
        'file_compression' => $file_compression,
    ]);

    if (($result['isUpload'] ?? false) !== true) {
        return [
            'status' => (int) ($result['code'] ?? 400),
            'error' => (string) ($result['message'] ?? 'File could not be moved to destination directory'),
        ];
    }

    return [
        'status' => 200,
        'data' => legacyUploadDataFromFilesResult($result['files'] ?? [], $data),
    ];
}

function moveFile($filesName, $currentPath, $folder, $data = NULL, $type = 'rename', $compress = false, $file_compression = 1)
{
    $allowedOps = ['rename', 'copy'];
    if (!in_array($type, $allowedOps, true)) {
        throw new \InvalidArgumentException("Invalid file operation type: {$type}");
    }

    $uploader = files();
    $uploader->setUploadDir($folder, 0755);
    $uploader->setAllowedMimeTypes('*');

    $result = $uploader->storeFile($currentPath, $filesName, [
        'compress' => (bool) $compress,
        'file_compression' => $file_compression,
        'preserve_source' => $type === 'copy',
    ]);

    if (($result['isUpload'] ?? false) !== true) {
        return [];
    }

    return legacyUploadDataFromFilesResult($result['files'] ?? [], $data, $data['file_type'] ?? 'PROFILE_PHOTO');
}

function unlinkOldFiles($data = null)
{
    if (empty($data) || !isset($data['files_name']) || !isset($data['files_folder'])) {
        return false;
    }

    $filesName = $data['files_name'];
    $folder = $data['files_folder'];
    $file_compression = $data['files_compression'];
    $files_path = $data['files_path'];
    $ext = pathinfo($filesName, PATHINFO_EXTENSION);
    $filesNameNoExt = pathinfo($filesName, PATHINFO_FILENAME);

    $canCompress = ['jpg', 'png', 'jpeg', 'gif'];
    if (in_array(pathinfo($filesName, PATHINFO_EXTENSION), $canCompress)) {
        $compressfolder = ROOT_DIR . $folder . '/' . $filesNameNoExt . "_compress." . $ext;
        $thumbnailfolder = ROOT_DIR . $folder . '/' . $filesNameNoExt . "_thumbnail." . $ext;

        if ($file_compression === 2) {
            if (file_exists($compressfolder)) {
                unlink($compressfolder);
            };
        } elseif ($file_compression === 3) {
            if (file_exists($compressfolder)) {
                unlink($compressfolder);
            }

            if (file_exists($thumbnailfolder)) {
                unlink($thumbnailfolder);
            }
        }
    }

    if (file_exists(ROOT_DIR . $files_path)) {
        unlink(ROOT_DIR . $files_path);
    }
}

function getFilesCompression($data, $compression = null)
{
    if (empty($data) || !isset($data['files_name']) || !isset($data['files_folder'])) {
        return false;
    }

    $filesName = $data['files_name'];
    $folder = $data['files_folder'];
    $file_compression = empty($compression) ? $data['files_compression'] : $compression;
    $files_path = $data['files_path'];
    $ext = pathinfo($filesName, PATHINFO_EXTENSION);
    $filesNameNoExt = pathinfo($filesName, PATHINFO_FILENAME);

    $canCompress = ['jpg', 'png', 'jpeg', 'gif'];
    if (in_array(pathinfo($filesName, PATHINFO_EXTENSION), $canCompress)) {
        $compressfolder = $folder . '/' . $filesNameNoExt . "_compress." . $ext;
        $thumbnailfolder = $folder . '/' . $filesNameNoExt . "_thumbnail." . $ext;

        if ($file_compression === 2) {
            if (file_exists(ROOT_DIR . $compressfolder)) {
                return $compressfolder;
            }
        } elseif ($file_compression === 3) {
            if (file_exists(ROOT_DIR . $thumbnailfolder)) {
                return $thumbnailfolder;
            }
        }
    }

    return $files_path;
}

function compress($source, $destination, $quality = '100')
{
    if (!file_exists($source) || !is_readable($source)) {
        throw new Exception("Source file does not exist or is not readable: " . $source);
    }

    $info = getimagesize($source);
    $image = null;

    if ($info['mime'] == 'image/jpeg')
        $image = imagecreatefromjpeg($source);
    elseif ($info['mime'] == 'image/gif')
        $image = imagecreatefromgif($source);
    elseif ($info['mime'] == 'image/png')
        $image = imagecreatefrompng($source);

    if ($image) {
        imagejpeg($image, $destination, $quality);
    }

    return $destination;
}

function compressImageonthego($source, $quality)
{
    if (!file_exists($source) || !is_readable($source)) {
        throw new Exception("Source file does not exist or is not readable: " . $source);
    }

    $info = getimagesize($source);
    $extension = explode(".", $source);
    $newname = "temp" . rand(10, 100);

    if ($info['mime'] == 'image/jpeg')
        $image = imagecreatefromjpeg($source);
    elseif ($info['mime'] == 'image/gif')
        $image = imagecreatefromgif($source);
    elseif ($info['mime'] == 'image/png')
        $image = imagecreatefrompng($source);

    imagejpeg($image, "images/" . $newname . "." . $extension[1], $quality);
    echo "<b>" . $newname . "." . $extension[1] . "</b>";
}

function convertBase64String($base64String)
{
    return files()->decodeBase64DataUrl((string) $base64String);
}

function legacyUploadDataFromFilesResult(array $file, $data = null, $defaultEntityFileType = null)
{
    $entityType = is_array($data) ? ($data['type'] ?? null) : null;
    $entityFileType = is_array($data) ? ($data['file_type'] ?? $defaultEntityFileType) : $defaultEntityFileType;
    $entityId = is_array($data) ? ($data['entity_id'] ?? null) : null;
    $userId = is_array($data) ? ($data['user_id'] ?? null) : null;
    $mime = (string) ($file['mime'] ?? get_mime_type((string) ($file['original_name'] ?? '')));
    $fileType = explode('/', $mime, 2)[0] ?? '';

    return [
        'files_name' => (string) ($file['name'] ?? ''),
        'files_original_name' => (string) ($file['original_name'] ?? ''),
        'files_folder' => (string) ($file['relative_folder'] ?? ''),
        'files_type' => $fileType,
        'files_mime' => $mime,
        'files_extension' => (string) ($file['extension'] ?? ''),
        'files_size' => (int) ($file['size'] ?? 0),
        'files_compression' => (int) ($file['compression'] ?? 1),
        'files_path' => (string) ($file['relative_path'] ?? ''),
        'files_path_is_url' => 0,
        'entity_type' => $entityType,
        'entity_file_type' => $entityFileType,
        'entity_id' => $entityId,
        'user_id' => $userId,
    ];
}

function getExtensionFromMimeType($mimeType)
{
    $mimeToExt = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/bmp' => 'bmp',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
        'image/tiff' => 'tiff',
        'image/ico' => 'ico',
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/rtf' => 'rtf',
        'application/zip' => 'zip',
        'application/x-rar-compressed' => 'rar',
        'application/x-tar' => 'tar',
        'application/gzip' => 'gz',
        'audio/mpeg' => 'mp3',
        'audio/wav' => 'wav',
        'audio/ogg' => 'ogg',
        'audio/mp4' => 'm4a',
        'video/mp4' => 'mp4',
        'video/mpeg' => 'mpeg',
        'video/quicktime' => 'mov',
        'video/x-msvideo' => 'avi',
        'video/x-ms-wmv' => 'wmv',
        'application/json' => 'json',
        'application/xml' => 'xml',
        'text/html' => 'html',
        'text/css' => 'css',
        'application/javascript' => 'js'
    ];

    return isset($mimeToExt[$mimeType]) ? $mimeToExt[$mimeType] : null;
}

function convertGBToByte($gbValue)
{
    return $gbValue * pow(1024, 3);
}

function convertPHPSizeToBytes($size)
{
    $suffix = strtoupper(substr($size, -1));
    $value = (int)substr($size, 0, -1);
    switch ($suffix) {
        case 'P':
            $value *= 1024;
        case 'T':
            $value *= 1024;
        case 'G':
            $value *= 1024;
        case 'M':
            $value *= 1024;
        case 'K':
            $value *= 1024;
    }
    return $value;
}

function fileUploadErrorMessage($errorCode)
{
    $errors = [
        UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
        UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form.',
        UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded.',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write the file to disk.',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.'
    ];

    return $errors[$errorCode] ?? 'Unknown upload error.';
}

if (!defined('BANNED_REPLACE_WORD')) {
    define('BANNED_REPLACE_WORD', '[CONTENT-BLOCKED]');
}

// UPLOAD SECURITY HELPER

/**
 * Check if a string contains potentially malicious data
 * 
 * This function checks input strings for various types of malicious content including:
 * - XSS (Cross-Site Scripting) attempts
 * - JavaScript injection
 * - HTML injection
 * - Protocol handlers
 * - Base64 encoded malicious content
 * - DOM-based attacks
 * - SQL Injection attempts
 * - NoSQL Injection patterns
 * - Advanced XSS obfuscation techniques
 * 
 * @param string $data The input string to check
 * @param boolean $sanitizeValue Whether to sanitize and return the value (default: true)
 * @return array Returns ['malicious' => bool, 'value' => string] where value is either sanitized or original
 */
if (!function_exists('containsMalicious')) {
    function containsMalicious($input, $sanitizeValue = true, $options = [])
    {
        return security()->containsMalicious($input, (bool) $sanitizeValue, is_array($options) ? $options : []);
    }
}

/**
 * Scan a CSV file for malicious content in each cell
 * 
 * This function processes a CSV file line by line to minimize memory usage,
 * checking each cell for potential security threats using containsMalicious().
 * Optimized to handle extremely large files.
 * 
 * @param string $filePath Path to the CSV file to check
 * @param bool $terminate Whether to terminate the process execution when malicious content is found (default: false)
 * @param array $options Additional options for processing large files:
 *                      - include_data: Return processed data (default: true)
 *                      - skip_first_line: Skip header row (default: true)
 *                      - max_execution_time: Maximum execution time in seconds (default: 0 = no limit)
 *                      - memory_limit: Maximum memory to use in MB (default: NULL = use PHP default)
 *                      - buffer_size: File read buffer size in bytes (default: 8192)
 *                      - max_file_size: Maximum file size in MB (default: 4MB)
 *                      - optimize_memory: Number of rows to process before GC (default: 10000)
 *                      - csv_line_length: Maximum line length for fgetcsv in characters (default: 1000 = no limit)
 *                      - sanitize_value: Return sanitized value instead of original when malicious content is detected (default: true)
 *                      - whitelist_patterns: Regex patterns that should be treated as safe false positives
 *                      - whitelist_contains: Plain-text substrings that should be treated as safe false positives
 * @return array Returns standardized response array with the following keys:
 *                - success: boolean indicating operation success
 *                - message: operation result message
 *                - file_info: array containing file metadata information
 *                - header: array of CSV header data (if skip_first_line is true)
 *                - total_skipped_rows: count of skipped rows
 *                - total_processed_rows: count of processed rows
 *                - column_empty_count: array of empty columns with their names (if skip_first_line is true)
 *                - issue: array of detected issues (if any)
 *                - data: processed data (if include_data is true)
 */
if (!function_exists('extractSafeCSVContent')) {
    function extractSafeCSVContent($files, $terminate = false, $options = [])
    {
        // Set default options and merge with provided options
        $defaultOptions = [
            'include_data' => true,   // Will return data that has been processed
            'skip_first_line' => true,       // Skip first line check
            'max_execution_time' => 0,       // No execution time limit by default
            'memory_limit' => null,          // Use PHP default memory limit
            'buffer_size' => 8192,           // File read buffer size (bytes)
            'max_file_size' => 4,            // Maximum file size in MB
            'optimize_memory' => 10000,      // Number of rows to process before force GC to cleanup memory
            'csv_line_length' => 1000,       // Maximum line length for fgetcsv (0 = no limit)
            'sanitize_value' => true        // Return sanitized value instead of original when malicious content is detected
        ];
        $options = array_merge($defaultOptions, $options);

        // Default response
        $result = [
            'success' => false,
            'message' => '',
            'file_info' => [],
            'header' => [],
            'total_skipped_rows' => 0,
            'total_processed_rows' => 0,
            'issue' => [],
            'data' => [],
            'column_empty_count' => []
        ];

        if ($files['error'] !== UPLOAD_ERR_OK) {
            $result['message'] = 'File upload error: ' . fileUploadErrorMessage($files['error']);
            return $result;
        }

        $filename = $files['name'];
        $filePath = $files['tmp_name'];
        $fileSize = $files['size'];
        $fileExtension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        $fileSizeMB = round($fileSize / (1024 * 1024), 2); // Convert bytes to MB

        $checkFilename = containsMalicious($filename, true);
        if ($checkFilename['malicious']) {
            $result['message'] = 'Security warning: The filename contains potentially harmful characters. Please rename your file and try again.';
            return $result;
        }

        $result['file_info'] = [
            'name' => trim($filename),
            'path' => $filePath,
            'ext' => trim($fileExtension),
            'size' => $fileSize,
            'size_mb' => $fileSizeMB,
        ];

        if (!file_exists($filePath)) {
            $result['message'] = 'File not found: ' . $filePath;
            return $result;
        }

        if (!is_readable($filePath)) {
            $result['message'] = 'File not readable: ' . $filePath;
            return $result;
        }

        if ($fileExtension !== 'csv') {
            $result['message'] = 'File is not a CSV: ' . $filename;
            return $result;
        }

        if ($fileSizeMB > $options['max_file_size']) {
            $result['message'] = 'File size exceeds the maximum allowed limit of ' . $options['max_file_size'] . ' MB: ' . $filename;
            return $result;
        }

        // Apply execution time limit if specified
        if ($options['max_execution_time'] > 0) {
            set_time_limit($options['max_execution_time']);
        }

        // Apply memory limit if specified
        if (!empty($options['memory_limit']) || $options['memory_limit'] != 0) {
            ini_set('memory_limit', $options['memory_limit']);
        }

        // Initialize return values
        $dataProcess = [];

        // Open file for reading - using streaming approach to minimize memory usage
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            $result['message'] = 'Failed to open file: ' . $filePath;
            return $result;
        }

        // Set buffer size to reduce memory usage
        stream_set_read_buffer($handle, $options['buffer_size']);

        $rowNumber = 0;

        try {

            $columnHasValue = [];

            // Skip header if needed
            if ($options['skip_first_line']) {
                // Read the first line as headers
                $headers = fgetcsv($handle, $options['csv_line_length'], ",");
                if ($headers === false || empty(array_filter($headers))) {
                    $result['message'] = 'CSV file is empty or has invalid headers.';
                    fclose($handle);
                    return $result;
                }

                // Trim headers to remove whitespace
                $result['header'] = array_map('trim', $headers);
                $result['total_skipped_rows'] += 1;

                // Initialize tracking array for all columns
                $columnHasValue = array_fill(0, count($result['header']), 0);
            }

            // Process each row using fgetcsv
            while (($row = fgetcsv($handle, $options['csv_line_length'], ",")) !== false) {
                ++$result['total_processed_rows'];

                // Skip completely empty rows (all columns are empty)
                if (!array_filter($row, 'strlen')) {
                    ++$result['total_skipped_rows'];
                    continue;
                }

                foreach ($row as $colNumber => $cellValue) {
                    $cellValue = trim($cellValue);

                    // Store processed data if requested
                    if ($options['include_data']) {
                        $dataProcess[$rowNumber][$colNumber] = $cellValue;
                    }

                    // Skip processing for empty cells
                    if (empty($cellValue)) {
                        if ($options['skip_first_line'])
                            $columnHasValue[$colNumber]++;

                        continue;
                    }

                    $check = containsMalicious($cellValue, $options['sanitize_value'], [
                        'whitelist_patterns' => $options['whitelist_patterns'] ?? [],
                        'whitelist_contains' => $options['whitelist_contains'] ?? [],
                    ]);
                    $malicious = $check['malicious'] ?? false;
                    $sanitizeValue = $check['value'] ?? $cellValue;

                    // Check if cell contains malicious content
                    if ($malicious) {
                        $issue = [
                            'row' => $result['total_processed_rows'] + 1,
                            'column' => $colNumber + 1,
                            'value' => htmlspecialchars(mb_substr($cellValue, 0, 400) . (mb_strlen($cellValue) > 400 ? '...' : '')),
                            'sanitize_value' => htmlspecialchars($sanitizeValue)
                        ];

                        $result['issue'][] = $issue;

                        if ($terminate) {
                            if (is_resource($handle)) {
                                fclose($handle);
                            }

                            $result['data'] = []; // reset
                            $result['message'] = "Security Alert - Row {$issue['row']} column {$issue['column']}: 
                                <span 
                                style='text-decoration: underline; cursor: pointer; position: relative; display: inline-block; color: #d9534f; font-weight: bold; padding: 2px 4px; background-color: rgba(217, 83, 79, 0.1); border-radius: 3px;' 
                                onmouseover=\"this.querySelector('.tooltip').style.visibility='visible'; this.querySelector('.tooltip').style.opacity='1';\" 
                                onmouseout=\"this.querySelector('.tooltip').style.visibility='hidden'; this.querySelector('.tooltip').style.opacity='0';\">
                                Malicious code detected
                                <span style='visibility: hidden; width: 450px; background-color: #ffffff; color: #333333; text-align: left; 
                                border-radius: 8px; padding: 16px; position: absolute; z-index: 1000; top: -15px; left: 100%; 
                                margin-left: 15px; opacity: 0; transition: opacity 0.3s, transform 0.3s; box-shadow: 0 6px 20px rgba(0,0,0,0.15);
                                border: 1px solid #e0e0e0; transform: translateY(5px); font-weight: normal; line-height: 1.5;' 
                                class='tooltip'>
                                    <div style='margin-bottom: 12px; font-size: 15px; font-weight: 600; border-bottom: 1px solid #e0e0e0; padding-bottom: 8px; display: flex; align-items: center;'>
                                        Security Issue Details
                                    </div>
                                        
                                    <div style='margin-bottom: 8px; font-weight: 500; color: #d32f2f; display: flex; align-items: center;'>
                                        <svg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' style='margin-right: 6px;'><path fill='currentColor' d='M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z'/></svg>
                                        Original Content:
                                    </div>
                                    <div style='background-color: #f8f8f8; padding: 10px; border-radius: 6px; margin-bottom: 14px; border-left: 3px solid #d32f2f;'>
                                        <textarea readonly style='width: 100%; height: 80px; background-color: transparent; color: #333333; 
                                        border: none; resize: vertical; font-family: monospace; font-size: 12px; line-height: 1.5;
                                        padding: 0; margin: 0; outline: none; overflow-y: auto;'>" . str_replace('"', '&quot;', htmlspecialchars($cellValue)) .
                                "</textarea>
                                    </div>
                                    
                                    <div style='margin-bottom: 8px; font-weight: 500; color: #2e7d32; display: flex; align-items: center;'>
                                        <svg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' style='margin-right: 6px;'><path fill='currentColor' d='M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z'/></svg>
                                        Sanitized Content:
                                    </div>
                                    <div style='background-color: #f8f8f8; padding: 10px; border-radius: 6px; border-left: 3px solid #2e7d32;'>
                                        <textarea readonly style='width: 100%; height: 80px; background-color: transparent; color: #333333; 
                                        border: none; resize: vertical; font-family: monospace; font-size: 12px; line-height: 1.5;
                                        padding: 0; margin: 0; outline: none; overflow-y: auto;'>" . $issue['sanitize_value'] .
                                "</textarea>
                                    </div>
                                    
                                    <div style='font-size: 12px; color: #666666; margin-top: 12px; display: flex; align-items: center; background-color: #fff8e1; padding: 8px; border-radius: 4px;'>
                                        <svg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' style='color: #f57c00; margin-right: 8px;'><path fill='currentColor' d='M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z'/></svg>
                                        Potentially harmful content has been replaced with '<b>" . BANNED_REPLACE_WORD . "</b>'
                                    </div>
                                </span>
                            </span>. Please verify your data source and remove any potentially harmful content.";

                            return $result;
                        } else {
                            // Store processed data if requested as a sanitize data
                            if ($options['include_data']) {
                                $dataProcess[$rowNumber][$colNumber] = $sanitizeValue;
                            }
                        }
                    }

                    unset($check);
                }

                // Free memory
                unset($row);

                if ($rowNumber % $options['optimize_memory'] === 0) {
                    if (function_exists('gc_collect_cycles')) {
                        gc_collect_cycles();
                    }
                }

                $rowNumber++;
            }

            $result['column_empty_count'] = $columnHasValue;
        } catch (Exception $e) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            $result['message'] = 'Error processing CSV: ' . $e->getMessage();
            return $result;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        $message = 'CSV scanned successfully.';
        $success = true;
        if (!empty($result['issue'])) {
            $message = 'Malicious content detected in CSV.';
            $success = false;
        } else if ($options['include_data'] && empty($dataProcess)) {
            $message = 'No valid data found in CSV file.';
            $success = false;
        }

        $result['success'] = $success;
        $result['message'] = $message;
        $result['data'] = $options['include_data'] ? $dataProcess : [];

        return $result;
    }
}