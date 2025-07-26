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
    $statusF = request()->input('email_status');

    $db = db();
    $result = $db->table('master_email_templates')
        ->select('id, email_type, email_subject, email_status, email_cc, email_bcc')
        ->when($statusF == 0 || !empty($statusF), function ($query) use ($statusF) {
            $query->where('email_status', $statusF);
        })
        ->setPaginateFilterColumn(['email_type', 'email_subject'])
        ->safeOutput() // Return with safe value using safeOutput() method to prevent from XSS attack being show in table
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
    $emailTemplate = db()->table('master_email_templates')
        ->where('id', $id)
        ->safeOutputWithException(['email_body'])
        ->fetch();

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
    $validation = request()->validate([
        'email_subject' => 'required|string|min_length:3|max_length:255|secure_value',
        'email_type' => 'required|string|min_length:3|max_length:255|secure_value',
        'email_body' => 'required|string',
        'id' => 'numeric',
    ]);

    if (!$validation->passed()) {
        jsonResponse(['code' => 400, 'message' => $validation->getFirstError()]);
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

    $result = db()->table('master_email_templates')->insertOrUpdate(
        [
            'id' => request()->input('id')
        ],
        $dataToSave
    );

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
