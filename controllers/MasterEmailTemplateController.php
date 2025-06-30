<?php

// IMPORTANT: This file is part of the application
require_once '../init.php';

/*
|--------------------------------------------------------------------------
| LIST (SERVER-SIDE DATATABLE)  
|--------------------------------------------------------------------------
*/

function listEmailTemplateDatatable($request)
{
    $status = request()->input('email_status');

    $db = db();
    $db->table('master_email_templates')->select('id, email_type, email_subject, email_status, email_cc, email_bcc');

    if ($status == 0 || !empty($status)) {
        $db->where('email_status', $status);
    }

    // Return with safe value using safeOutput() method to prevent from XSS attack being show in table
    $result = $db->setPaginateFilterColumn(['email_type', 'email_subject'])
        ->safeOutput()
        ->paginate_ajax(request()->all());

    // Alter/formatting the data return
    $result['data'] = array_map(function ($row) {
        $id = encodeID($row['id']);

        return [
            'type' => $row['email_type'],
            'subject' => $row['email_subject'],
            'cc' => empty($row['email_cc']) ? 'NO' : 'YES',
            'bcc' => empty($row['email_bcc']) ? 'NO' : 'YES',
            'status' => $row['email_status'] ? '<span class="badge bg-label-success"> Active </span>' : '<span class="badge bg-label-warning"> Inactive </span>',
            'action' => "
                <span style='display: inline-block; vertical-align: middle;'>
                    <i class='bx bx-edit-alt' style='cursor: pointer;' onclick='editRecord(\"{$id}\")' title='Edit'></i>
                </span>
                <div class='dropdown' style='display: inline-block; vertical-align: middle;'>
                    <button type='button' class='btn p-0 dropdown-toggle hide-arrow' data-bs-toggle='dropdown' aria-expanded='false' style='cursor: pointer;'>
                        <i class='bx bx-dots-vertical-rounded'></i>
                    </button>
                    <div class='dropdown-menu'>
                        <a href='javascript:void(0);' onclick='deleteRecord(\"{$id}\")' class='dropdown-item'>
                            <i class='bx bx-trash me-1'></i> Delete
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

    // Can't use ->safeOutput() since need to update the email_body
    $emailTemplate = db()->table('master_email_templates')->where('id', $id)->safeOutputWithException(['email_body'])->fetch();

    if (!$emailTemplate) {
        jsonResponse(['code' => 404, 'message' => 'Email template not found']);
    }

    jsonResponse(['code' => 200, 'data' => $emailTemplate]);
}

/*
|--------------------------------------------------------------------------
| INSERT/UPDATE OPERATION 
|--------------------------------------------------------------------------
*/

function save($request)
{
    if (empty($request['email_subject'])) {
        jsonResponse(['code' => 400, 'message' => 'Email subject is required']);
    }

    if (empty($request['email_type'])) {
        jsonResponse(['code' => 400, 'message' => 'Email type is required']);
    }

    if (empty($request['email_body'])) {
        jsonResponse(['code' => 400, 'message' => 'Description is required']);
    }

    $dataToSave = [
        'email_type' => request()->input('email_type'),
        'email_subject' => request()->input('email_subject'),
        'email_body' => $request['email_body'], //  use $request to escape from sanitize since this will save as html code
        'email_footer' => request()->input('email_footer'),
        'email_cc' => request()->input('email_cc'),
        'email_bcc' => request()->input('email_bcc'),
        'email_status' => request()->input('email_status')
    ];

    if (empty($request['id'])) {
        $result = db()->table('master_email_templates')
            ->insert(array_merge($dataToSave, ['created_at' => timestamp()]));
    } else {
        $result = db()->table('master_email_templates')
            ->where('id', request()->input('id'))
            ->update(array_merge($dataToSave, ['updated_at' => timestamp()]));
    }

    if (isError($result['code'])) {
        jsonResponse(['code' => 422, 'message' => 'Failed to save email template']);
    }

    jsonResponse(['code' => 200, 'message' => 'Email template saved']);
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

    $result = db()->table('master_email_templates')->where('id', $id)->delete();

    if (isError($result['code'])) {
        jsonResponse(['code' => 422, 'message' => 'Failed to delete email template']);
    }

    jsonResponse(['code' => 200, 'message' => 'Email template deleted']);
}
