<?php

namespace App\Http\Requests;

use Core\Http\FormRequest;

class SaveRoleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'role_name' => 'required|string|min_length:3|max_length:64|secure_value',
            'role_rank' => 'required|numeric|min:1|max:99999',
            'role_status' => 'required|integer|min:0|max:1',
            'id' => 'numeric',
        ];
    }

    public function messages(): array
    {
        return [
            'role_name.required' => 'Role name is required.',
            'role_name.min_length' => 'Role name must be at least 3 characters.',
            'role_name.max_length' => 'Role name may not be greater than 64 characters.',
            'role_rank.required' => 'Role rank is required.',
            'role_rank.numeric' => 'Role rank must be a number.',
            'role_status.required' => 'Role status is required.',
        ];
    }
}
