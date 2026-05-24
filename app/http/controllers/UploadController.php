<?php

namespace App\Http\Controllers;

use Core\Http\Controller;
use Core\Http\Request;
use App\Http\Requests\UploadImageCropperRequest;

class UploadController extends Controller
{
    private const PROFILE_UPLOAD_POLICY = 'image-cropper';

    /**
     * Permission that allows a user to manage another user's profile uploads.
     * Super Administrators satisfy it implicitly through the '*' wildcard;
     * Administrators receive it through the seeded RBAC defaults.
     */
    private const MANAGE_OTHERS_PERMISSION = 'user-update';

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

            if (!$this->canEditEntity((string) $entity_type, $entity_id)) {
                jsonResponse(['code' => 403, 'message' => 'You are not allowed to upload to this profile.']);
            }

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
                    ->select('id, entity_id, entity_type, entity_file_type, files_name, files_path, files_compression, files_folder')
                    ->where('entity_type', $entity_type)
                    ->where('entity_file_type', $entity_file_type)
                    ->where('entity_id', $entity_id)
                    ->fetch();
            } else {
                $dataPrev = db()->table('entity_files')
                    ->select('id, entity_id, entity_type, entity_file_type, files_name, files_path, files_compression, files_folder')
                    ->where('id', $id)
                    ->fetch();

                if (empty($dataPrev)) {
                    jsonResponse(['code' => 404, 'message' => 'Upload target was not found']);
                }

                if (!$this->isManagedProfileUploadRecord($dataPrev) || !$this->recordMatchesSubmittedEntity($dataPrev, $entity_id, $entity_type, $entity_file_type)) {
                    jsonResponse(['code' => 403, 'message' => 'Upload target is not allowed']);
                }
            }

            $uploader = files();
            $uploader->setUploadDir($folder, 0755);
            $uploader->setMaxFileSize(8);
            $uploader->setAllowedMimeTypes('image/jpeg, image/png');
            $uploader->setImageLimits(2000, 2000, 4000000);

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

        $files = db()->table('entity_files')->select('id, entity_id, entity_type, entity_file_type, files_name, files_path, files_disk_storage, files_path_is_url, files_compression, files_folder')
            ->where('id', $id)
            ->fetch();

        if (empty($files)) {
            jsonResponse(['code' => 404, 'message' => 'No file data found']);
        }

        if (!$this->isManagedProfileUploadRecord($files)) {
            jsonResponse(['code' => 403, 'message' => 'Upload target is not allowed']);
        }

        if (!$this->canEditEntity((string) ($files['entity_type'] ?? ''), $files['entity_id'] ?? null)) {
            jsonResponse(['code' => 403, 'message' => 'You are not allowed to delete this file.']);
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

    /**
     * Decide whether the current user may upload to or delete files for the
     * given entity. Self-managed profiles are always allowed; cross-user
     * management requires the MANAGE_OTHERS_PERMISSION (Super Administrator
     * satisfies it via the '*' wildcard, Administrator via the seeded
     * 'user-update' grant).
     *
     * @param string $entityType
     * @param mixed  $entityId
     */
    protected function canEditEntity(string $entityType, mixed $entityId): bool
    {
        if ($entityId === null || $entityId === '') {
            return false;
        }

        if ($this->isSelfEntity($entityType, $entityId)) {
            return true;
        }

        return $this->userHasPermission(self::MANAGE_OTHERS_PERMISSION);
    }

    /**
     * Determine whether the entity refers to the currently authenticated user.
     * Only entity_type='users' qualifies for self-management.
     */
    protected function isSelfEntity(string $entityType, mixed $entityId): bool
    {
        if (strcasecmp(trim($entityType), 'users') !== 0) {
            return false;
        }

        $currentId = $this->currentUserId();
        if ($currentId === null || $currentId === '') {
            return false;
        }

        return (string) $entityId === (string) $currentId;
    }

    /**
     * Resolve the current user identifier. Overridable for testing.
     *
     * @return int|string|null
     */
    protected function currentUserId(): int|string|null
    {
        return currentUserID();
    }

    /**
     * Resolve a permission check against the active user. Overridable for testing.
     */
    protected function userHasPermission(string $permission): bool
    {
        return auth()->hasPermission($permission);
    }

    protected function isManagedProfileUploadRecord(array $record): bool
    {
        $policy = $this->profileUploadPolicy();
        if (empty($policy)) {
            return false;
        }

        [$folderGroup, $folderType] = $this->extractFolderProfile((string) ($record['files_folder'] ?? ''));

        return $this->matchesAllowedValue((string) ($record['entity_type'] ?? ''), (array) ($policy['entity_types'] ?? []))
            && $this->matchesAllowedValue((string) ($record['entity_file_type'] ?? ''), (array) ($policy['entity_file_types'] ?? []))
            && $this->matchesAllowedValue($folderGroup, (array) ($policy['folder_groups'] ?? []), true)
            && $this->matchesAllowedValue($folderType, (array) ($policy['folder_types'] ?? []), true);
    }

    protected function recordMatchesSubmittedEntity(array $record, string $entityId, string $entityType, string $entityFileType): bool
    {
        return trim((string) ($record['entity_id'] ?? '')) === trim($entityId)
            && strcasecmp(trim((string) ($record['entity_type'] ?? '')), trim($entityType)) === 0
            && strcasecmp(trim((string) ($record['entity_file_type'] ?? '')), trim($entityFileType)) === 0;
    }

    protected function profileUploadPolicy(): array
    {
        return (array) config('framework.upload_guards.' . self::PROFILE_UPLOAD_POLICY, []);
    }

    protected function matchesAllowedValue(string $value, array $allowed, bool $allowEmpty = false): bool
    {
        $value = trim($value);
        if ($value === '') {
            return $allowEmpty || empty($allowed);
        }

        if (empty($allowed)) {
            return true;
        }

        $allowed = array_map(static fn($item): string => strtolower(trim((string) $item)), $allowed);

        return in_array(strtolower($value), $allowed, true);
    }

    protected function extractFolderProfile(string $folder): array
    {
        $segments = array_values(array_filter(explode('/', trim($folder, '/')), static fn($segment): bool => $segment !== ''));
        if (count($segments) < 2) {
            return ['', ''];
        }

        if (($segments[0] ?? '') === 'public' && ($segments[1] ?? '') === 'upload') {
            $segments = array_slice($segments, 2);
        }

        if (empty($segments)) {
            return ['', ''];
        }

        return [
            (string) ($segments[0] ?? ''),
            (string) ($segments[count($segments) - 1] ?? ''),
        ];
    }
}
