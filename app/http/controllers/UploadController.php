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
        $response = ['code' => 400, 'message' => 'Invalid request'];

        try {
            $entity_id = decodeID($request->validated('entity_id'));
            $entity_type = $request->validated('entity_type');
            $entity_file_type = $request->validated('entity_file_type');
            $image = $request->validated('image');

            $imageConvert = convertBase64String($image);

            if (!$imageConvert['status']) {
                $response = ['code' => 400, 'message' => $imageConvert['error']];
                jsonResponse($response);
            }

            $imageUpload = $imageConvert['data'];
            $extension = $imageConvert['extension'];

            $user_id = currentUserID();
            $id = decodeID($request->validated('id'));
            $folder_group = $request->validated('folder_group', 'unknown');
            $folder_type = $request->validated('folder_type', 'unknown');

            $folder = folder($folder_group, $entity_id, $folder_type);
            $fileNameNew = $entity_id . "_" . date('dFY') . "_" . date('his') . '.' . $extension;
            $path = ROOT_DIR . $folder . '/' . $fileNameNew;

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

            if (!is_dir(ROOT_DIR . $folder)) {
                if (!mkdir(ROOT_DIR . $folder, 0755, true)) {
                    $response = ['code' => 500, 'message' => 'Failed to create upload directory'];
                    jsonResponse($response);
                }
            }

            if (file_put_contents($path, $imageUpload)) {
                try {
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
                                unlinkOldFiles($dataPrev);
                                $response = ['code' => 200, 'message' => 'Image uploaded successfully', 'data' => $moveImg];
                            }
                        } catch (\Exception $e) {
                            if (file_exists($path)) {
                                unlink($path);
                            }
                            error_log("Database error: " . $e->getMessage());
                            $response = ['code' => 500, 'message' => 'Database error occurred'];
                        }
                    } else {
                        if (file_exists($path)) {
                            unlink($path);
                        }
                        $response = ['code' => 500, 'message' => 'Failed to process uploaded file'];
                    }
                } catch (\Exception $e) {
                    if (file_exists($path)) {
                        unlink($path);
                    }
                    error_log("Move file error: " . $e->getMessage());
                    $response = ['code' => 500, 'message' => 'Failed to move uploaded file'];
                }
            } else {
                $response = ['code' => 500, 'message' => 'Failed to save image file'];
            }
        } catch (\Exception $e) {
            error_log("Upload image cropper error: " . $e->getMessage());
            $response = ['code' => 500, 'message' => 'An unexpected error occurred'];
        }

        jsonResponse($response);
    }

    public function removeUploadFiles(Request $request): void
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
}
