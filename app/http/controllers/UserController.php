<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveUserRequest;
use Core\Http\Controller;
use Core\Http\Request;

class UserController extends Controller
{
    private const USER_STATUS_BADGES = [
        0 => '<span class="badge bg-label-warning"> Inactive </span>',
        1 => '<span class="badge bg-label-success"> Active </span>',
        2 => '<span class="badge bg-label-warning"> Suspended </span>',
        3 => '<span class="badge bg-label-danger"> Deleted </span>',
        4 => '<span class="badge bg-label-dark"> Unverified </span>',
    ];

    public function __construct()
    {
        parent::__construct();
    }

    public function index(): void
    {
        $this->setPageState('directory', null, 'Directory');
        $this->view('directory.users');
    }

    public function listUserDatatable(Request $request): void
    {
        $statusFilter = $request->input('user_status_filter');
        $genderFilter = $request->input('user_gender_filter');
        $profileFilter = $request->input('user_profile_filter');
        $onlyTrashed = in_array($request->input('user_deleted_filter', false), [true, 1, '1', 'true', 'on', 'yes'], true);

        $db = db();
        $db->table('users')
            ->select('id, name, email, user_gender, user_contact_no, user_dob, user_status, deleted_at')
            ->when(strlen((string) $genderFilter) > 0, function ($query) use ($genderFilter) {
                $query->where('user_gender', $genderFilter);
            })
            ->when(strlen((string) $statusFilter) > 0, function ($query) use ($statusFilter) {
                $query->where('user_status', $statusFilter);
            })
            ->when(strlen((string) $profileFilter) > 0, function ($query) use ($profileFilter) {
                if ($profileFilter === 'N/A') {
                    $query->whereDoesntHave('user_profile', 'user_id', 'id');
                } elseif (ctype_digit($profileFilter)) {
                    $query->whereHas('user_profile', 'user_id', 'id', function ($profileQuery) use ($profileFilter) {
                        $profileQuery->where('role_id', (int) $profileFilter);
                    });
                }
            });

        if ($onlyTrashed) {
            $db->whereNotNull('deleted_at');
        } else {
            $db->whereNull('deleted_at');
        }

        $result = $db->setPaginateFilterColumn(['name', 'email', 'user_contact_no'])
            ->setAllowedSortColumns(['users.name', 'users.email', 'users.user_contact_no', 'users.user_gender', 'users.user_status', 'users.user_dob'])
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
            ->paginate_ajax($request->all());

        if (!empty($result['data'])) {
            $result['data'] = array_map(fn($row) => $this->mapUserDatatableRow($row), $result['data']);
        }

        jsonResponse($result);
    }

    public function show(string $id): void
    {
        $id = $this->decodeIdOrFail($id);

        $user = db()->table('users')
            ->where('id', $id)
            ->withOne('profile', 'user_profile', 'user_id', 'id', function ($db) {
                $db->select('id, user_id, role_id')
                    ->where('profile_status', 1)
                    ->where('is_main', 1)
                    ->withOne('roles', 'master_roles', 'id', 'role_id', function ($db) {
                        $db->select('id,role_name')->where('role_status', 1);
                    });
            })
            ->whereNull('deleted_at')
            ->safeOutput()
            ->fetch();

        if (!$user) {
            jsonResponse(['code' => 404, 'message' => 'User not found']);
        }

        jsonResponse(['code' => 200, 'data' => $user]);
    }

    public function save(SaveUserRequest $request): void
    {
        $data = $request->validated();
        $requestId = $data['id'] ?? null;
        $roleId = $data['role_id'] ?? null;
        unset($data['role_id']);

        if (isset($data['id'])) {
            if (!empty($data['id'])) {
                $uniqueColumns = [
                    'email' => 'Email',
                    'username' => 'Username',
                    'user_contact_no' => 'Contact number',
                ];

                $user = db()->table('users')
                    ->select(array_keys($uniqueColumns))
                    ->where('id', $data['id'])
                    ->whereNull('deleted_at')
                    ->safeOutput()
                    ->fetch();

                if (!$user) {
                    jsonResponse(['code' => 404, 'message' => 'User not found']);
                }

                $conditions = [];
                foreach ($uniqueColumns as $column => $label) {
                    if (isset($data[$column]) && isset($user[$column]) && ($data[$column] != $user[$column])) {
                        $conditions[$column] = $data[$column];
                    }
                }

                if (!empty($conditions)) {
                    $duplicates = db()->table('users')
                        ->select(array_keys($conditions))
                        ->where('id', '!=', $data['id'])
                        ->where(function ($q) use ($conditions) {
                            $first = true;
                            foreach ($conditions as $column => $value) {
                                if ($first) {
                                    $q->where($column, $value);
                                    $first = false;
                                } else {
                                    $q->orWhere($column, $value);
                                }
                            }
                        })
                        ->limit(5)
                        ->get();

                    if (!empty($duplicates)) {
                        $duplicateFields = [];

                        foreach ($duplicates as $duplicate) {
                            foreach ($conditions as $column => $value) {
                                if ($duplicate[$column] === $value) {
                                    $duplicateFields[] = $uniqueColumns[$column];
                                }
                            }
                        }

                        if (!empty($duplicateFields)) {
                            jsonResponse([
                                'code' => 422,
                                'message' => implode(', ', array_unique($duplicateFields)) . ' already exist',
                            ]);
                        }
                    }
                }
            }

            unset($data['id']);
        }

        $result = db()->table('users')->insertOrUpdate(
            [
                'id' => $requestId
            ],
            $data
        );

        if (isError($result['code'])) {
            jsonResponse(['code' => 422, 'message' => 'Failed to save user']);
        }

        $userId = $requestId ?: ($result['id'] ?? null);

        if ($userId && $roleId) {
            db()->table('user_profile')->insertOrUpdate(
                [
                    'user_id' => $userId,
                    'is_main' => 1
                ],
                [
                    'user_id' => $userId,
                    'role_id' => $roleId,
                    'profile_status' => 1,
                    'is_main' => 1
                ]
            );
        }

        $row = db()->table('users')
            ->where('id', $userId)
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
            ->whereNull('deleted_at')
            ->safeOutput()
            ->fetch();

        jsonResponse([
            'code' => 200,
            'message' => 'User saved',
            'data' => !empty($row) ? $this->mapUserDatatableRow($row) : null,
        ]);
    }

    public function destroy(string $id): void
    {
        $id = $this->decodeIdOrFail($id);

        $result = db()->table('users')->where('id', $id)->softDelete(
            [
                'user_status' => 3,
                'deleted_at' => timestamp(),
            ]
        );

        if (isError($result['code'])) {
            jsonResponse(['code' => 422, 'message' => 'Failed to delete user']);
        }

        jsonResponse(['code' => 200, 'message' => 'User deleted']);
    }

    private function mapUserDatatableRow(array $row): array
    {
        $key = encodeID($row['id']);
        $rowKey = 'user-row-' . $row['id'];
        $avatar = isset($row['avatar']['files_path']) ? asset(getFilesCompression($row['avatar']), false) : asset('upload/default.jpg');
        $avatarOriginal = isset($row['avatar']['files_path']) ? asset($row['avatar']['files_path'], false) : asset('upload/default.jpg');
        $avatarId = isset($row['avatar']['id']) ? encodeID($row['avatar']['id']) : null;
        $uploadFunc = "updateCropperPhoto('PROFILE UPLOAD', '{$avatarId}', '{$key}', 'USER_PROFILE', 'users', '{$avatarOriginal}', 'getDataList', 'directory', 'avatar')";
        $uploadAction = permission('user-upload-profile') && featureFlag('uploads.image-cropper') ? '<a class="btn btn-icon btn-info btn-xs rounded-circle" href="javascript:void(0)" onclick="' . $uploadFunc . '" style="position: absolute; top: 40px; right: -6px;" title="Change profile">                             
                                                                            <i aria-hidden="true" class="tf-icons bx bx-camera" style="font-size: 0.75rem; position: relative; top: 45%; transform: translateY(-50%);"></i>                            
                                                                        </a>' : '';

        $profileRoleIds = [];
        $profileRoleNames = [];

        if (!empty($row['profile']) && is_array($row['profile'])) {
            foreach ($row['profile'] as $profile) {
                if (isset($profile['role_id'])) {
                    $profileRoleIds[] = (string) $profile['role_id'];
                }

                if (!empty($profile['roles']['role_name'])) {
                    $profileRoleNames[] = $profile['roles']['role_name'];
                }
            }
        }

        $isSuperadmin = in_array('1', $profileRoleIds, true);

        $statusMarkup = !empty($row['deleted_at'])
            ? self::USER_STATUS_BADGES[3]
            : (self::USER_STATUS_BADGES[$row['user_status']] ?? '<span class="badge bg-label-danger"> Unknown Status </span>');

        if (!empty($row['deleted_at'])) {
            $action = "<a href='javascript:void(0);' onclick='restoreRecord(\"{$key}\")' title='Restore users'> <i class='bx bx-refresh'></i> </a>";
        } else {
            $updateAction = permission('user-update') ? "<span style='display: inline-block; vertical-align: middle;'><i class='bx bx-edit-alt' style='cursor: pointer;' onclick='editRecord(\"{$key}\")' title='Edit'></i> </span>" : '';
            $deleteAction = permission('user-delete') ? "<a href='javascript:void(0);' onclick='deleteRecord(\"{$key}\", \"{$rowKey}\")' class='dropdown-item'><i class='bx bx-trash me-1'></i> Delete </a>" : '';
            $resetAction = permission('user-update') ? "<a href='javascript:void(0);' onclick='resetPassword(\"{$key}\")' class='dropdown-item'>
                                                                                                <i class='bx bx-key me-1'></i> Reset Password
                                                                                            </a>" : '';
            $dropdownAction = $isSuperadmin ? null : "<div class='dropdown' style='display: inline-block; vertical-align: middle;'>
                                                                                        <button type='button' class='btn p-0 dropdown-toggle hide-arrow' data-bs-toggle='dropdown' aria-expanded='false' style='cursor: pointer;'>
                                                                                            <i class='bx bx-dots-vertical-rounded'></i>
                                                                                        </button>
                                                                                        <div class='dropdown-menu'>
                                                                                            {$deleteAction}
                                                                                            {$resetAction}
                                                                                        </div>
                                                                                    </div>";

            $action = "{$updateAction} {$dropdownAction}";
        }

        return [
            'row_key' => $rowKey,
            'key' => $key,
            'user_status_value' => isset($row['user_status']) ? (int) $row['user_status'] : null,
            'user_gender_value' => isset($row['user_gender']) ? (int) $row['user_gender'] : null,
            'profile_role_ids' => array_values(array_unique($profileRoleIds)),
            'has_profile' => !empty($profileRoleIds),
            'avatar' => '<div class="avatar-lg" style="position: relative; display:inline-block;">'
                . '<img alt="user image" class="img-fluid img-thumbnail rounded-circle" loading="lazy" src="' . $avatar . '" onerror="this.onerror=null;this.src=\'' . asset('upload/default.jpg') . '\';">'
                . $uploadAction
                . '</div>',
            'name' => ($row['name'] ?? '') . (!empty($profileRoleNames) ? ' <span class="text-muted"><i><small>(' . implode(', ', $profileRoleNames) . ')</i></small></span>' : ''),
            'contact' => '<ul><li>' . implode('</li><li>', ['Email : ' . ($row['email'] ?? ''), empty($row['user_contact_no']) ? 'Contact No : <small><i> (No information provided) </i></small>' : 'Contact No : ' . $row['user_contact_no']]) . '</li></ul>',
            'gender' => (int) ($row['user_gender'] ?? 0) === 1 ? 'Male' : 'Female',
            'status' => $statusMarkup,
            'action' => $action,
        ];
    }
}
