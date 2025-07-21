<?php

if (!function_exists('runController')) {
    function runController($fileName, $functionName, $params = [])
    {
        $controllerPath = __DIR__ . '/../../controllers/' . $fileName . '.php';
        if (!file_exists($controllerPath) || !is_readable($controllerPath)) {
            throw new RuntimeException("Controller file not found or not readable: $controllerPath");
        }
        
        require_once $controllerPath;

        if (!function_exists($functionName)) {
            throw new RuntimeException("Function $functionName does not exist in controller $fileName.");
        }

        return call_user_func_array($functionName, $params);
    }
}

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

        $path = ROOT_DIR . $file ?? $file;

        if (!file_exists($path) || !is_readable($path)) {
            throw new RuntimeException("View file not found or not readable: $path");
        }

        if (!empty($params)) extract($params, EXTR_SKIP);
        include $path;
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
            'image' => 'sneat/img/illustrations/page-misc-error-light.png',
            'title' => '404 Page Not Found',
            'message' => 'Oops! 😖 The requested URL was not found on this server.',
        ]);
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
        $isCurrentLogin = hasData($_SESSION, $param);
        if (!$isCurrentLogin && $redirect) {
            $path = !empty($path) ? $path : '?_p=' . REDIRECT_LOGIN;
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

        $listPermission = getSession('permissions');

        // If '*' exists in listPermission, grant all permissions
        if (!empty($listPermission) && in_array('*', $listPermission)) {
            // If $params is an array, set each item to true
            if (is_array($params)) {
                return array_fill_keys($params, true);
            }
            // If $params is a string, return true
            return true;
        }

        // Check permissions for each item in params if it's an array
        if (is_array($params) && !empty($listPermission)) {
            $perm = [];
            foreach ($params as $slug) {
                $perm[$slug] = in_array($slug, $listPermission) ? true : false;
            }
            return $perm;
        }

        // If params is a string, check if it exists in listPermission
        if (is_string($params) && !empty($listPermission)) {
            return in_array($params, $listPermission);
        }

        return false;
    }
}

/**
 * Checks and enforces page-level permission for the current user.
 *
 * This function checks if the current user has the required permission (from the global $permission variable).
 * If permission is granted, it returns true. If not, and $render is true, it renders a 403 error page and exits.
 * Otherwise, it sets the HTTP response code to 403 and outputs a simple error message.
 *
 * @param bool $render Whether to render the 403 error page and exit on failure (default: true).
 * @return bool Returns true if permission is granted, false otherwise.
 */
if (!function_exists('requirePagePermission ')) {
    function requirePagePermission ($render = true)
    {
        global $permission;

        if (permission($permission ?? null)) {
            return true;
        }

        if ($render) {
            show_403();
            exit;
        }

        http_response_code(403);
        echo "403 - No permission";
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
        return currentRoleID() == 1 && currentRank() == 9000 ? true : false;
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