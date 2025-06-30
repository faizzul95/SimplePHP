<?php

// IMPORTANT: This file is part of the application
require_once '../init.php';

/*
|--------------------------------------------------------------------------
| LIST (SERVER-SIDE DATATABLE)  
|--------------------------------------------------------------------------
*/

function listRolesDatatable($request)
{
    $status = request()->input('role_status');

    $db = db();
    $db->table('master_roles')->select('id, role_name, role_rank, role_status')
        ->withCount('profile', 'user_profile', 'role_id', 'id', function ($q) {
            $q->where('profile_status', '1');
        });

    if ($status == 0 || !empty($status)) {
        $db->where('role_status', $status);
    }

    // Return with safe value using safeOutput() method to prevent from XSS attack being show in table
    $result = $db->setPaginateFilterColumn(['role_name', 'role_rank'])
        ->safeOutput()
        ->paginate_ajax(request()->all());

    // Alter/formatting the data return
    $result['data'] = array_map(function ($row) {
        $id = encodeID($row['id']);
        $delAction = $row['profile_count'] > 0 ? null : "onclick='deleteRecord(\"{$id}\")'";
        $delText = empty($delAction) ? '(disabled)'  : '';

        return [
            'name' => $row['role_name'],
            'rank' => $row['role_rank'],
            'count' => number_format($row['profile_count']),
            'status' => $row['role_status'] ? '<span class="badge bg-label-success"> Active </span>' : '<span class="badge bg-label-warning"> Inactive </span>',
            'action' => "
                <span style='display: inline-block; vertical-align: middle;'>
                    <i class='bx bx-edit-alt' style='cursor: pointer;' onclick='editRecord(\"{$id}\")' title='Edit'></i>
                </span>
                <div class='dropdown' style='display: inline-block; vertical-align: middle;'>
                    <button type='button' class='btn p-0 dropdown-toggle hide-arrow' data-bs-toggle='dropdown' aria-expanded='false' style='cursor: pointer;'>
                        <i class='bx bx-dots-vertical-rounded'></i>
                    </button>
                    <div class='dropdown-menu'>
                        <a href='javascript:void(0);' {$delAction} class='dropdown-item'>
                            <i class='bx bx-trash me-1'></i> Delete {$delText}
                        </a>
                    </div>
                </div>
            "
        ];
    }, $result['data']);

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

    $role = db()->table('master_roles')->where('id', $id)->safeOutput()->fetch();
    // $role = db()->where('id', $id)->safeOutput()->fetch('master_roles'); // without using table()

    if (!$role) {
        jsonResponse(['code' => 404, 'message' => 'Role not found']);
    }

    jsonResponse(['code' => 200, 'data' => $role]);
}

/*
|--------------------------------------------------------------------------
| INSERT/UPDATE OPERATION 
|--------------------------------------------------------------------------
*/

function save($request)
{
    if (empty($request['role_name'])) {
        jsonResponse(['code' => 400, 'message' => 'Role name is required']);
    }

    if (empty($request['role_rank'])) {
        jsonResponse(['code' => 400, 'message' => 'Role rank is required']);
    }

    if (empty($request['id'])) {
        $result = db()->table('master_roles')
            ->insert(array_merge(request()->all(), ['created_at' => timestamp()]));
    } else {
        $result = db()->table('master_roles')
            ->where('id', request()->input('id'))
            ->update(array_merge(request()->all(), ['updated_at' => timestamp()]));
    }

    if (isError($result['code'])) {
        jsonResponse(['code' => 422, 'message' => 'Failed to save role']);
    }

    jsonResponse(['code' => 200, 'message' => 'Role saved']);
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

    $result = db()->table('master_roles')->where('id', $id)->delete();

    if (isError($result['code'])) {
        jsonResponse(['code' => 422, 'message' => 'Failed to delete role']);
    }

    jsonResponse(['code' => 200, 'message' => 'Role deleted']);
}

/*
|--------------------------------------------------------------------------
| LIST SELECT OPTION
|--------------------------------------------------------------------------
*/

function listSelectOptionRole($request)
{
    $role = db()->table('master_roles')->safeOutput()->get();
    jsonResponse(['code' => 200, 'data' => $role]);
}
