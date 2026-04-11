<?php

namespace App\Http\Requests;

use Core\Http\FormRequest;

class SaveRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->isCreate()) {
            return isSuperadmin() || permission('rbac-roles-create');
        }

        if ($this->isUpdate()) {
            return isSuperadmin() || permission('rbac-roles-update');
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
            'role_name' => 'required|string|min_length:2|max_length:64|secure_value',
            'role_rank' => 'required|numeric|min:1|max:99999',
            'role_status' => 'required|integer|min:0|max:1',
            'id' => 'nullable|numeric',
        ];
    }

    public function sanitize(): array
    {
        return [
            'role_name' => 'trim|strip_tags|normalize_spaces',
        ];
    }

    public function casts(): array
    {
        return [
            'role_name' => 'trim|ucwords',
            'role_rank' => 'int',
            'role_status' => 'int',
            'id' => 'int',
        ];
    }

    public function messages(): array
    {
        return [
            'role_name.required' => 'Role name is required.',
            'role_name.min_length' => 'Role name must be at least :min characters.',
            'role_name.max_length' => 'Role name may not be greater than :max characters.',
            'role_rank.required' => 'Role rank is required.',
            'role_rank.numeric' => 'Role rank must be a number.',
            'role_status.required' => 'Role status is required.',
        ];
    }
}
