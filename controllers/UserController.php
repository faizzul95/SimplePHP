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
    $profileF = request()->input('user_profile_filter');
    $onlyTrashed = request()->input('user_deleted_filter', false);

    $db = db();
    $db->table('users')
        ->select('id, name, email, user_gender, user_contact_no, user_dob, user_status, deleted_at')
        ->when($genderF == 0 || !empty($genderF), function ($query) use ($genderF) {
            $query->where('user_gender', $genderF);
        })
        ->when($statusF == 0 || !empty($statusF), function ($query) use ($statusF) {
            $query->where('user_status', $statusF);
        })
        ->when($profileF == 0 || !empty($profileF), function ($query) use ($profileF) {
            if ($profileF == 'N/A') {
                $query->whereRaw('NOT EXISTS (SELECT 1 FROM user_profile WHERE user_id = users.id)');
            } else {
                $query->whereRaw('EXISTS (SELECT 1 FROM user_profile WHERE user_id = users.id AND role_id = ?)', [$profileF]);
            }
        });

    if ($onlyTrashed) {
        $db->whereNotNull('deleted_at'); // only show deleted users
    } else {
        $db->whereNull('deleted_at');
    }

    // Return with safe value using safeOutput() method to prevent from XSS attack being show in table
    $result = $db->setPaginateFilterColumn(['name', 'email', 'user_contact_no'])
        ->with('profile', 'user_profile', 'user_id', 'id', function ($db) {
            $db->select('id, user_id, role_id')
                ->withOne('roles', 'master_roles', 'id', 'role_id', function ($db) {
                    $db->select('id,role_name')->where('role_status', 1);
                });
        })
        ->withOne('avatar', 'entity_files', 'entity_id', 'id', function ($db) {
            $db->select('id, entity_id, files_name, files_path, files_disk_storage, files_path_is_url, files_compression, files_folder')
                ->where('entity_file_type', 'USER_PROFILE');
        })
        ->safeOutput()
        ->paginate_ajax(request()->all());

    $status = [
        '<span class="badge bg-label-warning"> Inactive </span>',
        '<span class="badge bg-label-success"> Active </span>',
        '<span class="badge bg-label-warning"> Suspended </span>',
        '<span class="badge bg-label-danger"> Deleted </span>',
        '<span class="badge bg-label-dark"> Unverified </span>'
    ];

    if (!empty($result['data'])) {
        $listSuperadmin = db()->table('user_profile')->where('role_id', 1)->pluck('user_id');

        // Alter/formatting the data return
        $result['data'] = array_map(function ($row) use ($status, $listSuperadmin) {
            $id = encodeID($row['id']);
            $avatar = isset($row['avatar']['files_path']) ? asset(getFilesCompression($row['avatar']), false) : asset('upload/default.jpg');
            $avatarOriginal = isset($row['avatar']['files_path']) ? asset($row['avatar']['files_path'], false) : asset('upload/default.jpg');
            $avatarId = isset($row['avatar']['id']) ? encodeID($row['avatar']['id']) : null;

            $uploadFunc = "updateCropperPhoto('PROFILE UPLOAD', '{$avatarId}', '{$id}', 'USER_PROFILE', 'users', '{$avatarOriginal}', 'getDataList', 'directory', 'avatar')";
            $uploadAction = permission('settings-upload-image') ? '<a class="btn btn-icon btn-info btn-xs rounded-circle" href="javascript:void(0)" onclick="' . $uploadFunc . '" style="position: absolute; top: 40px; right: -6px;" title="Change profile">
                                <i aria-hidden="true" class="tf-icons bx bx-camera" style="font-size: 0.75rem; position: relative; top: 45%; transform: translateY(-50%);"></i>
                            </a>' : '';

            $statusUser = !empty($row['deleted_at']) ? '<span class="badge bg-label-danger"> Deleted </span>' : $status[$row['user_status']] ?? '<span class="badge bg-label-danger"> Unknown Status </span>';

            if (!empty($row['deleted_at'])) {
                $action = "<a href='javascript:void(0);' onclick='restoreRecord(\"{$id}\")' title='Restore users'> <i class='bx bx-refresh'></i> </a>";
            } else {
                $delResetAction = in_array($row['id'], $listSuperadmin) ? null : "<div class='dropdown' style='display: inline-block; vertical-align: middle;'>
                                <button type='button' class='btn p-0 dropdown-toggle hide-arrow' data-bs-toggle='dropdown' aria-expanded='false' style='cursor: pointer;'>
                                    <i class='bx bx-dots-vertical-rounded'></i>
                                </button>
                                <div class='dropdown-menu'>
                                    <a href='javascript:void(0);' onclick='deleteRecord(\"{$id}\")' class='dropdown-item'>
                                        <i class='bx bx-trash me-1'></i> Delete
                                    </a>
                                    <a href='javascript:void(0);' onclick='resetPassword(\"{$id}\")' class='dropdown-item'>
                                        <i class='bx bx-key me-1'></i> Reset Password
                                    </a>
                                </div>
                            </div>";

                $action = "
                <span style='display: inline-block; vertical-align: middle;'>
                    <i class='bx bx-edit-alt' style='cursor: pointer;' onclick='editRecord(\"{$id}\")' title='Edit'></i>
                </span>
               {$delResetAction}
            ";
            }

            return [
                'avatar' => '<div class="avatar-lg" style="position: relative; display:inline-block;">
                            <img alt="user image" class="img-fluid img-thumbnail rounded-circle" loading="lazy" src="' . $avatar . '" onerror="this.onerror=null;this.src=\'' . asset('upload/default.jpg') . '\';">
                           ' . $uploadAction . '
                        </div>',
                // 'name' => $row['name'],
                'name' => $row['name'] . (
                    !empty($row['profile']) && is_array($row['profile'])
                    ? ' <span class="text-muted">(' . implode(', ', array_map(function ($p) {
                        return isset($p['roles']['role_name']) ? $p['roles']['role_name'] : '';
                    }, $row['profile'])) . ')</span>'
                    : ''
                ),
                'contact' => '<ul><li>' . implode('</li><li>', ['Email : ' . $row['email'], empty($row['user_contact_no']) ? 'Contact No : <small><i> (No information provided) </i></small>' : 'Contact No : ' . $row['user_contact_no']]) . '</li></ul>',
                'gender' => $row['user_gender'] == 1 ? 'Male' : 'Female',
                'status' => $statusUser,
                'action' => $action
            ];
        }, $result['data']);
    }

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
                });
            // ->withOne('avatar', 'entity_files', 'entity_id', 'id', function ($db) {
            //     $db->select('id, entity_id, files_name, files_path, files_disk_storage, files_path_is_url, files_compression')
            //         ->where('entity_file_type', 'USER_PROFILE');
            // });
        })
        ->whereNull('deleted_at')
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
    $data = request()->all();

    $validation = validator($data, [
        'name' => 'required|string|min_length:3|max_length:255|secure_value',
        'user_preferred_name' => 'required|string|min_length:3|max_length:255|secure_value',
        'email' => 'required|email|max_length:255|secure_value',
        'user_contact_no' => 'required|numeric|min_length:10|max_length:15',
        'role_id' => 'required|integer|min:1',
        'user_status' => 'required|integer|min:0|max_length:2',
        'id' => 'numeric',
    ])->validate();

    if (!$validation->passed()) {
        jsonResponse(['code' => 400, 'message' => $validation->getFirstError()]);
    }

    if (isset($data['id'])) {
        unset($data['id']); // Remove ID from data if exists, 
    }

    if (empty($request['id'])) {

        $username = request()->input('username');
        if (empty($username)) {
            $username = request()->input('email');;
        }

        $password = request()->input('password');
        if (empty($password)) {
            $password = request()->input('user_contact_no');
        }

        $data = array_merge($data, ['username' => $username, 'password' => password_hash($password, PASSWORD_DEFAULT)]);
    }

    $result = db()->table('users')->insertOrUpdate(
        [
            'id' => request()->input('id')
        ],
        $data
    );

    if (isError($result['code'])) {
        jsonResponse(['code' => 422, 'message' => 'Failed to save user']);
    }

    db()->table('user_profile')->insertOrUpdate(
        [
            'id' => request()->input('id')
        ],
        [
            'user_id' => request()->input('id'),
            'role_id' => $request['role_id'],
            'profile_status' => 1,
            'is_main' => 1
        ]
    );

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

    // $result = db()->table('users')->where('id', $id)->delete();
    $result = db()->table('users')->where('id', $id)->softDelete(
        [
            'user_status' => 3,
            'deleted_at' => timestamp()
        ]
    );

    if (isError($result['code'])) {
        jsonResponse(['code' => 422, 'message' => 'Failed to delete user']);
    }

    jsonResponse(['code' => 200, 'message' => 'User deleted']);
}
