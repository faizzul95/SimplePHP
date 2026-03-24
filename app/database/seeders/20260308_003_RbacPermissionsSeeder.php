<?php

use Core\Database\Schema\Seeder;

return new class extends Seeder
{
    protected string $table = 'system_abilities';
    protected string $connection = 'default';

    public function run(): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $adminAbilitySlugs = [
            'management-view',
            'rbac-abilities-view',
            'rbac-abilities-create',
            'rbac-abilities-update',
            'rbac-email-view',
            'rbac-email-create',
            'rbac-email-update',
            'rbac-roles-view',
            'rbac-roles-update',
            'user-view',
            'user-create',
            'user-update',
        ];

        $abilities = [
            ['abilities_name' => 'Full Access', 'abilities_slug' => '*', 'abilities_desc' => 'Full access to all protected routes and actions.'],
            ['abilities_name' => 'Management Dashboard', 'abilities_slug' => 'management-view', 'abilities_desc' => 'Access management dashboard metrics and admin views.'],
            ['abilities_name' => 'RBAC Abilities View', 'abilities_slug' => 'rbac-abilities-view', 'abilities_desc' => 'View abilities and ability maintenance screens.'],
            ['abilities_name' => 'RBAC Abilities Create', 'abilities_slug' => 'rbac-abilities-create', 'abilities_desc' => 'Create new abilities.'],
            ['abilities_name' => 'RBAC Abilities Update', 'abilities_slug' => 'rbac-abilities-update', 'abilities_desc' => 'Update existing abilities.'],
            ['abilities_name' => 'RBAC Abilities Delete', 'abilities_slug' => 'rbac-abilities-delete', 'abilities_desc' => 'Delete abilities.'],
            ['abilities_name' => 'RBAC Email View', 'abilities_slug' => 'rbac-email-view', 'abilities_desc' => 'Manage email template screens.'],
            ['abilities_name' => 'RBAC Email Create', 'abilities_slug' => 'rbac-email-create', 'abilities_desc' => 'Create email templates.'],
            ['abilities_name' => 'RBAC Email Update', 'abilities_slug' => 'rbac-email-update', 'abilities_desc' => 'Update email templates.'],
            ['abilities_name' => 'RBAC Email Delete', 'abilities_slug' => 'rbac-email-delete', 'abilities_desc' => 'Delete email templates.'],
            ['abilities_name' => 'RBAC Roles View', 'abilities_slug' => 'rbac-roles-view', 'abilities_desc' => 'View roles and permission assignment screens.'],
            ['abilities_name' => 'RBAC Roles Create', 'abilities_slug' => 'rbac-roles-create', 'abilities_desc' => 'Create new roles.'],
            ['abilities_name' => 'RBAC Roles Update', 'abilities_slug' => 'rbac-roles-update', 'abilities_desc' => 'Update existing roles and assignments.'],
            ['abilities_name' => 'RBAC Roles Delete', 'abilities_slug' => 'rbac-roles-delete', 'abilities_desc' => 'Delete roles.'],
            ['abilities_name' => 'Upload Image', 'abilities_slug' => 'settings-upload-image', 'abilities_desc' => 'Upload and remove protected images.'],
            ['abilities_name' => 'User View', 'abilities_slug' => 'user-view', 'abilities_desc' => 'View user directory records and detail screens.'],
            ['abilities_name' => 'User Create', 'abilities_slug' => 'user-create', 'abilities_desc' => 'Create new user accounts.'],
            ['abilities_name' => 'User Update', 'abilities_slug' => 'user-update', 'abilities_desc' => 'Update user records and reset passwords.'],
            ['abilities_name' => 'User Delete', 'abilities_slug' => 'user-delete', 'abilities_desc' => 'Delete user accounts.'],
        ];

        foreach ($abilities as $index => &$ability) {
            $ability['id'] = $index + 1;
            $ability['deleted_at'] = null;
            $ability['updated_at'] = $timestamp;
        }
        unset($ability);

        $desiredIdBySlug = [];
        foreach ($abilities as $ability) {
            $desiredIdBySlug[$ability['abilities_slug']] = $ability['id'];
        }

        $existingAbilities = db()->table($this->table)->select('id, abilities_slug')->get();
        $currentSlugById = [];
        foreach ($existingAbilities as $existingAbility) {
            $currentSlugById[(int) $existingAbility['id']] = $existingAbility['abilities_slug'];
        }

        $existingPermissionSnapshots = [];
        $existingPermissions = db()->table('system_permission')->select('role_id, abilities_id, access_device_type')->get();
        foreach ($existingPermissions as $existingPermission) {
            $abilitiesSlug = $currentSlugById[(int) $existingPermission['abilities_id']] ?? null;
            if ($abilitiesSlug === null) {
                continue;
            }

            $existingPermissionSnapshots[] = [
                'role_id' => (int) $existingPermission['role_id'],
                'abilities_slug' => $abilitiesSlug,
                'access_device_type' => (int) ($existingPermission['access_device_type'] ?? 1),
            ];
        }

        if (!empty($existingAbilities)) {
            $this->execute('DELETE FROM `system_permission`');

            $temporaryId = $this->nextTemporaryAbilityId($existingAbilities);
            foreach ($existingAbilities as $existingAbility) {
                if (!isset($desiredIdBySlug[$existingAbility['abilities_slug']])) {
                    continue;
                }

                db()->table($this->table)
                    ->where('id', $existingAbility['id'])
                    ->update([
                        'id' => $temporaryId++,
                        'updated_at' => $timestamp,
                    ]);
            }
        }

        foreach ($abilities as $ability) {
            $this->syncAbility($ability, $timestamp);
        }

        $this->removeDuplicateAbilityRows($desiredIdBySlug);

        $abilityIdBySlug = $this->getAbilityIdMap();

        $permissions = [
            ['role_id' => 1, 'abilities_slug' => '*', 'access_device_type' => 1],
            ['role_id' => 2, 'abilities_slug' => 'management-view', 'access_device_type' => 1],
            ['role_id' => 2, 'abilities_slug' => 'rbac-abilities-view', 'access_device_type' => 1],
            ['role_id' => 2, 'abilities_slug' => 'rbac-abilities-create', 'access_device_type' => 1],
            ['role_id' => 2, 'abilities_slug' => 'rbac-abilities-update', 'access_device_type' => 1],
            ['role_id' => 2, 'abilities_slug' => 'rbac-email-view', 'access_device_type' => 1],
            ['role_id' => 2, 'abilities_slug' => 'rbac-email-create', 'access_device_type' => 1],
            ['role_id' => 2, 'abilities_slug' => 'rbac-email-update', 'access_device_type' => 1],
            ['role_id' => 2, 'abilities_slug' => 'rbac-roles-view', 'access_device_type' => 1],
            ['role_id' => 2, 'abilities_slug' => 'rbac-roles-update', 'access_device_type' => 1],
            ['role_id' => 2, 'abilities_slug' => 'user-view', 'access_device_type' => 1],
            ['role_id' => 2, 'abilities_slug' => 'user-create', 'access_device_type' => 1],
            ['role_id' => 2, 'abilities_slug' => 'user-update', 'access_device_type' => 1],
        ];

        $permissionRows = [];
        foreach ($existingPermissionSnapshots as $snapshot) {
            if ($snapshot['role_id'] === 2 && !in_array($snapshot['abilities_slug'], $adminAbilitySlugs, true)) {
                continue;
            }

            $abilityId = $abilityIdBySlug[$snapshot['abilities_slug']] ?? null;
            if ($abilityId === null) {
                continue;
            }

            $permissionRows[] = [
                'role_id' => $snapshot['role_id'],
                'abilities_id' => $abilityId,
                'access_device_type' => $snapshot['access_device_type'],
            ];
        }

        foreach ($permissions as $permission) {
            $abilityId = $abilityIdBySlug[$permission['abilities_slug']] ?? null;
            if ($abilityId === null) {
                continue;
            }

            $permissionRows[] = [
                'role_id' => $permission['role_id'],
                'abilities_id' => $abilityId,
                'access_device_type' => $permission['access_device_type'],
            ];
        }

        foreach ($this->uniquePermissions($permissionRows) as $permission) {
            $this->insertOrUpdate('system_permission', [
                'role_id' => $permission['role_id'],
                'abilities_id' => $permission['abilities_id'],
                'access_device_type' => $permission['access_device_type'],
            ], [
                'role_id' => $permission['role_id'],
                'abilities_id' => $permission['abilities_id'],
                'access_device_type' => $permission['access_device_type'],
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }
    }

    protected function getAbilityIdMap(): array
    {
        $map = [];
        $abilities = db()->table($this->table)->select('id, abilities_slug')->get();

        foreach ($abilities as $ability) {
            $map[$ability['abilities_slug']] = (int) $ability['id'];
        }

        return $map;
    }

    protected function nextTemporaryAbilityId(array $existingAbilities): int
    {
        $maxId = 0;

        foreach ($existingAbilities as $existingAbility) {
            $maxId = max($maxId, (int) $existingAbility['id']);
        }

        return $maxId + 1000;
    }

    protected function syncAbility(array $ability, string $timestamp): void
    {
        $existingAbility = null;
        $existingAbilities = db()->table($this->table)
            ->select('id, abilities_slug')
            ->orderBy('id', 'ASC')
            ->get();

        foreach ($existingAbilities as $candidate) {
            if ($candidate['abilities_slug'] === $ability['abilities_slug']) {
                $existingAbility = $candidate;
                break;
            }
        }

        if ($existingAbility) {
            db()->table($this->table)
                ->where('id', $existingAbility['id'])
                ->update($ability);
            return;
        }

        db()->table($this->table)->insert(array_merge($ability, [
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]));
    }

    protected function removeDuplicateAbilityRows(array $desiredIdBySlug): void
    {
        $abilities = db()->table($this->table)
            ->select('id, abilities_slug')
            ->orderBy('id', 'ASC')
            ->get();

        $idsBySlug = [];
        foreach ($abilities as $ability) {
            $idsBySlug[$ability['abilities_slug']][] = (int) $ability['id'];
        }

        foreach ($idsBySlug as $slug => $ids) {
            if (count($ids) < 2) {
                continue;
            }

            $canonicalId = $desiredIdBySlug[$slug] ?? min($ids);
            foreach ($ids as $id) {
                if ($id === $canonicalId) {
                    continue;
                }

                $this->execute("DELETE FROM `{$this->table}` WHERE `id` = {$id}");
            }
        }
    }

    protected function uniquePermissions(array $permissions): array
    {
        $unique = [];

        foreach ($permissions as $permission) {
            $key = implode(':', [
                $permission['role_id'],
                $permission['abilities_id'],
                $permission['access_device_type'],
            ]);

            $unique[$key] = $permission;
        }

        return array_values($unique);
    }
};