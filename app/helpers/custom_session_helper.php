<?php

if (!function_exists('flashSessionMetaKey')) {
    function flashSessionMetaKey(): string
    {
        return '__flash_meta';
    }
}

if (!function_exists('flashOldInputKey')) {
    function flashOldInputKey(): string
    {
        return '_old_input';
    }
}

if (!function_exists('flashErrorsKey')) {
    function flashErrorsKey(): string
    {
        return '_errors';
    }
}

if (!function_exists('initializeFlashSessionState')) {
    function initializeFlashSessionState(): void
    {
        static $initialized = false;

        if ($initialized || session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $initialized = true;
        $metaKey = flashSessionMetaKey();

        if (!isset($_SESSION[$metaKey]) || !is_array($_SESSION[$metaKey])) {
            $_SESSION[$metaKey] = ['new' => [], 'old' => []];
            return;
        }

        $_SESSION[$metaKey]['new'] = array_values(array_unique(array_filter((array) ($_SESSION[$metaKey]['new'] ?? []), 'is_string')));
        $_SESSION[$metaKey]['old'] = array_values(array_unique(array_filter((array) ($_SESSION[$metaKey]['old'] ?? []), 'is_string')));

        foreach ($_SESSION[$metaKey]['old'] as $flashKey) {
            unset($_SESSION[$flashKey]);
        }

        $_SESSION[$metaKey]['old'] = $_SESSION[$metaKey]['new'];
        $_SESSION[$metaKey]['new'] = [];
    }
}

if (!function_exists('flashSession')) {
    function flashSession(string $key, $value)
    {
        initializeFlashSessionState();

        if (session_status() !== PHP_SESSION_ACTIVE || $key === '') {
            return $value;
        }

        $metaKey = flashSessionMetaKey();
        $_SESSION[$key] = $value;

        $newKeys = (array) ($_SESSION[$metaKey]['new'] ?? []);
        $oldKeys = (array) ($_SESSION[$metaKey]['old'] ?? []);

        if (!in_array($key, $newKeys, true)) {
            $newKeys[] = $key;
        }

        $_SESSION[$metaKey]['new'] = array_values($newKeys);
        $_SESSION[$metaKey]['old'] = array_values(array_filter($oldKeys, static fn($existing) => $existing !== $key));

        return $value;
    }
}

if (!function_exists('getFlashSession')) {
    function getFlashSession(string $key, $default = null)
    {
        initializeFlashSessionState();
        return $_SESSION[$key] ?? $default;
    }
}

if (!function_exists('forgetFlashSession')) {
    function forgetFlashSession(string $key): void
    {
        initializeFlashSessionState();

        if (session_status() !== PHP_SESSION_ACTIVE || $key === '') {
            return;
        }

        unset($_SESSION[$key]);

        $metaKey = flashSessionMetaKey();
        $_SESSION[$metaKey]['new'] = array_values(array_filter((array) ($_SESSION[$metaKey]['new'] ?? []), static fn($existing) => $existing !== $key));
        $_SESSION[$metaKey]['old'] = array_values(array_filter((array) ($_SESSION[$metaKey]['old'] ?? []), static fn($existing) => $existing !== $key));
    }
}

if (!function_exists('sessionArrayValue')) {
    function sessionArrayValue(array $items, ?string $key = null, $default = null)
    {
        if ($key === null || $key === '') {
            return $items;
        }

        $segments = explode('.', $key);
        $value = $items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}

if (!function_exists('oldInput')) {
    function oldInput(?string $key = null, $default = null)
    {
        $oldInput = getFlashSession(flashOldInputKey(), []);
        if (!is_array($oldInput)) {
            $oldInput = [];
        }

        return sessionArrayValue($oldInput, $key, $default);
    }
}

if (!function_exists('old')) {
    function old(?string $key = null, $default = null)
    {
        return oldInput($key, $default);
    }
}

if (!function_exists('validationErrors')) {
    function validationErrors(?string $key = null, $default = [])
    {
        $errors = getFlashSession(flashErrorsKey(), []);
        if (!is_array($errors)) {
            $errors = [];
        }

        return sessionArrayValue($errors, $key, $default);
    }
}

function rawSessionValue($key = null)
{
    if ($key === null) {
        return $_SESSION ?? [];
    }

    return $_SESSION[$key] ?? null;
}

function authSessionResolutionDepth(?int $setValue = null): int
{
    $key = '__auth_session_resolution_depth';

    if (!array_key_exists($key, $GLOBALS) || !is_int($GLOBALS[$key])) {
        $GLOBALS[$key] = 0;
    }

    if ($setValue !== null) {
        $GLOBALS[$key] = max(0, $setValue);
    }

    return (int) $GLOBALS[$key];
}

function isAuthSessionResolutionActive(): bool
{
    return authSessionResolutionDepth() > 0;
}

function withAuthSessionResolution(callable $callback)
{
    authSessionResolutionDepth(authSessionResolutionDepth() + 1);

    try {
        return $callback();
    } finally {
        authSessionResolutionDepth(authSessionResolutionDepth() - 1);
    }
}

function authSessionVirtualData($methods = null)
{
    if (isAuthSessionResolutionActive()) {
        return [];
    }

    if (!function_exists('auth')) {
        return [];
    }

    return withAuthSessionResolution(static function () use ($methods) {
        try {
            $resolvedMethods = $methods;
            if ($resolvedMethods === null) {
                $resolvedMethods = ['session', 'token', 'oauth2'];
            }

            $auth = auth();
            if (!is_object($auth) || !method_exists($auth, 'user')) {
                return [];
            }

            $user = $auth->user($resolvedMethods);
            if (empty($user) || empty($user['id'])) {
                return [];
            }

            $roles = method_exists($auth, 'roles') ? $auth->roles((int) $user['id']) : [];
            $primaryRole = is_array($roles) && !empty($roles) ? (array) $roles[0] : [];
            $fullName = trim((string) ($user['name'] ?? ''));
            $preferredName = trim((string) ($user['preferred_name'] ?? $user['user_preferred_name'] ?? ''));

            return [
                'userID' => (int) $user['id'],
                'userFullName' => $fullName !== '' ? $fullName : ($preferredName !== '' ? $preferredName : 'Guest User'),
                'userNickname' => $preferredName !== '' ? $preferredName : $fullName,
                'userEmail' => (string) ($user['email'] ?? ''),
                'roleID' => (int) ($primaryRole['id'] ?? 0),
                'roleRank' => (int) ($primaryRole['rank'] ?? 0),
                'roleName' => (string) ($primaryRole['name'] ?? 'Guest'),
                'permissions' => method_exists($auth, 'permissions') ? $auth->permissions((int) $user['id'], true) : [],
                'userAvatar' => (string) ($user['userAvatar'] ?? $user['avatar'] ?? 'public/upload/default.jpg'),
                'isLoggedIn' => method_exists($auth, 'check') ? $auth->check($resolvedMethods) : true,
                'auth_via' => method_exists($auth, 'via') ? $auth->via($resolvedMethods) : null,
                'oauth_provider' => (string) ($user['oauth_provider'] ?? ''),
            ];
        } catch (\Throwable $e) {
            return [];
        }
    });
}

function authSessionValue($key = null, $methods = null)
{
    if (isAuthSessionResolutionActive()) {
        return rawSessionValue($key);
    }

    if ($key === null) {
        return array_replace(authSessionVirtualData($methods), rawSessionValue());
    }

    if (array_key_exists($key, rawSessionValue())) {
        return rawSessionValue($key);
    }

    $virtualSession = authSessionVirtualData($methods);
    return $virtualSession[$key] ?? null;
}

function isAuthenticated($methods = null)
{
    if (!function_exists('auth')) {
        return !empty($_SESSION['isLoggedIn']);
    }

    try {
        return auth()->check($methods ?? ['session', 'token', 'oauth2']);
    } catch (\Throwable $e) {
        return !empty($_SESSION['isLoggedIn']);
    }
}

function currentAuthMethod($methods = null)
{
    if (!function_exists('auth')) {
        return !empty($_SESSION['isLoggedIn']) ? 'session' : null;
    }

    try {
        return auth()->via($methods ?? ['session', 'token', 'oauth2']);
    } catch (\Throwable $e) {
        return !empty($_SESSION['isLoggedIn']) ? 'session' : null;
    }
}

function currentAuthUser($methods = null)
{
    if (!function_exists('auth')) {
        return null;
    }

    try {
        return auth()->user($methods ?? ['session', 'token', 'oauth2']);
    } catch (\Throwable $e) {
        return null;
    }
}

function allSession($die = false)
{
    echo '<pre>';
    print_r(authSessionValue());
    echo '</pre>';

    if ($die)
        die;
}

function startSession($param = NULL)
{
    if (!is_array($param) || empty($param)) {
        return rawSessionValue();
    }

    foreach ($param as $sessionName => $sessionValue) {
        $_SESSION[$sessionName] = is_string($sessionValue) ? trim($sessionValue) : $sessionValue;
    }

    return rawSessionValue();
}

function endSession($param = NULL)
{
    if (is_array($param)) {
        foreach ($param as $sessionName) {
            unset($_SESSION[$sessionName]);
        }
    } else {
        unset($_SESSION[$param]);
    }
}

function getSession($param = NULL)
{
    if (is_array($param)) {
        $sessiondata = [];
        foreach ($param as $sessionName) {
            array_push($sessiondata, authSessionValue($sessionName));
        }
        return $sessiondata;
    } else {
        return authSessionValue($param);
    }
}

initializeFlashSessionState();
