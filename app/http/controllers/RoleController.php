<?php

namespace App\Http\Controllers;

use Core\Http\Controller;
use Core\Http\Request;
use App\Http\Requests\SaveRoleRequest;

class RoleController extends Controller
{
    public function __construct()
    {
        parent::__construct();
    }

    public function index(): void
    {
        $this->setPageState('rbac', 'roles', 'rbac-roles-view', 'App Management', 'Roles');
        $this->view('rbac.roles');
    }

    public function listRolesDatatable(Request $request): void
    {
        $status = request()->input('role_status');

        $db = db();
        $result = $db->table('master_roles')->select('id, role_name, role_rank, role_status')
            ->whereNull('deleted_at')
            ->when(strlen((string) $status) > 0, function ($query) use ($status) {
                $query->where('role_status', $status);
            })
            ->withCount('profile', 'user_profile', 'role_id', 'id', function ($q) {
                $q->where('profile_status', '1');
            })
            ->setPaginateFilterColumn(['role_name', 'role_rank'])
            ->safeOutput()
            ->paginate_ajax(request()->all());

        $result['data'] = array_map(function ($row) {
            $id = encodeID($row['id']);
            $canUpdate = permission('rbac-roles-update');
            $canDelete = permission('rbac-roles-delete') && (int) $row['profile_count'] < 1;
            $canAssign = permission('rbac-roles-update');
            $delAction = $canDelete ? "onclick='deleteRecord(\"{$id}\")'" : null;
            $delText = empty($delAction) ? '(disabled)'  : '';
            $editAction = $canUpdate ? "onclick='editRecord(\"{$id}\")'" : '';
            $editStyle = $canUpdate ? "cursor: pointer;" : "cursor: not-allowed; opacity: .45;";
            $assignAction = $canAssign ? "<a href='javascript:void(0);' onclick='permissionRecord(\"{$id}\", \"{$row['role_name']}\")' class='dropdown-item'>
                            <i class='bx bx-shield-quarter me-1'></i> Assign Permissions
                        </a>" : '';

            return [
                'name' => $row['role_name'],
                'rank' => $row['role_rank'],
                'count' => number_format($row['profile_count']),
                'status' => $row['role_status'] ? '<span class="badge bg-label-success"> Active </span>' : '<span class="badge bg-label-warning"> Inactive </span>',
                'action' => "
                <span style='display: inline-block; vertical-align: middle;'>
                    <i class='bx bx-edit-alt' style='{$editStyle}' {$editAction} title='Edit'></i>
                </span>
                <div class='dropdown' style='display: inline-block; vertical-align: middle;'>
                    <button type='button' class='btn p-0 dropdown-toggle hide-arrow' data-bs-toggle='dropdown' aria-expanded='false' style='cursor: pointer;'>
                        <i class='bx bx-dots-vertical-rounded'></i>
                    </button>
                    <div class='dropdown-menu'>
                        <a href='javascript:void(0);' {$delAction} class='dropdown-item'>
                            <i class='bx bx-trash me-1'></i> Delete {$delText}
                        </a>
                        {$assignAction}
                    </div>
                </div>
            "
            ];
        }, $result['data']);

        jsonResponse($result);
    }

    public function show(string $id): void
    {
        $id = decodeID($id);

        if (empty($id)) {
            jsonResponse(['code' => 400, 'message' => 'ID is required']);
        }

        $role = db()->table('master_roles')->where('id', $id)->safeOutput()->fetch();

        if (!$role) {
            jsonResponse(['code' => 404, 'message' => 'Role not found']);
        }

        jsonResponse(['code' => 200, 'data' => $role]);
    }

    public function save(SaveRoleRequest $request): void
    {
        $data = $request->validated();
        $roleId = $data['id'] ?? null;
        unset($data['id']);

        $result = db()->table('master_roles')->insertOrUpdate(
            [
                'id' => $roleId
            ],
            $data
        );

        if (isError($result['code'])) {
            jsonResponse(['code' => 422, 'message' => 'Failed to save role']);
        }

        jsonResponse(['code' => 200, 'message' => 'Role saved']);
    }

    public function destroy(string $id): void
    {
        $id = decodeID($id);

        if (empty($id)) {
            jsonResponse(['code' => 400, 'message' => 'ID is required']);
        }

        $result = db()->table('master_roles')->where('id', $id)->softDelete(
            [
                'role_status' => 0,
                'deleted_at' => timestamp()
            ]
        );

        if (isError($result['code'])) {
            jsonResponse(['code' => 422, 'message' => 'Failed to delete role']);
        }

        jsonResponse(['code' => 200, 'message' => 'Role deleted']);
    }

    public function listSelectOptionRole(Request $request): void
    {
        $role = db()->table('master_roles')->whereNull('deleted_at')->safeOutput()->get();
        jsonResponse(['code' => 200, 'data' => $role]);
    }
}
