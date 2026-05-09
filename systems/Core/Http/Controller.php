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
 * Features:
 *   - View rendering via Blade engine
 *   - JSON response helpers (success/error/paginate)
 *   - Record lookup with automatic 404 handling
 *   - ID encoding/decoding with validation
 *   - Explicit authorization helpers
 *   - Redirect helpers
 *   - Page state management for menu/breadcrumb
 */
abstract class Controller
{
    use SecureResourceAccess;

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
     * @param array  $params Variables passed to the view
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
     * @param string      $titlePageValue Page title
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
     * @param string     $message  Human-readable message
     * @param array|null $data     Optional extra payload merged into the response
     * @param int        $code     Success code (default 200)
     */
    protected function successResponse(string $message = 'Success', ?array $data = null, int $code = 200): void
    {
        $response = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $response['data'] = $data;
        }
        jsonResponse($response, $code);
    }

    /**
     * Send a JSON error response and terminate.
     *
     * @param string $message  Human-readable message
     * @param int    $code     Error code (default 422)
     * @param array  $errors   Optional field-level errors
     */
    protected function errorResponse(string $message = 'Error', int $code = 422, array $errors = []): void
    {
        $response = ['code' => $code, 'message' => $message];
        if (!empty($errors)) {
            $response['errors'] = $errors;
        }
        jsonResponse($response, $code);
    }

    /**
     * Return a paginated JSON response (DataTables compatible).
     * Terminates the request.
     *
     * @param array $result The paginated result array from paginate_ajax()
     */
    protected function paginateResponse(array $result): void
    {
        jsonResponse($result);
    }

    // ─── Record Helpers ──────────────────────────────────────────────

    /**
     * Decode an encoded ID or terminate with 400 if invalid.
     *
     * @param string $encodedId  The encoded (hashed) ID
     * @param string $message    Human-readable message for the error
     * @return int|string  The decoded ID
     */
    protected function decodeIdOrFail(string $encodedId, string $message = 'ID is required'): int|string
    {
        $id = decodeID($encodedId);
        if (empty($id)) {
            $this->errorResponse($message, 400);
        }
        return $id;
    }

    /**
     * Fetch a single record from a table or terminate with 404.
     *
     * @param string      $table      Table name
     * @param int|string  $id         Primary key value
     * @param string|null $select     Columns to select (null = '*' = all)
     * @param bool        $softDelete Respect soft-delete (whereNull deleted_at)
     * @param string      $message    Error message on 404
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

    /**
     * Decode + fetch in one call. Terminates with 400/404 on failure.
     *
     * @param string      $encodedId  Encoded ID string
     * @param string      $table      Table name
     * @param string      $label      Human-readable name
     * @param string|null $select     Columns to select
     * @param bool        $softDelete Respect soft-delete
     * @return array  The fetched record (with decoded 'id' injected)
     */
    protected function findByEncodedIdOrFail(
        string $encodedId,
        string $table,
        string $label = 'Record',
        ?string $select = null,
        bool $softDelete = true
    ): array {
        $id = $this->decodeIdOrFail($encodedId, $label);
        return $this->findOrFail($table, $id, $select, $softDelete, $label);
    }

    // ─── Authorization ───────────────────────────────────────────────

    /**
     * Check a permission slug and terminate with 403 if denied.
     *
     * @param string $permissionSlug The permission to check (e.g. 'user-delete')
     * @param string $message        Custom denial message
     */
    protected function authorizeOrFail(string $permissionSlug, string $message = 'Unauthorized'): void
    {
        if (!permission($permissionSlug)) {
            $this->errorResponse($message, 403);
        }
    }

    // ─── Soft-Delete Helpers ─────────────────────────────────────────

    /**
     * Soft-delete a record by encoded ID and return a JSON response.
     *
     * @param string $encodedId Encoded ID
     * @param string $table     Table name
     * @param string $label     Human-readable entity name
     * @param array  $extra     Extra columns to set on delete (e.g. ['user_status' => 3])
     */
    protected function softDeleteByEncodedId(
        string $encodedId,
        string $table,
        string $label = 'Record',
        array $extra = []
    ): void {
        $id = $this->decodeIdOrFail($encodedId, $label);

        $result = db()->table($table)->where('id', $id)->softDelete(
            array_merge($extra, ['deleted_at' => timestamp()])
        );

        if (isError($result['code'])) {
            $this->errorResponse("Failed to delete {$label}", 422);
        }

        $this->successResponse("{$label} deleted");
    }

    /**
     * Restore a soft-deleted record by encoded ID and return a JSON response.
     *
     * @param string $encodedId  Encoded ID
     * @param string $table      Table name
     * @param string $label      Human-readable entity name
     * @param array  $extra      Extra columns to set on restore
     */
    protected function restoreByEncodedId(
        string $encodedId,
        string $table,
        string $label = 'Record',
        array $extra = []
    ): void {
        $id = $this->decodeIdOrFail($encodedId, $label);

        $result = db()->table($table)->where('id', $id)->update(
            array_merge($extra, ['deleted_at' => null])
        );

        if (isError($result['code'])) {
            $this->errorResponse("Failed to restore {$label}", 422);
        }

        $this->successResponse("{$label} restored");
    }

    // ─── Utility ─────────────────────────────────────────────────────

    /**
     * Get the current authenticated user ID.
     *
     * @return int|null
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
     * @return mixed
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

    /**
     * Check if the current user has a specific permission.
     *
     * @param string $slug Permission slug
     * @return bool
     */
    protected function can(string $slug): bool
    {
        return permission($slug);
    }

    /**
     * Check if the current user lacks a specific permission.
     *
     * @param string $slug Permission slug
     * @return bool
     */
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

    protected function redirectTo(string $path, int $status = 302): void
    {
        redirect()->to($path, $status)->send();
    }

    protected function redirectRoute(string $name, array $params = [], int $status = 302): void
    {
        redirect()->route($name, $params, $status)->send();
    }
}
