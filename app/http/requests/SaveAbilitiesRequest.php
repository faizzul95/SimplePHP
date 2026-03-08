<?php

namespace App\Http\Requests;

use Core\Http\FormRequest;

class SaveAbilitiesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'abilities_name' => 'required|string|min_length:5|max_length:50|secure_value',
            'abilities_slug' => 'required|string|min_length:5|max_length:100|secure_value',
            'abilities_desc' => 'string|max_length:255|secure_value',
            'id' => 'numeric',
        ];
    }

    public function messages(): array
    {
        return [
            'abilities_name.required' => 'Abilities name is required.',
            'abilities_name.min_length' => 'Abilities name must be at least 5 characters.',
            'abilities_name.max_length' => 'Abilities name may not be greater than 50 characters.',
            'abilities_slug.required' => 'Abilities slug is required.',
            'abilities_slug.min_length' => 'Abilities slug must be at least 5 characters.',
            'abilities_slug.max_length' => 'Abilities slug may not be greater than 100 characters.',
            'abilities_desc.max_length' => 'Description may not be greater than 255 characters.',
        ];
    }
}
