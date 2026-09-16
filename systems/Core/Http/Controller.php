<?php

namespace Core\Http;

use Core\View\BladeEngine;

/**
 * Base Controller
 *
 * Provides reusable helper methods for all application controllers.
 * Application controllers should extend this class directly:
 *   use Core\Http\Controller;
 *   class MyController extends Controller { ... }
 *
 * Provides:
 *   - View rendering via the Blade engine
 *   - Record lookup with automatic 404 handling
 *   - The current user's identity and permissions
 *   - Page state for the menu and breadcrumb
 *
 * Deliberately not here: responses and redirects. `ok()`, `fail()` and
 * `redirect()` are global helpers that work identically in a controller, a
 * middleware and a console command, so duplicating them as protected methods
 * only created a second way to do the same thing — and the two drifted.
 */
abstract class Controller
{
    protected const AUTH_METHODS = ['session', 'token', 'oauth2'];

    protected BladeEngine $blade;

    public function __construct()
    {
        $this->blade = blade_engine();
    }

    // ─── View ────────────────────────────────────────────────────────

    /**
     * Render a Blade view and terminate the request.
     *
     * @param string $view  Dot-notation view name (e.g. 'dashboard.admin')
     */
    protected function view(string $view, array $params = []): void
    {
        response()->view($view, $params)->send();
    }

    // ─── Page State ──────────────────────────────────────────────────

    /**
     * Set the global page state used by the menu and breadcrumb.
     *
     * @param string      $page           Active menu group   (e.g. 'rbac')
     * @param string|null $subpage        Active submenu item (e.g. 'roles')
     * @param string      $titleSubPageValue Sub-page / breadcrumb title
     */
    protected function setPageState(
        string $page,
        string|array|null $subpage,
        string $titlePageValue,
        string $titleSubPageValue = ''
    ): void {
        global $titlePage, $titleSubPage, $currentPage, $currentSubPage, $currentMenuTrail;

        $normalizedSubpages = [];
        if (is_array($subpage)) {
            $normalizedSubpages = array_values(array_filter(array_map(static fn($item) => trim((string) $item), $subpage), static fn($item) => $item !== ''));
        } elseif (is_string($subpage) && trim($subpage) !== '') {
            $normalizedSubpages = [trim($subpage)];
        }

        $currentPage = $page;
        $currentSubPage = !empty($normalizedSubpages) ? end($normalizedSubpages) : null;
        $currentMenuTrail = array_merge([$page], $normalizedSubpages);
        $titlePage = $titlePageValue;
        $titleSubPage = $titleSubPageValue;
    }

    // ─── JSON Responses ──────────────────────────────────────────────

    /**
     * Send a JSON response and terminate.
     *
     * @param array $data        Payload (must include a 'code' key)
     * @param int   $httpStatus  HTTP status code (default derives from $data['code'])
     */
    protected function jsonResponse(array $data, int $httpStatus = 0): void
    {
        jsonResponse($data, $httpStatus ?: ($data['code'] ?? 200));
    }

    /**
     * Send a JSON success response and terminate.
     *
     * @param array|null $data     Optional extra payload merged into the response
     */
    protected function successResponse(string $message = 'Success', ?array $data = null, int $code = 200): void
    {
        $response = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $response['data'] = $data;
        }
        jsonResponse($response, $code);
    }

    /** Send a JSON error response and terminate. */
    protected function errorResponse(string $message = 'Error', int $code = 422, array $errors = []): void
    {
        $response = ['code' => $code, 'message' => $message];
        if (!empty($errors)) {
            $response['errors'] = $errors;
        }
        jsonResponse($response, $code);
    }

    // ─── Record Helpers ──────────────────────────────────────────────

    /**
     * Fetch a single record from a table or terminate with 404.
     *
     * @param bool        $softDelete Respect soft-delete (whereNull deleted_at)
     * @return array  The fetched record
     */
    protected function findOrFail(
        string $table,
        int|string $id,
        ?string $select = null,
        bool $softDelete = true,
        string $message = 'Record not found'
    ): array {
        $query = db()->table($table);

        if ($select !== null && $select !== '*') {
            $query->select($select);
        }

        $query->where('id', $id);

        if ($softDelete) {
            $query->whereNull('deleted_at');
        }

        $record = $query->safeOutput()->fetch();

        if (!$record) {
            $this->errorResponse($message ?: 'Record not found', 404);
        }

        return $record;
    }

    // ─── Utility ─────────────────────────────────────────────────────

    /**
     * Get the current authenticated user ID.
     */
    protected function authId(): ?int
    {
        if (function_exists('auth')) {
            try {
                $id = auth()->id($this->authMethods());
                if ($id !== null) {
                    return (int) $id;
                }
            } catch (\Throwable $e) {
            }
        }

        $legacyId = function_exists('authSessionValue')
            ? authSessionValue('userID', $this->authMethods())
            : ($_SESSION['userID'] ?? null);

        return $legacyId !== null ? (int) $legacyId : null;
    }

    /**
     * Get the current authenticated user's data across session, token, and oauth2.
     *
     * @param string|null $key  Optional key to pluck (e.g. 'name', 'email')
     */
    protected function authUser(?string $key = null): mixed
    {
        $user = $this->resolveAuthUser();

        if ($key !== null) {
            return $this->authUserValue($user, $key);
        }

        return array_merge([
            'id' => $this->authId(),
            'name' => $this->authUserValue($user, 'name'),
            'preferred_name' => $this->authUserValue($user, 'preferred_name'),
            'email' => $this->authUserValue($user, 'email'),
            'auth_type' => $this->authMethod(),
            'userID' => $this->authId(),
            'userFullName' => $this->authUserValue($user, 'userFullName'),
            'userName' => $this->authUserValue($user, 'userName'),
            'userNickname' => $this->authUserValue($user, 'userNickname'),
            'userEmail' => $this->authUserValue($user, 'userEmail'),
        ], $user);
    }

    /** Check if the current user has a specific permission. */
    protected function can(string $slug): bool
    {
        return permission($slug);
    }

    /** Check if the current user lacks a specific permission. */
    protected function cannot(string $slug): bool
    {
        return !permission($slug);
    }

    protected function authMethod(): ?string
    {
        if (function_exists('currentAuthMethod')) {
            return currentAuthMethod($this->authMethods());
        }

        return !empty($_SESSION['isLoggedIn']) ? 'session' : null;
    }

    protected function authMethods(): array
    {
        return self::AUTH_METHODS;
    }

    protected function resolveAuthUser(): array
    {
        if (function_exists('currentAuthUser')) {
            $user = currentAuthUser($this->authMethods());
            if (is_array($user)) {
                return $user;
            }
        }

        if (function_exists('auth')) {
            try {
                $user = auth()->user($this->authMethods());
                if (is_array($user)) {
                    return $user;
                }
            } catch (\Throwable $e) {
            }
        }

        return [];
    }

    protected function authUserValue(array $user, string $key): mixed
    {
        $normalizedKey = trim($key);
        if ($normalizedKey === '') {
            return null;
        }

        $aliases = [
            'id' => ['id', 'userID'],
            'name' => ['name', 'userFullName', 'userName'],
            'preferred_name' => ['preferred_name', 'user_preferred_name', 'userNickname'],
            'email' => ['email', 'userEmail'],
            'auth_type' => ['auth_type', 'auth_via'],
            'userID' => ['userID', 'id'],
            'userFullName' => ['userFullName', 'name', 'userName'],
            'userName' => ['userName', 'name', 'userFullName'],
            'userNickname' => ['userNickname', 'preferred_name', 'user_preferred_name'],
            'userEmail' => ['userEmail', 'email'],
        ];

        foreach ($aliases[$normalizedKey] ?? [$normalizedKey] as $candidate) {
            if (array_key_exists($candidate, $user) && $user[$candidate] !== null && $user[$candidate] !== '') {
                return $user[$candidate];
            }

            if (function_exists('authSessionValue')) {
                $sessionValue = authSessionValue($candidate, $this->authMethods());
                if ($sessionValue !== null && $sessionValue !== '') {
                    return $sessionValue;
                }
            } elseif (array_key_exists($candidate, $_SESSION ?? [])) {
                $sessionValue = $_SESSION[$candidate];
                if ($sessionValue !== null && $sessionValue !== '') {
                    return $sessionValue;
                }
            }
        }

        if ($normalizedKey === 'auth_type') {
            return $this->authMethod();
        }

        return null;
    }
}
