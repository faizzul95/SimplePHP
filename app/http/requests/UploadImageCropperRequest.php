<?php

namespace App\Http\Requests;

use Core\Http\FormRequest;

class UploadImageCropperRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'entity_type' => 'required|string|max_length:255|secure_value',
            'entity_file_type' => 'required|string|max_length:255|secure_value',
            'entity_id' => 'required|string',
            'id' => 'string',
        ];
    }

    public function messages(): array
    {
        return [
            'entity_type.required' => 'Entity type is required.',
            'entity_file_type.required' => 'Entity file type is required.',
            'entity_id.required' => 'Entity ID is required.',
        ];
    }
}
