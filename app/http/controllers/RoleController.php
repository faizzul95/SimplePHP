<?php

namespace App\Http\Controllers;

use Core\Http\Controller;
use Core\Http\Reply;
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
        $this->setPageState('rbac', 'roles', 'App Management', 'Roles');
        $this->view('rbac.roles');
    }

    public function listRolesDatatable(Request $request): array
    {
        $status = $request->input('role_status');

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
            ->setAllowedSortColumns(['master_roles.role_name', 'master_roles.role_rank', 'master_roles.role_status'])
            ->safeOutput()
            ->paginate_ajax($request->all());

        $result['data'] = array_map([$this, 'mapRoleDatatableRow'], $result['data']);

        // Already the exact shape DataTables expects (draw / recordsTotal /
        // data), and Emitter turns a returned array into a JSON response — so
        // wrapping it would only nest the payload a level deeper.
        return $result;
    }

    public function show(int|string $id): Reply
    {
        if ($id === '') {
            return fail('Role ID is required', 400);
        }

        $role = $this->findOrFail('master_roles', $id, '*', false, 'Role not found');
        return ok(null, $role);
    }

    public function save(SaveRoleRequest $request): Reply
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
            return fail('Failed to save role');
        }

        $savedRoleId = $roleId ?: ($result['id'] ?? null);

        // Fetch the saved role data to return in response for datatable update
        $savedRow = $savedRoleId ? db()->table('master_roles')
            ->select('id, role_name, role_rank, role_status')
            ->where('id', $savedRoleId)
            ->whereNull('deleted_at')
            ->withCount('profile', 'user_profile', 'role_id', 'id', function ($q) {
                $q->where('profile_status', '1');
            })
            ->safeOutput()
            ->fetch() : null;

        return ok('Role saved', $savedRow ? $this->mapRoleDatatableRow($savedRow) : null);
    }

    public function destroy(int|string $id): Reply
    {
        if ($id === '') {
            return fail('Role ID is required', 400);
        }
        
        $result = db()->table('master_roles')->where('id', $id)->softDelete(
            [
                'role_status' => 0,
                'deleted_at' => timestamp()
            ]
        );

        if (isError($result['code'])) {
            return fail('Failed to delete role');
        }

        return ok('Role deleted');
    }

    public function listSelectOptionRole(Request $request): Reply
    {
        $role = db()->table('master_roles')->whereNull('deleted_at')->safeOutput()->get();
        return ok(null, $role);
    }

    private function mapRoleDatatableRow(array $row): array
    {
        $key = $row['id'] ?? null;
        $rowKey = 'role-row-' . $row['id'];
        $canUpdate = permission('rbac-roles-update');
        $canDelete = permission('rbac-roles-delete') && (int) $row['profile_count'] < 1;
        $canAssign = permission('rbac-roles-update');
        
        /*
        | data-* attributes, not onclick.
        |
        | The role name used to go through addslashes() and into a
        | single-quoted onclick. The HTML parser runs before the JS parser
        | and a backslash means nothing to it, so a role named
        | `Ops' onmouseover='...` ended the attribute and the rest parsed as
        | further attributes — stored XSS against any admin who hovered the
        | row. htmlspecialchars is the escaping this position actually needs.
        */
        $safeKey = htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8');
        $safeRowKey = htmlspecialchars($rowKey, ENT_QUOTES, 'UTF-8');
        $safeRoleName = htmlspecialchars((string) $row['role_name'], ENT_QUOTES, 'UTF-8');

        $delAction = $canDelete
            ? "data-dt-action='delete' data-dt-id='{$safeKey}' data-dt-row='{$safeRowKey}'"
            : null;
        $delText = empty($delAction) ? '(disabled)' : '';
        $editAction = $canUpdate ? "data-dt-action='edit' data-dt-id='{$safeKey}'" : '';
        $editStyle = $canUpdate ? "cursor: pointer;" : "cursor: not-allowed; opacity: .45;";
        $assignAction = $canAssign ? "<a href='javascript:void(0);' data-dt-action='permissions' data-dt-id='{$safeKey}' data-dt-name='{$safeRoleName}' class='dropdown-item'>
                            <i class='bx bx-shield-quarter me-1'></i> Assign Permissions
                        </a>" : '';

        return [
            'row_key' => $rowKey,
            'key' => $key,
            'role_status_value' => (int) $row['role_status'],
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
    }
}
