<?php

namespace App\Http\Controllers;

use Core\Http\Controller;
use Core\Http\Request;
use App\Http\Requests\UploadImageCropperRequest;

class UploadController extends Controller
{
    public function __construct()
    {
        parent::__construct();
    }

    public function uploadImageCropper(UploadImageCropperRequest $request): void
    {
        $storedFile = null;

        try {
            $entity_id = $request->validated('entity_id');
            if ($entity_id === null || $entity_id === '') {
                jsonResponse(['code' => 400, 'message' => 'Entity ID is invalid']);
            }

            $entity_type = $request->validated('entity_type');
            $entity_file_type = $request->validated('entity_file_type');
            $image = $request->validated('image');

            $user_id = currentUserID();
            $requestId = $request->validated('id');
            if ($requestId === null || $requestId === '') {
                $id = null;
            } else {
                $id = $requestId;
            }
            $folder_group = $request->validated('folder_group', 'unknown');
            $folder_type = $request->validated('folder_type', 'unknown');
            $originalBaseName = $entity_id . '_' . date('YmdHis');

            $folder = folder($folder_group, $entity_id, $folder_type);

            $dataPrev = [];
            if (empty($id)) {
                $dataPrev = db()->table('entity_files')
                    ->where('entity_type', $entity_type)
                    ->where('entity_file_type', $entity_file_type)
                    ->where('entity_id', $entity_id)
                    ->fetch();
            } else {
                $dataPrev = db()->table('entity_files')->where('id', $id)->fetch();
            }

            $uploader = files();
            $uploader->setUploadDir($folder, 0755);
            $uploader->setMaxFileSize(8);
            $uploader->setAllowedMimeTypes('image/jpeg, image/png');
            $uploader->setImageLimits(5000, 5000, 16000000);

            $uploadResult = $uploader->uploadBase64Image($image, [
                'original_name' => $originalBaseName . '.upload',
                'compress' => true,
                'file_compression' => 3,
            ]);

            if (!$uploadResult['isUpload']) {
                jsonResponse([
                    'code' => (int) ($uploadResult['code'] ?? 400),
                    'message' => (string) ($uploadResult['message'] ?: 'Failed to process uploaded image'),
                ]);
            }

            $stored = $uploadResult['files'];
            $storedFile = [
                'files_name' => html_entity_decode($stored['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'files_original_name' => $originalBaseName . '.' . $stored['extension'],
                'files_folder' => $stored['relative_folder'],
                'files_type' => 'image',
                'files_mime' => $stored['mime'],
                'files_extension' => $stored['extension'],
                'files_size' => $stored['size'],
                'files_compression' => $stored['compression'],
                'files_path' => $stored['relative_path'],
                'files_path_is_url' => 0,
                'entity_type' => $entity_type,
                'entity_file_type' => $entity_file_type,
                'entity_id' => $entity_id,
                'user_id' => $user_id,
            ];

            if (empty($id)) {
                $storedFile['created_at'] = timestamp();
                $response = db()->table('entity_files')->insert($storedFile);
            } else {
                $storedFile['updated_at'] = timestamp();
                $response = db()->table('entity_files')->where('id', $id)->update($storedFile);
            }

            if (isError($response['code'])) {
                if ($storedFile !== null) {
                    unlinkOldFiles($storedFile);
                }

                logger()->logWithContext('Upload image cropper database write failed', [
                    'entity_id' => $entity_id,
                    'entity_type' => $entity_type,
                    'entity_file_type' => $entity_file_type,
                    'response' => $response,
                ], \Components\Logger::LOG_LEVEL_ERROR);
                jsonResponse(['code' => 500, 'message' => 'Database error occurred']);
            }

            unlinkOldFiles($dataPrev);
            jsonResponse([
                'code' => 200,
                'message' => 'Image uploaded successfully',
                'data' => $storedFile,
            ]);
        } catch (\Throwable $e) {
            if ($storedFile !== null) {
                unlinkOldFiles($storedFile);
            }

            logger()->logException($e);
            jsonResponse(['code' => 500, 'message' => 'An unexpected error occurred']);
        }
    }

    public function removeUploadFiles(Request $request): void
    {
        $id = $request->input('id');
        if ($id === null || $id === '') {
            jsonResponse(['code' => 400, 'message' => 'File ID is required']);
        }

        $files = db()->table('entity_files')->select('id, entity_id, files_name, files_path, files_disk_storage, files_path_is_url, files_compression, files_folder')
            ->where('id', $id)
            ->fetch();

        if (empty($files)) {
            jsonResponse(['code' => 404, 'message' => 'No file data found']);
        }

        $result = db()->table('entity_files')->where('id', $id)->delete();

        if (isError($result['code'])) {
            logger()->logWithContext('Upload file deletion failed', [
                'entity_file_id' => $id,
                'response' => $result,
            ], \Components\Logger::LOG_LEVEL_ERROR);
            jsonResponse(['code' => 422, 'message' => 'Failed to delete file']);
        }

        unlinkOldFiles($files);

        jsonResponse(['code' => 200, 'message' => 'File deleted']);
    }
}
