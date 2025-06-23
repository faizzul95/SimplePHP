<?php

// IMPORTANT: This file is part of the application
require_once '../init.php';

function uploadImageCropper($request)
{
    $response = ['code' => 400, 'message' => 'Invalid request'];

    try {
        $user_id = currentUserID();
        $id = decodeID(request()->input('id'));
        $entity_id = decodeID(request()->input('entity_id'));
        $entity_type = request()->input('entity_type');
        $entity_file_type = request()->input('entity_file_type');
        $image = $request['image'] ?? null;

        // Validate required fields
        if (empty($image) || empty($entity_id) || empty($entity_type) || empty($entity_file_type)) {
            $response = ['code' => 400, 'message' => 'Missing required fields'];
            jsonResponse($response);
        }

        $folder_group = request()->input('folder_group', 'unknown');
        $folder_type = request()->input('folder_type', 'unknown');

        // Generate folder
        $folder = folder($folder_group, $entity_id, $folder_type);

        // Validate base64 image format
        if (!preg_match('/^data:image\/[a-zA-Z]+;base64,/', $image)) {
            $response = ['code' => 400, 'message' => 'Invalid image format'];
            jsonResponse($response);
        }

        // Extract image data
        list($type, $image_data) = explode(';', $image);
        list(, $image_data) = explode(',', $image_data);

        // Extract file extension from MIME type
        $mimeType = str_replace('data:', '', $type);
        $extension = '';

        switch ($mimeType) {
            case 'image/jpeg':
            case 'image/jpg':
                $extension = '.jpg';
                break;
            case 'image/png':
                $extension = '.png';
                break;
            case 'image/gif':
                $extension = '.gif';
                break;
            case 'image/webp':
                $extension = '.webp';
                break;
            case 'image/bmp':
                $extension = '.bmp';
                break;
            default:
                $extension = '.jpg'; // fallback to jpg
                break;
        }

        $imageUpload = base64_decode($image_data);

        // Validate decoded image
        if ($imageUpload === false) {
            $response = ['code' => 400, 'message' => 'Failed to decode image'];
            jsonResponse($response);
        }

        $fileNameNew = $entity_id . "_" . date('dFY') . "_" . date('his') . $extension;
        $path = ROOT_DIR . $folder . '/' . $fileNameNew;

        // Get previous data if updating
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

        // Create directory if it doesn't exist
        if (!is_dir(ROOT_DIR . $folder)) {
            if (!mkdir(ROOT_DIR . $folder, 0755, true)) {
                $response = ['code' => 500, 'message' => 'Failed to create upload directory'];
                jsonResponse($response);
            }
        }

        // Save file
        if (file_put_contents($path, $imageUpload)) {
            try {
                // Move image from default
                $moveImg = moveFile(
                    $fileNameNew,
                    $path,
                    $folder,
                    [
                        'type' => $entity_type,
                        'file_type' => $entity_file_type,
                        'entity_id' => $entity_id,
                        'user_id' => $user_id,
                    ],
                    'rename',
                    true,
                    3
                );

                if (!empty($moveImg)) {
                    try {
                        if (empty($id)) {
                            $moveImg['created_at'] = timestamp();
                            $response = db()->table('entity_files')->insert($moveImg);
                        } else {
                            $moveImg['updated_at'] = timestamp();
                            $response = db()->table('entity_files')->where('id', $id)->update($moveImg);
                        }

                        if (isSuccess($response['code'])) {
                            // Remove previous file if exists
                            unlinkOldFiles($dataPrev);
                            $response = ['code' => 200, 'message' => 'Image uploaded successfully', 'data' => $moveImg];
                        }
                    } catch (Exception $e) {
                        // Clean up uploaded file if database operation fails
                        if (file_exists($path)) {
                            unlink($path);
                        }
                        error_log("Database error: " . $e->getMessage());
                        $response = ['code' => 500, 'message' => 'Database error occurred'];
                    }
                } else {
                    // Clean up uploaded file if moveFile fails
                    if (file_exists($path)) {
                        unlink($path);
                    }
                    $response = ['code' => 500, 'message' => 'Failed to process uploaded file'];
                }
            } catch (Exception $e) {
                // Clean up uploaded file if moveFile fails
                if (file_exists($path)) {
                    unlink($path);
                }
                error_log("Move file error: " . $e->getMessage());
                $response = ['code' => 500, 'message' => 'Failed to move uploaded file'];
            }
        } else {
            $response = ['code' => 500, 'message' => 'Failed to save image file'];
        }
    } catch (Exception $e) {
        error_log("Upload image cropper error: " . $e->getMessage());
        $response = ['code' => 500, 'message' => 'An unexpected error occurred'];
    }

    jsonResponse($response);
}

/*
|--------------------------------------------------------------------------
| DELETE UPLOAD FILES
|--------------------------------------------------------------------------
*/

function removeUploadFiles($request)
{
    $id = decodeID(request()->input('id'));

    if (empty($id)) {
        jsonResponse(['code' => 400, 'message' => 'ID is required']);
    }

    $files = db()->table('entity_files')->select('id, entity_id, files_name, files_path, files_disk_storage, files_path_is_url, files_compression, files_folder')
        ->where('id', $id)
        ->fetch();

    if (empty($files)) {
        jsonResponse(['code' => 400, 'message' => 'No files data found']);
    }

    $result = db()->table('entity_files')->where('id', $id)->delete();

    if (isError($result['code'])) {
        jsonResponse(['code' => 422, 'message' => 'Failed to delete files']);
    }

    unlinkOldFiles($files);

    jsonResponse(['code' => 200, 'message' => 'Files deleted']);
}
