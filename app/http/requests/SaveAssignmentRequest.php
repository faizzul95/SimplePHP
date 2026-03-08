<?php

namespace App\Http\Requests;

use Core\Http\FormRequest;

class SaveAssignmentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'role_id' => 'required|numeric',
            'abilities_id' => 'required|numeric',
            'all_access' => 'numeric',
            'permission' => 'required|string|secure_value',
        ];
    }

    public function messages(): array
    {
        return [
            'role_id.required' => 'Role ID is required.',
            'abilities_id.required' => 'Abilities ID is required.',
            'permission.required' => 'Permission action is required.',
        ];
    }
}
