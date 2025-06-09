<?php

// IMPORTANT: This file is part of the application
require_once '../init.php';

/*
|--------------------------------------------------------------------------
| LIST (SERVER-SIDE DATATABLE) INFORMATION 
|--------------------------------------------------------------------------
*/

function listRolesDatatable($request)
{
    $draw = request()->input('draw');
    $start = request()->input('start');
    $limit = request()->input('length');
    $searchValue = request()->input('search')['value'] ?? '';
    $status = request()->input('role_status');

    $db = db();
    $db->table('master_roles')->select('id, role_name, role_rank, role_status');

    if ($status == 0 || !empty($status)) {
        $db->where('role_status', $status);
    }

    if (!empty($searchValue)) {
        $db->where('role_name', 'LIKE', '%' . $searchValue . '%');
    }

    // Return with safe value using safeOutput() method to prevent from XSS attack being show in table
    $result = $db->safeOutput()->paginate($start, $limit, $draw);

    jsonResponse([
        'draw' => $draw,
        'recordsTotal' => $result['recordsTotal'],
        'recordsFiltered' => $result['recordsFiltered'],
        'data' => array_map(function ($row) {
            return [
                'name' => $row['role_name'],
                'rank' => $row['role_rank'],
                'status' => $row['role_status'] ? '<span class="badge bg-label-success"> Active </span>' : '<span class="badge bg-label-danger"> Inactive </span>',
                'action' => "<center>
                                <button class='btn btn-primary btn-sm' onclick='editRecord({$row['id']})'> <span class='tf-icons bx bx-edit'></span> </button> 
                                <button class='btn btn-danger btn-sm' onclick='deleteRecord({$row['id']})'> <span class='tf-icons bx bx-trash'></span> </button>
                            </center>"
            ];
        }, $result['data'])
    ]);
}

/*
|--------------------------------------------------------------------------
| SHOW INFORMATION 
|--------------------------------------------------------------------------
*/

function show($request)
{
    $id = request()->input('id');

    if (empty($id)) {
        jsonResponse(['code' => 400, 'message' => 'ID is required']);
    }

    $role = db()->table('master_roles')->where('id', $id)->fetch();

    if (!$role) {
        jsonResponse(['code' => 404, 'message' => 'Role not found']);
    }

    jsonResponse(['code' => 200, 'data' => $role]);
}

/*
|--------------------------------------------------------------------------
| INSERT/UPDATE INFORMATION 
|--------------------------------------------------------------------------
*/

function save($request)
{
    $id = request()->input('id');
    $roleName = request()->input('role_name');
    $roleRank = request()->input('role_rank');
    $roleStatus = request()->input('role_status');

    if (empty($roleName)) {
        jsonResponse(['code' => 400, 'message' => 'Role name is required']);
    }

    if (empty($roleRank)) {
        jsonResponse(['code' => 400, 'message' => 'Role rank is required']);
    }

    $data = [
        'role_name' => $roleName,
        'role_rank' => $roleRank,
        'role_status' => $roleStatus
    ];

    if (empty($id)) {
        // Insert new role
        $result = db()->table('master_roles')->insert($data);
    } else {
        // Update existing role
        $result = db()->table('master_roles')->where('id', $id)->update($data);
    }

    if (isError($result['code'])) {
        jsonResponse(['code' => 422, 'message' => 'Failed to save role']);
    }

    jsonResponse(['code' => 200, 'message' => 'Role saved']);
}

/*
|--------------------------------------------------------------------------
| DELETE INFORMATION 
|--------------------------------------------------------------------------
*/

function destroy($request)
{
    $id = request()->input('id');

    if (empty($id)) {
        jsonResponse(['code' => 400, 'message' => 'ID is required']);
    }

    $result = db()->table('master_roles')->where('id', $id)->delete();

    if (isError($result['code'])) {
        jsonResponse(['code' => 422, 'message' => 'Failed to delete role']);
    }

    jsonResponse(['code' => 200, 'message' => 'Role deleted']);
}
