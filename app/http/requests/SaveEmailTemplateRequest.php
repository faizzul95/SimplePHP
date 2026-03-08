<?php

namespace App\Http\Requests;

use Core\Http\FormRequest;

class SaveEmailTemplateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email_subject' => 'required|string|min_length:3|max_length:255|secure_value',
            'email_type' => 'required|string|min_length:3|max_length:255|secure_value',
            'email_body' => 'required|string',
            'email_status' => 'required|integer|min:0|max:1',
            'id' => 'numeric',
        ];
    }

    public function messages(): array
    {
        return [
            'email_subject.required' => 'Email subject is required.',
            'email_subject.min_length' => 'Email subject must be at least 3 characters.',
            'email_type.required' => 'Email type is required.',
            'email_type.min_length' => 'Email type must be at least 3 characters.',
            'email_body.required' => 'Email body is required.',
        ];
    }
}
