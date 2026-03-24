<?php

namespace App\Http\Controllers;

use Core\Http\Controller;
use Core\Http\Request;
use App\Http\Requests\SaveAbilitiesRequest;
use App\Http\Requests\SaveAssignmentRequest;

class PermissionController extends Controller
{
    public function __construct()
    {
        parent::__construct();
    }

    public function listPermissionDatatable(Request $request): void
    {
        $db = db();
        $result = $db->table('system_abilities')->select('id, abilities_name, abilities_slug, abilities_desc')
            ->whereNull('deleted_at')
            ->orderBy('abilities_name', 'ASC')
            ->withCount('count', 'system_permission', 'abilities_id', 'id')
            ->setPaginateFilterColumn(['abilities_name', 'abilities_slug'])
            ->safeOutput()
            ->paginate_ajax(request()->all());

        $result['data'] = array_map(function ($row) {
            $id = encodeID($row['id']);
            $canUpdate = permission('rbac-abilities-update');
            $canDelete = permission('rbac-abilities-delete') && (int) $row['count'] < 1;
            $delAction = $canDelete ? "onclick='deletePermRecord(\"{$id}\")'" : null;
            $delText = empty($delAction) ? '(disabled)' : '';
            $editAction = $canUpdate ? "onclick='editPermRecord(\"{$id}\")'" : '';
            $editStyle = $canUpdate ? 'cursor: pointer;' : 'cursor: not-allowed; opacity: .45;';
            $deleteStyle = $canDelete ? 'cursor: pointer;' : 'cursor: not-allowed; opacity: .45;';
            $action = "
                <span style='display: inline-block; vertical-align: middle;'>
                    <i class='bx bx-edit-alt' style='{$editStyle}' {$editAction} title='Edit'></i>
                </span>
                <span style='display: inline-block; vertical-align: middle;'>
                    <i class='bx bx-trash' style='{$deleteStyle}' {$delAction} title='Delete {$delText}'></i>
                </span>
            ";

            return [
                'name' => $row['abilities_name'],
                'slug' => $row['abilities_slug'],
                'count' => number_format($row['count']),
                'desc' => $row['abilities_desc'],
                'action' => $action,
            ];
        }, $result['data']);

        jsonResponse($result);
    }

    public function listPermissionAssignDatatable(Request $request): void
    {
        $roleID = decodeID(request()->input('id'));

        $db = db();
        $result = $db->table('system_abilities')->select('id, abilities_name, abilities_slug, abilities_desc')
            ->whereNull('deleted_at')
            ->orderBy('abilities_name', 'ASC')
            ->safeOutput()
            ->get();

        $currentPerm = $db->table('system_permission')->select('id, abilities_id, access_device_type')
            ->where('role_id', $roleID)
            ->get();

        $currentAbilitiesID = [];
        foreach ($currentPerm as $perm) {
            $currentAbilitiesID[] = $perm['abilities_id'];
        }

        $canModifyAssignments = permission('rbac-roles-update');

        $result = array_map(function ($row) use ($result, $roleID, $currentAbilitiesID, $canModifyAssignments) {
            $abilitiesID = $row['id'];
            $allAccess = $row['abilities_slug'] === '*' ? 1 : 0;

            $allAccessID = null;
            foreach ($result as $r) {
                if ($r['abilities_slug'] === '*') {
                    $allAccessID = $r['id'];
                    break;
                }
            }
            $hasAllAccess = in_array($allAccessID, $currentAbilitiesID);
            $acquiredAccess = in_array($abilitiesID, $currentAbilitiesID) ? 1 : 0;

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

        jsonResponse($result);
    }

    public function show(string $id): void
    {
        $id = decodeID($id);

        if (empty($id)) {
            jsonResponse(['code' => 400, 'message' => 'ID is required']);
        }

        $abilities = db()->table('system_abilities')->where('id', $id)->safeOutput()->fetch();

        if (!$abilities) {
            jsonResponse(['code' => 404, 'message' => 'Abilities not found']);
        }

        jsonResponse(['code' => 200, 'data' => $abilities]);
    }

    public function saveAbilities(SaveAbilitiesRequest $request): void
    {
        $data = $request->validated();
        $abilityId = $request->input('id');
        unset($data['id']);

        if (empty($abilityId) && !permission('rbac-abilities-create')) {
            jsonResponse(['code' => 403, 'message' => 'You do not have permission to create abilities.']);
        }

        if (!empty($abilityId) && !permission('rbac-abilities-update')) {
            jsonResponse(['code' => 403, 'message' => 'You do not have permission to update abilities.']);
        }

        $result = db()->table('system_abilities')->insertOrUpdate(
            [
                'id' => $abilityId
            ],
            $data
        );

        if (isError($result['code'])) {
            jsonResponse(['code' => 422, 'message' => 'Failed to save abilities']);
        }

        jsonResponse(['code' => 200, 'message' => 'Abilities saved']);
    }

    public function saveAssignment(SaveAssignmentRequest $request): void
    {
        $roleID = $request->validated('role_id');
        $abilitiesID = $request->validated('abilities_id');
        $isAllAccess = $request->validated('all_access');
        $permission = $request->validated('permission');

        if (empty($roleID) || empty($abilitiesID)) {
            jsonResponse(['code' => 400, 'message' => 'ID is required']);
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
                jsonResponse(['code' => 200, 'message' => 'Permission already assigned']);
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
            jsonResponse(['code' => 422, 'message' => 'Failed to processed permission']);
        }

        jsonResponse(['code' => 200, 'message' => ucfirst($permission)]);
    }

    public function destroy(string $id): void
    {
        $id = decodeID($id);

        if (empty($id)) {
            jsonResponse(['code' => 400, 'message' => 'ID is required']);
        }

        $result = db()->table('system_abilities')->where('id', $id)->softDelete();

        if (isError($result['code'])) {
            jsonResponse(['code' => 422, 'message' => 'Failed to delete abilities']);
        }

        jsonResponse(['code' => 200, 'message' => 'Abilities deleted']);
    }
}
