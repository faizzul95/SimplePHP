<?php

namespace App\Http\Controllers;

use Core\Http\Controller;
use Core\Http\Reply;
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
        $this->setPageState('rbac', 'email', 'App Management', 'Email Template');
        $this->view('rbac.emailTemplate');
    }

    public function listEmailTemplateDatatable(Request $request): array
    {
        $statusF = $request->input('email_status');

        $db = db();
        $result = $db->table('master_email_templates')
            ->select('id, email_type, email_subject, email_status, email_cc, email_bcc')
            ->when(strlen((string) $statusF) > 0, function ($query) use ($statusF) {
                $query->where('email_status', $statusF);
            })
            ->setPaginateFilterColumn(['email_type', 'email_subject'])
            ->setAllowedSortColumns(['master_email_templates.email_type', 'master_email_templates.email_subject', 'master_email_templates.email_status'])
            ->safeOutput()
            ->paginate_ajax($request->all());

        $result['data'] = array_map([$this, 'mapEmailTemplateDatatableRow'], $result['data']);

        // Already the exact shape DataTables expects (draw / recordsTotal /
        // data), and Emitter turns a returned array into a JSON response — so
        // wrapping it would only nest the payload a level deeper.
        return $result;
    }

    public function show(int|string $id): Reply
    {
        if ($id === '') {
            return fail('Email template ID is required', 400);
        }

        $emailTemplate = db()->table('master_email_templates')
            ->where('id', $id)
            ->safeOutputWithException(['email_body'])
            ->fetch();

        if (!$emailTemplate) {
            return fail('Email template not found', 404);
        }

        return ok(null, $emailTemplate);
    }

    public function save(SaveEmailTemplateRequest $request): Reply
    {
        $dataToSave = $request->validated();
        $templateId = $dataToSave['id'] ?? null;
        unset($dataToSave['id']);

        $result = db()->table('master_email_templates')->insertOrUpdate(
            ['id' => $templateId],
            $dataToSave
        );

        if (isError($result['code'])) {
            return fail('Failed to save email template');
        }

        $savedTemplateId = $templateId ?: ($result['id'] ?? null);
        $savedRow = $savedTemplateId ? db()->table('master_email_templates')
            ->select('id, email_type, email_subject, email_status, email_cc, email_bcc')
            ->where('id', $savedTemplateId)
            ->safeOutput()
            ->fetch() : null;

        // JSON for the datatable's XHR, a redirect carrying the message as flash
        // for a plain form post. Neither branch is written here.
        return ok(
            'Email template saved',
            $savedRow ? $this->mapEmailTemplateDatatableRow($savedRow) : null
        )->route('rbac.email');
    }

    public function destroy(int|string $id): Reply
    {
        if ($id === '') {
            return fail('Email template ID is required', 400);
        }

        $result = db()->table('master_email_templates')->where('id', $id)->delete();

        if (isError($result['code'])) {
            return fail('Failed to delete email template');
        }

        return ok('Email template deleted')->route('rbac.email');
    }

    private function mapEmailTemplateDatatableRow(array $row): array
    {
        $key = $row['id'] ?? null;
        $rowKey = 'email-template-row-' . $row['id'];
        $canUpdate = permission('rbac-email-update');
        $canDelete = permission('rbac-email-delete');
        $safeKey = htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8');
        $safeRowKey = htmlspecialchars((string) $rowKey, ENT_QUOTES, 'UTF-8');
        $editAction = $canUpdate ? "data-dt-action='edit' data-dt-id='{$safeKey}'" : '';
        $editStyle = $canUpdate ? 'cursor: pointer;' : 'cursor: not-allowed; opacity: .45;';
        $deleteAction = $canDelete
            ? "data-dt-action='delete' data-dt-id='{$safeKey}' data-dt-row='{$safeRowKey}'"
            : '';
        $deleteText = $canDelete ? 'Delete' : 'Delete (disabled)';

        return [
            'row_key' => $rowKey,
            'key' => $key,
            'email_status_value' => isset($row['email_status']) ? (int) $row['email_status'] : null,
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
    }
}
