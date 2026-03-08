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
 *   - Permission checks with automatic 403
 *   - Page state management for menu/breadcrumb
 */
abstract class Controller
{
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
        echo $this->blade->render($view, $params);
        exit;
    }

    // ─── Page State ──────────────────────────────────────────────────

    /**
     * Set the global page state used by the menu and breadcrumb.
     *
     * When a permission slug is provided the user's access is checked
     * automatically; a 403 page is shown when the check fails.
     *
     * @param string      $page           Active menu group   (e.g. 'rbac')
     * @param string|null $subpage        Active submenu item (e.g. 'roles')
     * @param string|null $permission     Permission slug to enforce (null = skip)
     * @param string      $titlePageValue Page title
     * @param string      $titleSubPageValue Sub-page / breadcrumb title
     */
    protected function setPageState(
        string $page,
        ?string $subpage,
        ?string $permission,
        string $titlePageValue,
        string $titleSubPageValue = ''
    ): void {
        global $titlePage, $titleSubPage, $currentPage, $currentSubPage;

        $currentPage    = $page;
        $currentSubPage = $subpage;
        $titlePage      = $titlePageValue;
        $titleSubPage   = $titleSubPageValue;

        if ($permission !== null && !permission($permission)) {
            show_403();
            exit;
        }
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
     * @param string $label      Human-readable name for the error message
     * @return int|string  The decoded ID
     */
    protected function decodeIdOrFail(string $encodedId, string $label = 'ID'): int|string
    {
        $id = decodeID($encodedId);
        if (empty($id)) {
            $this->errorResponse("{$label} is required", 400);
        }
        return $id;
    }

    /**
     * Fetch a single record from a table or terminate with 404.
     *
     * @param string      $table     Table name
     * @param int|string  $id        Primary key value
     * @param string      $label     Human-readable name for the error message
     * @param string|null $select    Columns to select (null = all)
     * @param bool        $softDelete Respect soft-delete (whereNull deleted_at)
     * @return array  The fetched record
     */
    protected function findOrFail(
        string $table,
        int|string $id,
        string $label = 'Record',
        ?string $select = null,
        bool $softDelete = true
    ): array {
        $query = db()->table($table);

        if ($select !== null) {
            $query->select($select);
        }

        $query->where('id', $id);

        if ($softDelete) {
            $query->whereNull('deleted_at');
        }

        $record = $query->safeOutput()->fetch();

        if (!$record) {
            $this->errorResponse("{$label} not found", 404);
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
        return $this->findOrFail($table, $id, $label, $select, $softDelete);
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
        return auth()->id() ?? (isset($_SESSION['userID']) ? (int) $_SESSION['userID'] : null);
    }

    /**
     * Get the current authenticated user's data from session.
     *
     * @param string|null $key  Optional key to pluck (e.g. 'name', 'email')
     * @return mixed
     */
    protected function authUser(?string $key = null): mixed
    {
        if ($key !== null) {
            return $_SESSION[$key] ?? null;
        }

        return [
            'id'    => $this->authId(),
            'name'  => $_SESSION['userName'] ?? null,
            'email' => $_SESSION['userEmail'] ?? null,
        ];
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
}
