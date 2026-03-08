<?php

namespace App\Http\Requests\Api;

use Core\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|min:3|max:120',
            'email' => 'required|email|max:150',
            'username' => 'required|string|min:3|max:60',
            'password' => 'required|string|min:6|max:100',
            'user_status' => 'nullable|in:0,1,2,3,4',
        ];
    }
    
    public function messages(): array
    {
        return [
            'name.required' => 'Name is required.',
            'name.min' => 'Name must be at least 3 characters.',
            'name.max' => 'Name may not be greater than 120 characters.',

            'email.required' => 'Email is required.',
            'email.email' => 'Email format is invalid.',
            'email.max' => 'Email may not be greater than 150 characters.',

            'username.required' => 'Username is required.',
            'username.min' => 'Username must be at least 3 characters.',
            'username.max' => 'Username may not be greater than 60 characters.',

            'password.required' => 'Password is required.',
            'password.min' => 'Password must be at least 6 characters.',
            'password.max' => 'Password may not be greater than 100 characters.',

            'user_status.in' => 'Status must be one of: 0 (Inactive), 1 (Active), 2 (Suspended), 3 (Deleted), 4 (Unverified).',
        ];
    }
}
