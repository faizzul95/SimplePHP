<?php

namespace App\Http\Requests;

use Core\Http\FormRequest;

class SaveUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->isCreate()) {
            return auth()->can('user-create');
        }

        if ($this->isUpdate()) {
            return auth()->can('user-update');
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
            'name' => 'required|string|min_length:3|max_length:255|secure_value',
            'user_preferred_name' => 'required|string|min_length:3|max_length:255|secure_value',
            'email' => 'required|email|max_length:255|secure_value',
            'user_contact_no' => 'required|numeric|min_length:10|max_length:15',
            'user_gender' => 'required|integer|in:1,2',
            'username' => 'nullable|string|min_length:3|max_length:255|secure_value',
            'password' => 'nullable|string|min_length:8|max_length:255',
            'role_id' => 'required|integer|min:1',
            'user_status' => 'required|integer|min:0|max:4',
            'id' => 'nullable|numeric',
        ];
    }

    public function sanitize(): array
    {
        return [
            'name' => 'trim|strip_tags|normalize_spaces',
            'user_preferred_name' => 'trim|strip_tags|normalize_spaces',
            'email' => 'trim|lowercase',
            'user_contact_no' => 'trim|no_null_bytes',
            'username' => 'trim|strip_tags|normalize_spaces',
        ];
    }

    public function casts(): array
    {
        return [
            'name' => 'trim',
            'user_preferred_name' => 'trim',
            'email' => 'trim|lowercase',
            'user_contact_no' => 'trim|string',
            'user_gender' => 'int',
            'username' => 'nullable_string',
            'password' => 'nullable_string',
            'role_id' => 'int',
            'user_status' => 'int',
            'id' => 'int',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Name is required.',
            'name.min_length' => 'Name must be at least :min characters.',
            'name.max_length' => 'Name may not be greater than :max characters.',
            'user_preferred_name.required' => 'Preferred name is required.',
            'email.required' => 'Email is required.',
            'email.email' => 'Email format is invalid.',
            'user_contact_no.required' => 'Contact number is required.',
            'user_contact_no.numeric' => 'Contact number must be numeric.',
            'user_gender.required' => 'Gender is required.',
            'username.min_length' => 'Username must be at least :min characters.',
            'password.min_length' => 'Password must be at least :min characters.',
            'role_id.required' => 'Role is required.',
            'user_status.required' => 'Status is required.',
        ];
    }

    public function sensitiveFields(): array
    {
        return ['password'];
    }
}
