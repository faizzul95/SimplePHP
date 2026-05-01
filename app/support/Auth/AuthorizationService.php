<?php

namespace App\Support\Auth;

class AuthorizationService
{
    private array $config;
    private array $userRoleIdsCache = [];
    private array $rolesCache = [];
    private array $permissionsCache = [];

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function hasAbility(string $ability, callable $collectRequestAbilities): bool
    {
        $ability = trim($ability);
        if ($ability === '') {
            return false;
        }

        $abilities = $collectRequestAbilities();
        if (in_array('*', $abilities, true)) {
            return true;
        }

        return in_array($ability, $abilities, true);
    }

    public function roles(
        ?int $userId,
        callable $resolveAclUserId,
        callable $aclCacheKey,
        callable $sessionRoleFallback,
        callable $safeColumn,
        callable $safeTable
    ): array {
        $resolvedUserId = $resolveAclUserId($userId);
        if ($resolvedUserId < 1) {
            return [];
        }

        $roleCacheKey = $aclCacheKey($resolvedUserId);
        if (array_key_exists($roleCacheKey, $this->rolesCache)) {
            return $this->rolesCache[$roleCacheKey];
        }

        $rbac = (array) ($this->config['rbac'] ?? []);
        if (($rbac['enabled'] ?? true) !== true) {
            return [];
        }

        $tables = (array) ($rbac['tables'] ?? []);
        $roleCols = (array) ($rbac['role_columns'] ?? []);

        $roleIdColumn = $safeColumn((string) ($roleCols['id'] ?? 'id'));
        $roleNameColumn = $safeColumn((string) ($roleCols['name'] ?? 'role_name'), 'role_name');
        $roleRankColumn = $safeColumn((string) ($roleCols['rank'] ?? 'role_rank'), 'role_rank');
        $roleStatusColumn = $safeColumn((string) ($roleCols['status'] ?? 'role_status'), 'role_status');
        $rolesTable = $safeTable((string) ($tables['roles'] ?? 'master_roles'));

        $roleIds = $this->userRoleIds($resolvedUserId, $aclCacheKey, $safeColumn, $safeTable);
        if (empty($roleIds)) {
            return $this->rolesCache[$roleCacheKey] = $sessionRoleFallback($resolvedUserId);
        }

        $query = \db()->table($rolesTable)
            ->select(implode(', ', [$roleIdColumn, $roleNameColumn, $roleRankColumn]))
            ->whereIn($roleIdColumn, $roleIds);

        if (($rbac['only_active_roles'] ?? true) === true) {
            $query->where($roleStatusColumn, 1);
        }

        $rows = $query->safeOutput()->get();
        if (!is_array($rows) || empty($rows)) {
            return $this->rolesCache[$roleCacheKey] = $sessionRoleFallback($resolvedUserId);
        }

        $roles = [];
        foreach ($rows as $row) {
            $roles[] = [
                'id' => isset($row[$roleIdColumn]) ? (int) $row[$roleIdColumn] : null,
                'name' => (string) ($row[$roleNameColumn] ?? ''),
                'rank' => isset($row[$roleRankColumn]) ? (int) $row[$roleRankColumn] : null,
            ];
        }

        return $this->rolesCache[$roleCacheKey] = $roles;
    }

    public function hasRole(
        string|int $role,
        ?int $userId,
        callable $resolveAclUserId,
        callable $aclCacheKey,
        callable $sessionRoleFallback,
        callable $safeColumn,
        callable $safeTable
    ): bool {
        $roleValue = is_string($role) ? trim($role) : $role;
        if ($roleValue === '' || $roleValue === 0) {
            return false;
        }

        foreach ($this->roles($userId, $resolveAclUserId, $aclCacheKey, $sessionRoleFallback, $safeColumn, $safeTable) as $assignedRole) {
            if (is_int($roleValue) && (int) ($assignedRole['id'] ?? 0) === $roleValue) {
                return true;
            }

            if (is_string($roleValue) && strcasecmp((string) ($assignedRole['name'] ?? ''), $roleValue) === 0) {
                return true;
            }
        }

        return false;
    }

    public function hasAnyRole(
        array|string $roles,
        ?int $userId,
        callable $resolveAclUserId,
        callable $aclCacheKey,
        callable $sessionRoleFallback,
        callable $safeColumn,
        callable $safeTable
    ): bool {
        $roleList = is_array($roles) ? $roles : array_map('trim', explode(',', $roles));
        foreach ($roleList as $role) {
            if ($this->hasRole($role, $userId, $resolveAclUserId, $aclCacheKey, $sessionRoleFallback, $safeColumn, $safeTable)) {
                return true;
            }
        }

        return false;
    }

    public function hasAllRoles(
        array $roles,
        ?int $userId,
        callable $resolveAclUserId,
        callable $aclCacheKey,
        callable $sessionRoleFallback,
        callable $safeColumn,
        callable $safeTable
    ): bool {
        if (empty($roles)) {
            return false;
        }

        foreach ($roles as $role) {
            if (!$this->hasRole($role, $userId, $resolveAclUserId, $aclCacheKey, $sessionRoleFallback, $safeColumn, $safeTable)) {
                return false;
            }
        }

        return true;
    }

    public function permissions(
        ?int $userId,
        bool $includeRequestAbilities,
        callable $resolveAclUserId,
        callable $aclCacheKey,
        callable $safeColumn,
        callable $safeTable,
        callable $normalizeStringList,
        callable $collectRequestAbilities
    ): array {
        $resolvedUserId = $resolveAclUserId($userId);
        if ($resolvedUserId < 1) {
            return [];
        }

        $permissionCacheKey = $aclCacheKey($resolvedUserId, $includeRequestAbilities);
        if (array_key_exists($permissionCacheKey, $this->permissionsCache)) {
            return $this->permissionsCache[$permissionCacheKey];
        }

        $permissions = [];
        $isCurrentUser = $userId === null;
        $rbac = (array) ($this->config['rbac'] ?? []);

        if (($rbac['enabled'] ?? true) === true) {
            $tables = (array) ($rbac['tables'] ?? []);
            $permissionCols = (array) ($rbac['permission_columns'] ?? []);
            $abilityCols = (array) ($rbac['ability_columns'] ?? []);

            $permissionsTable = $safeTable((string) ($tables['permissions'] ?? 'system_permission'));
            $abilitiesTable = $safeTable((string) ($tables['abilities'] ?? 'system_abilities'));

            $permissionRoleColumn = $safeColumn((string) ($permissionCols['role_id'] ?? 'role_id'));
            $permissionAbilityColumn = $safeColumn((string) ($permissionCols['ability_id'] ?? 'abilities_id'));
            $abilityIdColumn = $safeColumn((string) ($abilityCols['id'] ?? 'id'));
            $abilitySlugColumn = $safeColumn((string) ($abilityCols['slug'] ?? 'abilities_slug'), 'abilities_slug');

            $roleIds = $this->userRoleIds($resolvedUserId, $aclCacheKey, $safeColumn, $safeTable);
            if (!empty($roleIds)) {
                $permissionRows = \db()->table($permissionsTable)
                    ->select($permissionAbilityColumn)
                    ->whereIn($permissionRoleColumn, $roleIds)
                    ->safeOutput()
                    ->get();

                $abilityIds = [];
                if (is_array($permissionRows)) {
                    foreach ($permissionRows as $row) {
                        $abilityId = (int) ($row[$permissionAbilityColumn] ?? 0);
                        if ($abilityId > 0) {
                            $abilityIds[] = $abilityId;
                        }
                    }
                }

                $abilityIds = array_values(array_unique($abilityIds));
                if (!empty($abilityIds)) {
                    $abilityRows = \db()->table($abilitiesTable)
                        ->select(implode(', ', [$abilityIdColumn, $abilitySlugColumn]))
                        ->whereIn($abilityIdColumn, $abilityIds)
                        ->safeOutput()
                        ->get();

                    if (is_array($abilityRows)) {
                        foreach ($abilityRows as $row) {
                            $slug = trim((string) ($row[$abilitySlugColumn] ?? ''));
                            if ($slug !== '') {
                                $permissions[] = $slug;
                            }
                        }
                    }
                }
            }
        }

        if ($includeRequestAbilities && $isCurrentUser) {
            $permissions = array_merge($permissions, $collectRequestAbilities());
        }

        return $this->permissionsCache[$permissionCacheKey] = $normalizeStringList($permissions);
    }

    public function hasPermission(
        string $permission,
        ?int $userId,
        callable $resolveAclUserId,
        callable $aclCacheKey,
        callable $safeColumn,
        callable $safeTable,
        callable $normalizeStringList,
        callable $collectRequestAbilities
    ): bool {
        $permission = trim($permission);
        if ($permission === '') {
            return false;
        }

        $permissions = $this->permissions(
            $userId,
            $userId === null,
            $resolveAclUserId,
            $aclCacheKey,
            $safeColumn,
            $safeTable,
            $normalizeStringList,
            $collectRequestAbilities
        );

        if (in_array('*', $permissions, true)) {
            return true;
        }

        return in_array($permission, $permissions, true);
    }

    public function hasAnyPermission(
        array|string $permissions,
        ?int $userId,
        callable $resolveAclUserId,
        callable $aclCacheKey,
        callable $safeColumn,
        callable $safeTable,
        callable $normalizeStringList,
        callable $collectRequestAbilities
    ): bool {
        $permissionList = is_array($permissions) ? $permissions : array_map('trim', explode(',', $permissions));
        foreach ($permissionList as $permission) {
            if ($this->hasPermission((string) $permission, $userId, $resolveAclUserId, $aclCacheKey, $safeColumn, $safeTable, $normalizeStringList, $collectRequestAbilities)) {
                return true;
            }
        }

        return false;
    }

    public function hasAllPermissions(
        array $permissions,
        ?int $userId,
        callable $resolveAclUserId,
        callable $aclCacheKey,
        callable $safeColumn,
        callable $safeTable,
        callable $normalizeStringList,
        callable $collectRequestAbilities
    ): bool {
        if (empty($permissions)) {
            return false;
        }

        foreach ($permissions as $permission) {
            if (!$this->hasPermission((string) $permission, $userId, $resolveAclUserId, $aclCacheKey, $safeColumn, $safeTable, $normalizeStringList, $collectRequestAbilities)) {
                return false;
            }
        }

        return true;
    }

    public function can(
        string $permission,
        ?int $userId,
        callable $resolveAclUserId,
        callable $aclCacheKey,
        callable $safeColumn,
        callable $safeTable,
        callable $normalizeStringList,
        callable $collectRequestAbilities
    ): bool {
        return $this->hasPermission($permission, $userId, $resolveAclUserId, $aclCacheKey, $safeColumn, $safeTable, $normalizeStringList, $collectRequestAbilities);
    }

    public function cannot(
        string $permission,
        ?int $userId,
        callable $resolveAclUserId,
        callable $aclCacheKey,
        callable $safeColumn,
        callable $safeTable,
        callable $normalizeStringList,
        callable $collectRequestAbilities
    ): bool {
        return !$this->can($permission, $userId, $resolveAclUserId, $aclCacheKey, $safeColumn, $safeTable, $normalizeStringList, $collectRequestAbilities);
    }

    public function invalidate(?int $userId = null): void
    {
        if ($userId === null || $userId < 1) {
            $this->userRoleIdsCache = [];
            $this->rolesCache = [];
            $this->permissionsCache = [];
            return;
        }

        foreach (array_keys($this->userRoleIdsCache) as $key) {
            if (str_starts_with($key, $userId . ':')) {
                unset($this->userRoleIdsCache[$key]);
            }
        }

        foreach (array_keys($this->rolesCache) as $key) {
            if (str_starts_with($key, $userId . ':')) {
                unset($this->rolesCache[$key]);
            }
        }

        foreach (array_keys($this->permissionsCache) as $key) {
            if (str_starts_with($key, $userId . ':')) {
                unset($this->permissionsCache[$key]);
            }
        }
    }

    public function userRoleIds(int $userId, callable $aclCacheKey, callable $safeColumn, callable $safeTable): array
    {
        if ($userId < 1) {
            return [];
        }

        $roleIdsCacheKey = $aclCacheKey($userId);
        if (array_key_exists($roleIdsCacheKey, $this->userRoleIdsCache)) {
            return $this->userRoleIdsCache[$roleIdsCacheKey];
        }

        $rbac = (array) ($this->config['rbac'] ?? []);
        if (($rbac['enabled'] ?? true) !== true) {
            return [];
        }

        $tables = (array) ($rbac['tables'] ?? []);
        $profileCols = (array) ($rbac['user_profile_columns'] ?? []);

        $profileTable = $safeTable((string) ($tables['user_profile'] ?? 'user_profile'));
        $userIdColumn = $safeColumn((string) ($profileCols['user_id'] ?? 'user_id'));
        $roleIdColumn = $safeColumn((string) ($profileCols['role_id'] ?? 'role_id'));
        $statusColumn = $safeColumn((string) ($profileCols['status'] ?? 'profile_status'));

        $query = \db()->table($profileTable)
            ->select($roleIdColumn)
            ->where($userIdColumn, $userId);

        if (($rbac['only_active_profiles'] ?? true) === true) {
            $query->where($statusColumn, 1);
        }

        $rows = $query->safeOutput()->get();
        if (!is_array($rows)) {
            return [];
        }

        $roleIds = [];
        foreach ($rows as $row) {
            $roleId = (int) ($row[$roleIdColumn] ?? 0);
            if ($roleId > 0) {
                $roleIds[] = $roleId;
            }
        }

        return $this->userRoleIdsCache[$roleIdsCacheKey] = array_values(array_unique($roleIds));
    }
}