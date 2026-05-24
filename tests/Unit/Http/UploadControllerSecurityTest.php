<?php

declare(strict_types=1);

use App\Http\Controllers\UploadController;
use PHPUnit\Framework\TestCase;

final class UploadControllerSecurityProbe extends UploadController
{
    public ?int $stubCurrentUserId = null;
    public bool $stubHasManagePermission = false;
    public ?string $lastPermissionChecked = null;

    public function __construct()
    {
    }

    public function allowsRecord(array $record): bool
    {
        return $this->isManagedProfileUploadRecord($record);
    }

    public function matchesRecord(array $record, string $entityId, string $entityType, string $entityFileType): bool
    {
        return $this->recordMatchesSubmittedEntity($record, $entityId, $entityType, $entityFileType);
    }

    public function probeCanEditEntity(string $entityType, mixed $entityId): bool
    {
        return $this->canEditEntity($entityType, $entityId);
    }

    protected function currentUserId(): int|string|null
    {
        return $this->stubCurrentUserId;
    }

    protected function userHasPermission(string $permission): bool
    {
        $this->lastPermissionChecked = $permission;
        return $this->stubHasManagePermission;
    }
}

final class UploadControllerSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['config']['framework']['upload_guards']['image-cropper'] = [
            'entity_types' => ['users'],
            'entity_file_types' => ['USER_PROFILE', 'avatar'],
            'folder_groups' => ['directory'],
            'folder_types' => ['avatar'],
        ];
    }

    public function testManagedProfileUploadRecordMatchesConfiguredPolicy(): void
    {
        $controller = new UploadControllerSecurityProbe();

        self::assertTrue($controller->allowsRecord([
            'entity_type' => 'users',
            'entity_file_type' => 'USER_PROFILE',
            'files_folder' => 'public/upload/directory/42/avatar',
        ]));
    }

    public function testManagedProfileUploadRecordRejectsUnrelatedFiles(): void
    {
        $controller = new UploadControllerSecurityProbe();

        self::assertFalse($controller->allowsRecord([
            'entity_type' => 'users',
            'entity_file_type' => 'invoice_pdf',
            'files_folder' => 'public/upload/billing/42/pdf',
        ]));
    }

    public function testManagedProfileUploadRecordMustMatchSubmittedEntityBinding(): void
    {
        $controller = new UploadControllerSecurityProbe();

        $record = [
            'entity_id' => '42',
            'entity_type' => 'users',
            'entity_file_type' => 'USER_PROFILE',
        ];

        self::assertTrue($controller->matchesRecord($record, '42', 'users', 'USER_PROFILE'));
        self::assertFalse($controller->matchesRecord($record, '7', 'users', 'USER_PROFILE'));
        self::assertFalse($controller->matchesRecord($record, '42', 'users', 'avatar'));
    }

    public function testOwnerCanAlwaysEditTheirOwnProfileUpload(): void
    {
        $controller = new UploadControllerSecurityProbe();
        $controller->stubCurrentUserId = 42;
        $controller->stubHasManagePermission = false;

        self::assertTrue($controller->probeCanEditEntity('users', '42'));
        self::assertTrue($controller->probeCanEditEntity('users', 42));
        self::assertSame(null, $controller->lastPermissionChecked, 'Owner path should short-circuit before any permission lookup.');
    }

    public function testNonOwnerWithoutManagePermissionIsForbidden(): void
    {
        $controller = new UploadControllerSecurityProbe();
        $controller->stubCurrentUserId = 42;
        $controller->stubHasManagePermission = false;

        self::assertFalse($controller->probeCanEditEntity('users', '7'));
        self::assertSame('user-update', $controller->lastPermissionChecked);
    }

    public function testNonOwnerWithManagePermissionIsAllowed(): void
    {
        $controller = new UploadControllerSecurityProbe();
        $controller->stubCurrentUserId = 42;
        $controller->stubHasManagePermission = true;

        self::assertTrue($controller->probeCanEditEntity('users', '7'));
        self::assertSame('user-update', $controller->lastPermissionChecked);
    }

    public function testEmptyEntityIdIsRejectedEvenForAdmins(): void
    {
        $controller = new UploadControllerSecurityProbe();
        $controller->stubCurrentUserId = 42;
        $controller->stubHasManagePermission = true;

        self::assertFalse($controller->probeCanEditEntity('users', ''));
        self::assertFalse($controller->probeCanEditEntity('users', null));
    }

    public function testUnauthenticatedUserCannotEditEvenMatchingEntityId(): void
    {
        $controller = new UploadControllerSecurityProbe();
        $controller->stubCurrentUserId = null;
        $controller->stubHasManagePermission = false;

        // No current user id means no self-match is possible — falls through to
        // the permission check, which is also denied here.
        self::assertFalse($controller->probeCanEditEntity('users', '7'));
    }

    public function testNonUserEntityTypeNeverQualifiesAsSelfEdit(): void
    {
        $controller = new UploadControllerSecurityProbe();
        $controller->stubCurrentUserId = 42;
        $controller->stubHasManagePermission = false;

        // Self-match only applies to entity_type='users'. Any other type must
        // satisfy the manage-others permission, regardless of id equality.
        self::assertFalse($controller->probeCanEditEntity('companies', '42'));
        self::assertSame('user-update', $controller->lastPermissionChecked);
    }
}