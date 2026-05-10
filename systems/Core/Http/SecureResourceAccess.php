<?php

namespace Core\Http;

/**
 * SecureResourceAccess Trait
 *
 * Provides plain-ID record lookup + ownership check helpers so controllers
 * can enforce record-level authorization without duplicating boilerplate.
 *
 * Usage — available automatically on every controller that extends Core\Http\Controller
 * (the trait is already mixed in; do NOT add `use SecureResourceAccess` to subclasses):
 *
 *   class UserController extends Controller
 *   {
 *       public function show(string $id): void
 *       {
 *           $user = $this->authorizeResourceById('users', (int) $id, fn($r) => $r['id'] === $this->authId());
 *           $this->successResponse('OK', $user);
 *       }
 *   }
 *
 * Requirements:
 *   The using class MUST provide:
 *     - errorResponse(string $message, int $code): void  (terminates)
 *     - findOrFail(string $table, int|string $id, string $select, bool $softDelete, string $message): array
 *     - authId(): int|null
 *
 *   All of the above are provided by Core\Http\Controller.
 */
trait SecureResourceAccess
{
    /**
     * Fetch a record by plain (non-encoded) ID and assert ownership.
     *
     * Use this on internal/API routes where IDs are not encoded, but you
     * still need to enforce record-level ownership before the controller
     * proceeds.
     *
     * Terminates with:
     *   404  if no record exists
     *   403  if $ownershipCheck returns false
     *
     * @param string        $table          Table name
     * @param int|string    $id             Raw primary key value
     * @param callable|null $ownershipCheck Receives the fetched record array; must return bool.
     * @param string        $label          Human-readable entity name
     * @param string        $select         Columns to SELECT
     * @param bool          $softDelete     Respect soft-delete
     * @return array  The fetched record
     */
    protected function authorizeResourceById(
        string $table,
        int|string $id,
        ?callable $ownershipCheck = null,
        string $label = 'Record',
        string $select = '*',
        bool $softDelete = true
    ): array {
        $record = $this->findOrFail($table, $id, $select, $softDelete, $label . ' not found');

        if ($ownershipCheck !== null && !$ownershipCheck($record)) {
            $this->errorResponse('You do not have permission to access this ' . strtolower($label), 403);
        }

        return $record;
    }

    /**
     * Assert that the current authenticated user owns the given record.
     *
     * Compares record[$ownerColumn] against authId(). Terminates with 403
     * if they do not match AND the user does not satisfy $adminCheck.
     *
     * @param array         $record       Fetched DB record
     * @param string        $ownerColumn  Column that stores the owner's user ID (default: 'user_id')
     * @param callable|null $adminCheck   Optional bypass: receives record, returns bool (e.g. admin override)
     * @param string        $label        Entity name for the error message
     */
    protected function assertOwnership(
        array $record,
        string $ownerColumn = 'user_id',
        ?callable $adminCheck = null,
        string $label = 'resource'
    ): void {
        $currentId = $this->authId();

        // Explicitly reject unauthenticated callers — null never equals any real owner ID
        if ($currentId === null) {
            $this->errorResponse('Authentication required to access this ' . strtolower($label), 401);
        }

        $ownerId = (int) ($record[$ownerColumn] ?? 0);

        if ($ownerId === (int) $currentId) {
            return;
        }

        if ($adminCheck !== null && $adminCheck($record)) {
            return;
        }

        $this->errorResponse('You do not have permission to modify this ' . strtolower($label), 403);
    }
}
