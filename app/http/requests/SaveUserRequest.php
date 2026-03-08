<?php

namespace App\Http\Requests;

use Core\Http\FormRequest;

class SaveUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|min_length:3|max_length:255|secure_value',
            'user_preferred_name' => 'required|string|min_length:3|max_length:255|secure_value',
            'email' => 'required|email|max_length:255|secure_value',
            'user_contact_no' => 'required|numeric|min_length:10|max_length:15',
            'role_id' => 'required|integer|min:1',
            'user_status' => 'required|integer|min:0|max:4',
            'id' => 'numeric',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Name is required.',
            'name.min_length' => 'Name must be at least 3 characters.',
            'name.max_length' => 'Name may not be greater than 255 characters.',
            'user_preferred_name.required' => 'Preferred name is required.',
            'email.required' => 'Email is required.',
            'email.email' => 'Email format is invalid.',
            'user_contact_no.required' => 'Contact number is required.',
            'user_contact_no.numeric' => 'Contact number must be numeric.',
            'role_id.required' => 'Role is required.',
            'user_status.required' => 'Status is required.',
        ];
    }
}
