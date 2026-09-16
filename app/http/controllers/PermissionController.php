<?php

namespace App\Http\Controllers;

use Core\Http\Controller;
use Core\Http\Reply;
use Core\Http\Request;
use App\Http\Requests\SaveAbilitiesRequest;
use App\Http\Requests\SaveAssignmentRequest;

class PermissionController extends Controller
{
    public function __construct()
    {
        parent::__construct();
    }

    public function listPermissionDatatable(Request $request): array
    {
        $db = db();
        $result = $db->table('system_abilities')->select('id, abilities_name, abilities_slug, abilities_desc')
            ->whereNull('deleted_at')
            ->orderBy('abilities_name', 'ASC')
            ->withCount('count', 'system_permission', 'abilities_id', 'id')
            ->setPaginateFilterColumn(['abilities_name', 'abilities_slug'])
            ->setAllowedSortColumns(['system_abilities.abilities_name', 'system_abilities.abilities_slug', 'system_abilities.abilities_desc'])
            ->safeOutput()
            ->paginate_ajax($request->all());

        $result['data'] = array_map([$this, 'mapPermissionDatatableRow'], $result['data']);

        // Already the exact shape DataTables expects (draw / recordsTotal /
        // data), and Emitter turns a returned array into a JSON response — so
        // wrapping it would only nest the payload a level deeper.
        return $result;
    }

    /** @return Reply|array<string, mixed> A Reply on the guard, the datatable payload otherwise. */
    public function listPermissionAssignDatatable(Request $request): Reply|array
    {
        $roleID = $request->input('id');
        if ($roleID === null || $roleID === '') {
            return fail('Role ID is required', 400);
        }

        $db = db();
        $result = $db->table('system_abilities')->select('id, abilities_name, abilities_slug, abilities_desc')
            ->whereNull('deleted_at')
            ->orderBy('abilities_name', 'ASC')
            ->safeOutput()
            ->get();

        $currentPerm = $db->table('system_permission')->select('id, abilities_id, access_device_type')
            ->where('role_id', $roleID)
            ->get();

        /*
        | Ids are compared as integers, and strictly.
        |
        | PDO hands these back as strings under some drivers and as ints under
        | others, so a bare in_array() had to stay loose to work at all — and a
        | loose in_array(null, ...) matches 0, "0" and "". With no wildcard
        | ability configured $allAccessID is null, so a single zero-ish entry in
        | the permission table reported the role as having *every* permission.
        | Normalising first is what makes the strict comparison safe.
        */
        $currentAbilitiesID = [];
        foreach ($currentPerm as $perm) {
            $currentAbilitiesID[] = (int) $perm['abilities_id'];
        }

        // Hoisted: this is a property of the ability list, not of the row, and
        // scanning for it inside the map made the whole build O(n²).
        $allAccessID = null;
        foreach ($result as $r) {
            if ($r['abilities_slug'] === '*') {
                $allAccessID = (int) $r['id'];
                break;
            }
        }

        $hasAllAccess = $allAccessID !== null && in_array($allAccessID, $currentAbilitiesID, true);

        $canModifyAssignments = permission('rbac-roles-update');

        $result = array_map(function ($row) use ($roleID, $currentAbilitiesID, $canModifyAssignments, $hasAllAccess) {
            $abilitiesID = $row['id'];
            $allAccess = $row['abilities_slug'] === '*' ? 1 : 0;

            $acquiredAccess = in_array((int) $abilitiesID, $currentAbilitiesID, true) ? 1 : 0;

            if ($allAccess) {
                $checked = $acquiredAccess ? 'checked' : '';
                $disabledCheckbox = $canModifyAssignments
                    ? "onchange='grantPermission({$roleID}, {$abilitiesID}, {$allAccess})'"
                    : 'disabled';
            } else {
                if ($hasAllAccess) {
                    $checked = 'checked';
                    $disabledCheckbox = "onchange='grantPermission({$roleID}, {$abilitiesID}, {$allAccess})' disabled";
                } else {
                    $checked = $acquiredAccess ? 'checked' : '';
                    $disabledCheckbox = $canModifyAssignments
                        ? "onchange='grantPermission({$roleID}, {$abilitiesID}, {$allAccess})'"
                        : 'disabled';
                }
            }

            $classCheckbox = $allAccess ? '' : ' list-grant-perm';
            $clssAcquired = !$allAccess && $checked == 'checked' ? ' acquired' : '';

            return [
                'checkbox' => "<input type='checkbox' id='ab{$abilitiesID}' class='form-check-input{$classCheckbox}{$clssAcquired}' {$disabledCheckbox} {$checked} />",
                'abilities_name' => $row['abilities_name'],
                'abilities_slug' => $row['abilities_slug'],
                'abilities_desc' => $row['abilities_desc'],
            ];
        }, $result);

        // Already the exact shape DataTables expects (draw / recordsTotal /
        // data), and Emitter turns a returned array into a JSON response — so
        // wrapping it would only nest the payload a level deeper.
        return $result;
    }

    public function show(int|string $id): Reply
    {
        if ($id === '') {
            return fail('Abilities ID is required', 400);
        }

        $abilities = db()->table('system_abilities')->where('id', $id)->safeOutput()->fetch();

        if (!$abilities) {
            return fail('Abilities not found', 404);
        }

        return ok(null, $abilities);
    }

    public function saveAbilities(SaveAbilitiesRequest $request): Reply
    {
        $data = $request->validated();
        $abilityId = $data['id'] ?? null;
        unset($data['id']);

        if (empty($abilityId) && !permission('rbac-abilities-create')) {
            return fail('You do not have permission to create abilities.', 403);
        }

        if (!empty($abilityId) && !permission('rbac-abilities-update')) {
            return fail('You do not have permission to update abilities.', 403);
        }

        $result = db()->table('system_abilities')->insertOrUpdate(
            [
                'id' => $abilityId
            ],
            $data
        );

        if (isError($result['code'])) {
            return fail('Failed to save abilities');
        }

        $savedAbilityId = $abilityId ?: ($result['id'] ?? null);
        $savedRow = $savedAbilityId ? db()->table('system_abilities')
            ->select('id, abilities_name, abilities_slug, abilities_desc')
            ->where('id', $savedAbilityId)
            ->whereNull('deleted_at')
            ->withCount('count', 'system_permission', 'abilities_id', 'id')
            ->safeOutput()
            ->fetch() : null;

        return ok('Abilities saved', $savedRow ? $this->mapPermissionDatatableRow($savedRow) : null);
    }

    public function saveAssignment(SaveAssignmentRequest $request): Reply
    {
        $roleID = $request->validated('role_id');
        $abilitiesID = $request->validated('abilities_id');
        $isAllAccess = $request->validated('all_access', 0);
        $permission = $request->validated('permission');

        if (empty($roleID) || empty($abilitiesID)) {
            return fail('ID is required', 400);
        }

        if ($permission == 'revoke') {
            if ($isAllAccess) {
                $result = db()->table('system_permission')
                    ->where('role_id', $roleID)
                    ->delete();
            } else {
                $result = db()->table('system_permission')
                    ->where('role_id', $roleID)
                    ->where('abilities_id', $abilitiesID)
                    ->delete();
            }
        } else {
            if ($isAllAccess) {
                db()->table('system_permission')->where('role_id', $roleID)->delete();
            }

            // Guard against duplicate permission entries
            $exists = db()->table('system_permission')
                ->where('role_id', $roleID)
                ->where('abilities_id', $abilitiesID)
                ->exists();

            if ($exists) {
                return ok('Permission already assigned');
            }

            $result = db()->table('system_permission')->insert(
                [
                    'role_id' => $roleID,
                    'abilities_id' => $abilitiesID,
                    'access_device_type' => 1,
                    'created_at' => timestamp(),
                ]
            );
        }

        if (isError($result['code'])) {
            return fail('Failed to processed permission');
        }

        return ok(ucfirst($permission));
    }

    public function destroy(int|string $id): Reply
    {
        if ($id === '') {
            return fail('Abilities ID is required', 400);
        }

        $result = db()->table('system_abilities')->where('id', $id)->softDelete();

        if (isError($result['code'])) {
            return fail('Failed to delete abilities');
        }

        return ok('Abilities deleted');
    }

    private function mapPermissionDatatableRow(array $row): array
    {
        $key = $row['id'] ?? null;
        $rowKey = 'permission-row-' . $row['id'];
        $canUpdate = permission('rbac-abilities-update');
        $canDelete = permission('rbac-abilities-delete') && (int) $row['count'] < 1;
        $deleteAction = $canDelete ? "onclick='deletePermRecord(\"{$key}\", \"{$rowKey}\")'" : null;
        $deleteText = empty($deleteAction) ? '(disabled)' : '';
        $editAction = $canUpdate ? "onclick='editPermRecord(\"{$key}\")'" : '';
        $editStyle = $canUpdate ? 'cursor: pointer;' : 'cursor: not-allowed; opacity: .45;';
        $deleteStyle = $canDelete ? 'cursor: pointer;' : 'cursor: not-allowed; opacity: .45;';
        $action = "
                <span style='display: inline-block; vertical-align: middle;'>
                    <i class='bx bx-edit-alt' style='{$editStyle}' {$editAction} title='Edit'></i>
                </span>
                <span style='display: inline-block; vertical-align: middle;'>
                    <i class='bx bx-trash' style='{$deleteStyle}' {$deleteAction} title='Delete {$deleteText}'></i>
                </span>
            ";

        return [
            'row_key' => $rowKey,
            'key' => $key,
            'name' => $row['abilities_name'],
            'slug' => $row['abilities_slug'],
            'count' => number_format($row['count']),
            'desc' => $row['abilities_desc'],
            'action' => $action,
        ];
    }
}
