<?php

namespace App\Http\Requests;

use Core\Http\FormRequest;

class SaveEmailTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->isCreate()) {
            return auth()->can('rbac-email-create');
        }

        if ($this->isUpdate()) {
            return auth()->can('rbac-email-update');
        }

        return false;
    }

    public function primaryKey(): string|array
    {
        return 'id';
    }

    public function rules(): array
    {
        return [
            'email_subject' => 'required|string|min_length:3|max_length:255|secure_value',
            'email_type' => 'required|string|min_length:3|max_length:255|secure_value',
            'email_body' => 'required|string|min_length:5|max_length:200000|safe_html',
            'email_status' => 'required|integer|min:0|max:1',
            'email_footer' => 'nullable|string',
            'email_cc' => 'nullable|string',
            'email_bcc' => 'nullable|string',
            'id' => 'nullable|numeric',
        ];
    }

    public function sanitize(): array
    {
        return [
            'email_subject' => 'trim|strip_tags|normalize_spaces',
            'email_type' => 'trim|strip_tags|normalize_spaces',
            'email_body' => 'no_null_bytes|normalize_newlines',
            'email_footer' => 'trim|no_null_bytes',
            'email_cc' => 'trim|no_null_bytes',
            'email_bcc' => 'trim|no_null_bytes',
        ];
    }

    public function defaults(): array
    {
        return [
            'id' => null,
            'email_footer' => null,
            'email_cc' => null,
            'email_bcc' => null,
        ];
    }

    public function casts(): array
    {
        return [
            'email_subject' => 'trim',
            'email_type' => 'trim',
            'email_status' => 'int',
            'email_footer' => 'nullable_string',
            'email_cc' => 'nullable_string',
            'email_bcc' => 'nullable_string',
            'id' => 'int',
        ];
    }

    public function messages(): array
    {
        return [
            'email_subject.required' => 'Email subject is required.',
            'email_subject.min_length' => 'Email subject must be at least :min characters.',
            'email_type.required' => 'Email type is required.',
            'email_type.min_length' => 'Email type must be at least :min characters.',
            'email_body.required' => 'Email body is required.',
            'email_body.safe_html' => 'Email body contains unsafe HTML or disallowed attributes.',
        ];
    }
}
