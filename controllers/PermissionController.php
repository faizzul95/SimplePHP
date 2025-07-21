<?php

// IMPORTANT: This file is part of the application
require_once '../init.php';

/*
|--------------------------------------------------------------------------
| LIST PERMISSION
|--------------------------------------------------------------------------
*/

function listPermissionDatatable($request)
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
| INSERT/REMOVED OPERATION 
|--------------------------------------------------------------------------
*/

function save($request)
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
