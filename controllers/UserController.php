<?php

// IMPORTANT: This file is part of the application
require_once '../init.php';

/*
|--------------------------------------------------------------------------
| LIST (SERVER-SIDE DATATABLE)  
|--------------------------------------------------------------------------
*/

function listUserDatatable($request)
{
    // Filter dropdown
    $statusF = request()->input('user_status_filter');
    $genderF = request()->input('user_gender_filter');

    $db = db();
    $db->table('users')->select('id, name, email, user_gender, user_contact_no, user_dob, user_status');

    if ($statusF == 0 || !empty($statusF)) {
        $db->where('user_status', $statusF);
    }

    if ($genderF == 0 || !empty($genderF)) {
        $db->where('user_gender', $genderF);
    }

    // Return with safe value using safeOutput() method to prevent from XSS attack being show in table
    $result = $db->setPaginateFilterColumn(['name', 'email', 'user_contact_no'])
        ->safeOutput()
        ->paginate_ajax(request()->all());

    $status = [
        '<span class="badge bg-label-warning"> Inactive </span>',
        '<span class="badge bg-label-success"> Active </span>',
        '<span class="badge bg-label-warning"> Suspended </span>',
        '<span class="badge bg-label-danger"> Deleted </span>',
        '<span class="badge bg-label-dark"> Unverified </span>'
    ];

    // Alter/formatting the data return
    $result['data'] = array_map(function ($row) use ($status) {
        $id = encodeID($row['id']);
        return [
            'name' => $row['name'],
            'email' => $row['email'],
            'gender' => $row['user_gender'] == 1 ? 'Male' : 'Female',
            'status' => $status[$row['user_status']] ?? '<span class="badge bg-label-danger"> Unknown Status </span>',
            'action' => "<center>
                                <button class='btn btn-outline-dark btn-sm' onclick='updateProfile(\"{$id}\")' title='Profile'> <span class='tf-icons bx bx-user-pin'></span> </button> 
                                <button class='btn btn-primary btn-sm' onclick='editRecord(\"{$id}\")' title='Edit' > <span class='tf-icons bx bx-edit'></span> </button> 
                                <button class='btn btn-danger btn-sm' onclick='deleteRecord(\"{$id}\")' title='Delete'> <span class='tf-icons bx bx-trash'></span> </button>
                            </center>"
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

    $db = db();
    $users = $db->table('users')
        ->where('id', $id)
        ->withOne('profile', 'user_profile', 'user_id', 'id', function ($db) {
            $db->select('id, user_id, role_id')
                ->where('profile_status', 1)
                ->where('is_main', 1)
                ->withOne('roles', 'master_roles', 'id', 'role_id', function ($db) {
                    $db->select('id,role_name')->where('role_status', 1);
                    // ->with('permission', 'system_permission', 'role_id', 'id', function ($db) {
                    //     $db->select('id,role_id,abilities_id')
                    //         ->withOne('abilities', 'system_abilities', 'id', 'abilities_id', function ($db) {
                    //             $db->select('id,abilities_name,abilities_slug');
                    //         });
                    // });
                });
            // ->withOne('avatar', 'entity_files', 'entity_id', 'id', function ($db) {
            //     $db->select('id, entity_id, files_name, files_path, files_disk_storage, files_path_is_url, files_compression')
            //         ->where('entity_file_type', 'USER_PROFILE');
            // });
        })
        ->safeOutput()
        ->fetch();

    if (!$users) {
        jsonResponse(['code' => 404, 'message' => 'User not found']);
    }

    jsonResponse(['code' => 200, 'data' => $users]);
}

/*
|--------------------------------------------------------------------------
| INSERT/UPDATE OPERATION 
|--------------------------------------------------------------------------
*/

function save($request)
{
    if (empty($request['name'])) {
        jsonResponse(['code' => 400, 'message' => 'User name is required']);
    }

    if (empty($request['user_preferred_name'])) {
        jsonResponse(['code' => 400, 'message' => 'Preferred name is required']);
    }

    if (empty($request['email'])) {
        jsonResponse(['code' => 400, 'message' => 'Email is required']);
    }

    // use upsert to reduce code & optimize for large records
    $result = db()->table('users')->upsert(request()->all());

    if (isError($result['code'])) {
        jsonResponse(['code' => 422, 'message' => 'Failed to save user']);
    }

    jsonResponse(['code' => 200, 'message' => 'User saved']);
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

    $result = db()->table('users')->where('id', $id)->delete();

    if (isError($result['code'])) {
        jsonResponse(['code' => 422, 'message' => 'Failed to delete user']);
    }

    jsonResponse(['code' => 200, 'message' => 'User deleted']);
}
