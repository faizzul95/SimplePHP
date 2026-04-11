<?php

namespace App\Http\Requests;

use Core\Http\FormRequest;

class SaveAbilitiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->isCreate()) {
            return isSuperadmin() || permission('rbac-abilities-create');
        }

        if ($this->isUpdate()) {
            return isSuperadmin() || permission('rbac-abilities-edit');
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
            'abilities_name' => 'required|string|min_length:5|max_length:50|secure_value',
            'abilities_slug' => 'required|string|min_length:5|max_length:100|secure_value',
            'abilities_desc' => 'nullable|string|max_length:255|secure_value',
            'id' => 'nullable|numeric',
        ];
    }

    public function sanitize(): array
    {
        return [
            'abilities_name' => 'trim|strip_tags|normalize_spaces',
            'abilities_slug' => 'trim|strip_tags|no_null_bytes',
            'abilities_desc' => 'trim|strip_tags|normalize_spaces',
        ];
    }

    public function defaults(): array
    {
        return [
            'id' => null,
            'abilities_desc' => null,
        ];
    }

    public function casts(): array
    {
        return [
            'abilities_name' => 'trim|ucwords',
            'abilities_slug' => 'trim|lowercase|slug',
            'abilities_desc' => 'nullable_string',
            'id' => 'int',
        ];
    }

    public function messages(): array
    {
        return [
            'abilities_name.required' => 'Abilities name is required.',
            'abilities_name.min_length' => 'Abilities name must be at least :min characters.',
            'abilities_name.max_length' => 'Abilities name may not be greater than :max characters.',
            'abilities_slug.required' => 'Abilities slug is required.',
            'abilities_slug.min_length' => 'Abilities slug must be at least :min characters.',
            'abilities_slug.max_length' => 'Abilities slug may not be greater than :max characters.',
            'abilities_desc.max_length' => 'Description may not be greater than :max characters.',
        ];
    }
}
