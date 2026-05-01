<?php

/*
|--------------------------------------------------------------------------
| VIEW RENDERING  
|--------------------------------------------------------------------------
*/

if (!function_exists('render')) {
    function render($file = null,  $params = [])
    {
        if (empty($file)) {
            throw new InvalidArgumentException('No view file specified.');
        }

        $target = (string) $file;
        $content = blade_engine()->render($target, is_array($params) ? $params : []);
        echo $content;
    }
}

if (!function_exists('show_403')) {
    function show_403()
    {
        render(REDIRECT_403, [
            'image' => 'general/images/nodata/403.png',
            'title' => '403',
            'message' => 'Not authorize to view this page ⚠️',
        ]);
    }
}

if (!function_exists('show_404')) {
    function show_404()
    {
        render(REDIRECT_404, [
            'image' => 'general/images/nodata/403.png',
            'title' => '404 Page Not Found',
            'message' => 'Oops! 😖 The requested URL was not found on this server.',
        ]);
    }
}

if (!function_exists('modalPartialAlert')) {
    function modalPartialAlert(string $message): string
    {
        return '<div class="alert alert-danger" role="alert">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>';
    }
}

if (!function_exists('renderModalPartial')) {
    function renderModalPartial($filePath = '', $dataArray = []): array
    {
        try {
            $normalizedPath = security()->normalizeRelativeProjectPath((string) $filePath);
        } catch (\InvalidArgumentException $e) {
            return [
                'status' => 422,
                'content' => modalPartialAlert('Invalid modal file path.'),
            ];
        }

        if (!str_starts_with($normalizedPath, 'views/')) {
            return [
                'status' => 422,
                'content' => modalPartialAlert('Invalid modal file path.'),
            ];
        }

        if (!str_ends_with($normalizedPath, '.php') && !str_ends_with($normalizedPath, '.blade.php')) {
            return [
                'status' => 422,
                'content' => modalPartialAlert('Invalid file type.'),
            ];
        }

        $partialName = pathinfo($normalizedPath, PATHINFO_FILENAME);
        if (!str_starts_with((string) $partialName, '_')) {
            return [
                'status' => 422,
                'content' => modalPartialAlert('Invalid modal partial.'),
            ];
        }

        $viewPath = 'app/' . ltrim($normalizedPath, '/');
        $absolute = realpath(ROOT_DIR . $viewPath);
        $allowedDir = realpath(ROOT_DIR . 'app/views');

        if ($absolute === false || $allowedDir === false) {
            return [
                'status' => 404,
                'content' => modalPartialAlert('File not found.'),
            ];
        }

        $normalizedAbsolute = str_replace('\\', '/', $absolute);
        $normalizedAllowedDir = rtrim(str_replace('\\', '/', $allowedDir), '/') . '/';
        if (!str_starts_with($normalizedAbsolute, $normalizedAllowedDir)) {
            return [
                'status' => 404,
                'content' => modalPartialAlert('File not found.'),
            ];
        }

        if (!is_readable($absolute)) {
            return [
                'status' => 404,
                'content' => modalPartialAlert('File does not exist: ' . $viewPath),
            ];
        }

        $__modalData = is_array($dataArray) ? array_map(static function ($value) {
            return is_scalar($value) || $value === null ? $value : null;
        }, $dataArray) : [];

        $relativePath = ltrim(substr($normalizedAbsolute, strlen(rtrim($normalizedAllowedDir, '/'))), '/');
        $relativePath = preg_replace('/\.(blade\.)?php$/', '', $relativePath) ?? $relativePath;
        $viewName = str_replace('/', '.', $relativePath);
        $content = blade_engine()->render($viewName, $__modalData);

        return [
            'status' => 200,
            'content' => is_string($content) ? $content : '',
        ];
    }
}

/*
|--------------------------------------------------------------------------
| SESSION & PERMISSION  
|--------------------------------------------------------------------------
*/

if (!function_exists('isLogin')) {
    function isLogin($redirect = true, $param = 'isLoggedIn', $path = null)
    {
        $isCurrentLogin = $param === 'isLoggedIn'
            ? isAuthenticated(['session', 'token', 'oauth2'])
            : hasData($_SESSION, $param);

        if (!$isCurrentLogin && $redirect) {
            $path = !empty($path) ? $path : url(REDIRECT_LOGIN);
            redirect($path, true);
        }

        return $isCurrentLogin;
    }
}

/**
 * Check if the user has the specified permissions.
 *
 * This function checks if a user has one or multiple specified permissions.
 * If the wildcard '*' is present in the user's permission list, it grants all permissions automatically.
 *
 * @param string|array $params A single permission slug (string) or an array of permission slugs to check.
 * @return mixed Returns true if '*' exists or the specified permission exists in the list.
 *               Returns an associative array with each permission set to true if '*' is present or if each permission exists in the list.
 */
if (!function_exists('permission')) {
    function permission($params = null)
    {
        if (empty($params) || $params === true) {
            return true;
        }

        if (function_exists('auth')) {
            if (is_array($params)) {
                $permissions = [];
                foreach ($params as $slug) {
                    $slug = trim((string) $slug);
                    if ($slug === '') {
                        continue;
                    }

                    $permissions[$slug] = auth()->can($slug);
                }

                return $permissions;
            }

            if (is_string($params)) {
                return auth()->can(trim($params));
            }
        }

        $listPermission = getSession('permissions');
        if (empty($listPermission) || !is_array($listPermission)) {
            return false;
        }

        if (in_array('*', $listPermission, true)) {
            return is_array($params)
                ? array_fill_keys(array_map(static fn($slug) => trim((string) $slug), $params), true)
                : true;
        }

        if (is_array($params)) {
            $perm = [];
            foreach ($params as $slug) {
                $slug = trim((string) $slug);
                if ($slug === '') {
                    continue;
                }

                $perm[$slug] = in_array($slug, $listPermission, true);
            }

            return $perm;
        }

        if (is_string($params)) {
            return in_array(trim($params), $listPermission, true);
        }

        return false;
    }
}

/*
|--------------------------------------------------------------------------
| CUSTOM HELPER BY PROJECT
|--------------------------------------------------------------------------
*/

/**
 * Extracts the abilities_slug values from a given permission array.
 *
 * This function iterates through an array of permissions to gather unique ability slugs.
 * If the abilities_slug contains a wildcard '*', it returns ['*'] immediately, ignoring other entries.
 * If no wildcard is found, it collects all unique abilities_slug values.
 *
 * @param array|null $permission Array of permission data containing nested abilities.
 * @return array Returns an array of abilities slugs. If '*' is present, returns ['*'].
 */
if (!function_exists('getPermissionSlug')) {
    function getPermissionSlug($permission = null)
    {
        $slug = [];

        // Check if the permission array is valid
        if (empty($permission) || !is_array($permission)) {
            return $slug;
        }

        foreach ($permission as $perm) {
            // Check if abilities key exists and has an abilities_slug
            if (isset($perm['abilities']['abilities_slug'])) {
                $abilitySlug = $perm['abilities']['abilities_slug'];

                // If the wildcard '*' is found, return ['*'] immediately
                if ($abilitySlug === '*') {
                    return ['*'];
                }

                // Add the ability slug if it's not already in the slug array
                $slug[] = $abilitySlug;
            }
        }

        // Return unique slugs
        return array_unique($slug);
    }
}

if (!function_exists('currentUserID')) {
    function currentUserID()
    {
        return getSession('userID');
    }
}

if (!function_exists('currentRank')) {
    function currentRank()
    {
        return getSession('roleRank');
    }
}

if (!function_exists('isSuperadmin')) {
    function isSuperadmin()
    {
        return currentRoleID() == 1 && (int) currentRank() >= 9999 ? true : false;
    }
}

if (!function_exists('isAdmin')) {
    function isAdmin()
    {
        return currentRoleID() == 2 ? true : false;
    }
}

if (!function_exists('currentRoleID')) {
    function currentRoleID()
    {
        return getSession('roleID') ?? '0';
    }
}

if (!function_exists('currentUserFullname')) {
    function currentUserFullname()
    {
        return getSession('userFullName') ?? 'Guest User';
    }
}

if (!function_exists('currentUserRoleName')) {
    function currentUserRoleName()
    {
        return getSession('roleName') ?? 'Guest';
    }
}

if (!function_exists('currentUserAvatar')) {
    function currentUserAvatar()
    {
        return getSession('userAvatar') ?? 'public/upload/default.jpg';
    }
}