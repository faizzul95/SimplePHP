<?php

// IMPORTANT: This file is part of the application
require_once '../init.php';

function uploadImageCropper($request)
{
    $response = ['code' => 400, 'message' => 'Invalid request'];

    $validation = validator(request()->all(), [
        // 'change_image' => 'required|file|mimes:jpg,jpeg,png|max_file_size:8192', // 8MB (8192 KB)
        'entity_type' => 'required|string|max_length:255|secure_value',
        'entity_file_type' => 'required|string|max_length:255|secure_value',
        'entity_id' => 'required|string', // use string instead of numeric/integer since the id is encode
        'id' => 'string',  // use string instead of numeric/integer since the id is encode
    ], [
        // 'change_image.required' => 'File upload is required',
        // 'change_image.file' => 'File upload must be a valid file',
        // 'change_image.mimes' => 'File upload must be a valid image file (jpg, jpeg, png)',
        // 'change_image.max_file_size' => 'File upload must not exceed 8MB',
    ])->validate();

    if (!$validation->passed()) {
        jsonResponse(['code' => 400, 'message' => $validation->getFirstError()]);
    }

    try {
        $entity_id = decodeID(request()->input('entity_id'));
        $entity_type = request()->input('entity_type');
        $entity_file_type = request()->input('entity_file_type');
        $image = $request['image'] ?? null;

        $imageConvert = convertBase64String($image);

        if (!$imageConvert['status']) {
            $response = ['code' => 400, 'message' => $imageConvert['error']];
            jsonResponse($response);
        }

        $imageUpload = $imageConvert['data'];
        $extension = $imageConvert['extension'];

        $user_id = currentUserID();
        $id = decodeID(request()->input('id'));
        $folder_group = request()->input('folder_group', 'unknown');
        $folder_type = request()->input('folder_type', 'unknown');

        // Generate folder
        $folder = folder($folder_group, $entity_id, $folder_type);

        $fileNameNew = $entity_id . "_" . date('dFY') . "_" . date('his') . '.' . $extension;
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
