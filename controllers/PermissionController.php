<?php

// IMPORTANT: This file is part of the application
require_once '../bootstrap.php';

/*
|--------------------------------------------------------------------------
| LIST (SERVER-SIDE DATATABLE)  
|--------------------------------------------------------------------------
*/

function listPermissionDatatable($request)
{
    $db = db();
    $result = $db->table('system_abilities')->select('id, abilities_name, abilities_slug, abilities_desc')
        ->whereNull('deleted_at') // filter for records soft deleted
        ->withCount('count', 'system_permission', 'abilities_id', 'id')
        ->setPaginateFilterColumn(['abilities_name', 'abilities_slug'])
        ->safeOutput() // Return with safe value using safeOutput() method to prevent from XSS attack being show in table
        ->paginate_ajax(request()->all());

    // Alter/formatting the data return
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

/*
|--------------------------------------------------------------------------
| LIST PERMISSION (ASSIGN) DATATABLE
|--------------------------------------------------------------------------
*/

function listPermissionAssignDatatable($request)
{
    $roleID = decodeID(request()->input('id'));

    $db = db();
    $result = $db->table('system_abilities')->select('id, abilities_name, abilities_slug, abilities_desc')
        ->whereNull('deleted_at') // filter for records soft deleted
        ->safeOutput() // Return with safe value using safeOutput() method to prevent from XSS attack being show in table
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

        // Find if "All Access" is acquired
        $allAccessID = null;
        foreach ($result as $r) {
            if ($r['abilities_slug'] === '*') {
                $allAccessID = $r['id'];
                break;
            }
        }
        $hasAllAccess = in_array($allAccessID, $currentAbilitiesID);

        // Is this specific ability acquired?
        $acquiredAccess = in_array($abilitiesID, $currentAbilitiesID) ? 1 : 0;

        if ($allAccess) {
            // Only tick "All Access" if acquired
            $checked = $acquiredAccess ? 'checked' : '';
            $disabledCheckbox = "onchange='grantPermission({$roleID}, {$abilitiesID}, {$allAccess})'";
        } else {
            if ($hasAllAccess) {
                // If "All Access" is acquired, tick and disable all others
                $checked = 'checked';
                $disabledCheckbox = "onchange='grantPermission({$roleID}, {$abilitiesID}, {$allAccess})' disabled";
            } else {
                // Only tick if this permission is acquired
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

/*
|--------------------------------------------------------------------------
| SHOW OPERATION 
|--------------------------------------------------------------------------
*/

function show($request)
{
    $id = decodeID(request()->input('id'));

    if (empty($id)) {
        jsonResponse(['code' => 400, 'message' => 'ID is required']);
    }

    $abilities = db()->table('system_abilities')->where('id', $id)->safeOutput()->fetch();

    if (!$abilities) {
        jsonResponse(['code' => 404, 'message' => 'Abilities not found']);
    }

    jsonResponse(['code' => 200, 'data' => $abilities]);
}

/*
|--------------------------------------------------------------------------
| INSERT/UPDATE OPERATION 
|--------------------------------------------------------------------------
*/

function saveAbilities($request)
{
    $validation = request()->validate([
        'abilities_name' => 'required|string|min_length:5|max_length:50|secure_value',
        'abilities_slug' => 'required|string|min_length:5|max_length:100|secure_value',
        'abilities_desc' => 'string|max_length:255|secure_value',
        'id' => 'numeric'
    ]);

    if (!$validation->passed()) {
        jsonResponse(['code' => 400, 'message' => $validation->getFirstError()]);
    }

    $result = db()->table('system_abilities')->insertOrUpdate(
        [
            'id' => request()->input('id')
        ],
        request()->all()
    );

    if (isError($result['code'])) {
        jsonResponse(['code' => 422, 'message' => 'Failed to save abilities']);
    }

    jsonResponse(['code' => 200, 'message' => 'Abilities saved']);
}

/*
|--------------------------------------------------------------------------
| INSERT/REMOVED OPERATION 
|--------------------------------------------------------------------------
*/

function saveAssignment($request)
{
    $roleID = request()->input('role_id');
    $abilitiesID = request()->input('abilities_id');
    $isAllAccess = request()->input('all_access');
    $permission = request()->input('permission');

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
        // Remove all the access before adding
        if ($isAllAccess) {
            db()->table('system_permission')->where('role_id', $roleID)->delete();
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

/*
|--------------------------------------------------------------------------
| DELETE OPERATION 
|--------------------------------------------------------------------------
*/

function destroy($request)
{
    $id = decodeID(request()->input('id'));

    if (empty($id)) {
        jsonResponse(['code' => 400, 'message' => 'ID is required']);
    }

    $result = db()->table('system_abilities')->where('id', $id)->softDelete();

    if (isError($result['code'])) {
        jsonResponse(['code' => 422, 'message' => 'Failed to delete abilities']);
    }

    jsonResponse(['code' => 200, 'message' => 'Abilities deleted']);
}
