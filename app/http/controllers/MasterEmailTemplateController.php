<?php

namespace App\Http\Controllers;

use Core\Http\Controller;
use Core\Http\Request;
use App\Http\Requests\SaveEmailTemplateRequest;

class MasterEmailTemplateController extends Controller
{
    public function __construct()
    {
        parent::__construct();
    }

    public function index(): void
    {
        $this->setPageState('rbac', 'email', 'rbac-email-view', 'App Management', 'Email Template');
        $this->view('rbac.emailTemplate');
    }

    public function listEmailTemplateDatatable(Request $request): void
    {
        $statusF = request()->input('email_status');

        $db = db();
        $result = $db->table('master_email_templates')
            ->select('id, email_type, email_subject, email_status, email_cc, email_bcc')
            ->when(strlen((string) $statusF) > 0, function ($query) use ($statusF) {
                $query->where('email_status', $statusF);
            })
            ->setPaginateFilterColumn(['email_type', 'email_subject'])
            ->safeOutput()
            ->paginate_ajax(request()->all());

        $result['data'] = array_map(function ($row) {
            $id = encodeID($row['id']);
            $canUpdate = permission('rbac-email-update');
            $canDelete = permission('rbac-email-delete');
            $editAction = $canUpdate ? "onclick='editRecord(\"{$id}\")'" : '';
            $editStyle = $canUpdate ? "cursor: pointer;" : "cursor: not-allowed; opacity: .45;";
            $deleteAction = $canDelete ? "onclick='deleteRecord(\"{$id}\")'" : '';
            $deleteText = $canDelete ? 'Delete' : 'Delete (disabled)';

            return [
                'type' => $row['email_type'],
                'subject' => $row['email_subject'],
                'cc' => empty($row['email_cc']) ? 'NO' : 'YES',
                'bcc' => empty($row['email_bcc']) ? 'NO' : 'YES',
                'status' => $row['email_status'] ? '<span class="badge bg-label-success"> Active </span>' : '<span class="badge bg-label-warning"> Inactive </span>',
                'action' => "
                <span style='display: inline-block; vertical-align: middle;'>
                    <i class='bx bx-edit-alt' style='{$editStyle}' {$editAction} title='Edit'></i>
                </span>
                <div class='dropdown' style='display: inline-block; vertical-align: middle;'>
                    <button type='button' class='btn p-0 dropdown-toggle hide-arrow' data-bs-toggle='dropdown' aria-expanded='false' style='cursor: pointer;'>
                        <i class='bx bx-dots-vertical-rounded'></i>
                    </button>
                    <div class='dropdown-menu'>
                        <a href='javascript:void(0);' {$deleteAction} class='dropdown-item'>
                            <i class='bx bx-trash me-1'></i> {$deleteText}
                        </a>
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

        $emailTemplate = db()->table('master_email_templates')
            ->where('id', $id)
            ->safeOutputWithException(['email_body'])
            ->fetch();

        if (!$emailTemplate) {
            jsonResponse(['code' => 404, 'message' => 'Email template not found']);
        }

        jsonResponse(['code' => 200, 'data' => $emailTemplate]);
    }

    public function save(SaveEmailTemplateRequest $request): void
    {
        $dataToSave = $request->validated();
        $templateId = $dataToSave['id'] ?? null;
        unset($dataToSave['id']);

        $result = db()->table('master_email_templates')->insertOrUpdate(
            [
                'id' => $templateId
            ],
            $dataToSave
        );

        if (isError($result['code'])) {
            jsonResponse(['code' => 422, 'message' => 'Failed to save email template']);
        }

        jsonResponse(['code' => 200, 'message' => 'Email template saved']);
    }

    public function destroy(string $id): void
    {
        $id = decodeID($id);

        if (empty($id)) {
            jsonResponse(['code' => 400, 'message' => 'ID is required']);
        }

        $result = db()->table('master_email_templates')->where('id', $id)->delete();

        if (isError($result['code'])) {
            jsonResponse(['code' => 422, 'message' => 'Failed to delete email template']);
        }

        jsonResponse(['code' => 200, 'message' => 'Email template deleted']);
    }
}
