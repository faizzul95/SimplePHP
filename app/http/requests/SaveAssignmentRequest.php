<?php

namespace App\Http\Requests;

use Core\Http\FormRequest;

class SaveAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->isCreate()) {
            return isSuperadmin() || permission('assignments-create');
        }

        if ($this->isUpdate()) {
            return isSuperadmin() || permission('assignments-edit');
        }

        return isSuperadmin();
    }

    public function primaryKey(): string|array
    {
        return 'id';
    }

    public function rules(): array
    {
        return [
            'role_id' => 'required|numeric',
            'abilities_id' => 'required|numeric',
            'all_access' => 'nullable|integer|in:0,1',
            'permission' => 'required|string|in:grant,revoke',
        ];
    }

    public function sanitize(): array
    {
        return [
            'permission' => 'trim|lowercase',
        ];
    }

    public function defaults(): array
    {
        return [
            'all_access' => 0,
        ];
    }

    public function casts(): array
    {
        return [
            'role_id' => 'int',
            'abilities_id' => 'int',
            'all_access' => 'int',
            'permission' => 'trim|lowercase',
        ];
    }

    public function messages(): array
    {
        return [
            'role_id.required' => 'Role ID is required.',
            'abilities_id.required' => 'Abilities ID is required.',
            'permission.required' => 'Permission action is required.',
            'permission.in' => 'Permission action must be one of: :values.',
        ];
    }
}
