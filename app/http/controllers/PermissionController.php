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
            ->withCount('count', 'system_permission', 'abilities_id', 'id')
            ->setPaginateFilterColumn(['abilities_name', 'abilities_slug'])
            ->safeOutput()
            ->paginate_ajax(request()->all());

        $result['data'] = array_map(function ($row) {
            $id = encodeID($row['id']);
            $delAction = $row['count'] > 0 ? null : "onclick='deletePermRecord(\"{$id}\")'";

            return [
                'name' => $row['abilities_name'],
                'slug' => $row['abilities_slug'],
                'count' => number_format($row['count']),
                'desc' => $row['abilities_desc'],
                'action' => "
                <span style='display: inline-block; vertical-align: middle;'>
                    <i class='bx bx-edit-alt' style='cursor: pointer;' onclick='editPermRecord(\"{$id}\")' title='Edit'></i>
                </span>
                <span style='display: inline-block; vertical-align: middle;'>
                    <i class='bx bx-trash' style='cursor: pointer;' {$delAction} title='Delete'></i>
                </span>
            "
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
            ->safeOutput()
            ->get();

        $currentPerm = $db->table('system_permission')->select('id, abilities_id, access_device_type')
            ->where('role_id', $roleID)
            ->get();

        $currentAbilitiesID = [];
        foreach ($currentPerm as $perm) {
            $currentAbilitiesID[] = $perm['abilities_id'];
        }

        $result = array_map(function ($row) use ($result, $roleID, $currentAbilitiesID) {
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
                $disabledCheckbox = "onchange='grantPermission({$roleID}, {$abilitiesID}, {$allAccess})'";
            } else {
                if ($hasAllAccess) {
                    $checked = 'checked';
                    $disabledCheckbox = "onchange='grantPermission({$roleID}, {$abilitiesID}, {$allAccess})' disabled";
                } else {
                    $checked = $acquiredAccess ? 'checked' : '';
                    $disabledCheckbox = "onchange='grantPermission({$roleID}, {$abilitiesID}, {$allAccess})'";
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
        unset($data['id']);

        $result = db()->table('system_abilities')->insertOrUpdate(
            [
                'id' => $request->input('id')
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
