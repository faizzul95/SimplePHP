<?php

namespace App\Http\Requests;

use Core\Http\FormRequest;

class UploadImageCropperRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->can('user-upload-profile');
    }

    public function primaryKey(): string|array
    {
        return 'id';
    }

    public function rules(): array
    {
        return [
            'entity_type' => 'required|string|max_length:255|secure_value',
            'entity_file_type' => 'required|string|max_length:255|secure_value',
            'entity_id' => 'required|string',
            'image' => 'required|string',
            'folder_group' => 'nullable|string|max_length:100',
            'folder_type' => 'nullable|string|max_length:100',
            'id' => 'nullable|string',
        ];
    }

    public function sanitize(): array
    {
        return [
            'entity_type' => 'trim|strip_tags|no_null_bytes',
            'entity_file_type' => 'trim|strip_tags|no_null_bytes',
            'entity_id' => 'trim|no_null_bytes',
            'folder_group' => 'trim|no_null_bytes',
            'folder_type' => 'trim|no_null_bytes',
            'id' => 'trim|no_null_bytes',
        ];
    }

    public function defaults(): array
    {
        return [
            'id' => null,
            'folder_group' => 'unknown',
            'folder_type' => 'unknown',
        ];
    }

    public function casts(): array
    {
        return [
            'entity_type' => 'trim',
            'entity_file_type' => 'trim',
            'entity_id' => 'trim',
            'image' => 'string',
            'folder_group' => 'nullable_string',
            'folder_type' => 'nullable_string',
            'id' => 'nullable_string',
        ];
    }

    public function messages(): array
    {
        return [
            'entity_type.required' => 'Entity type is required.',
            'entity_file_type.required' => 'Entity file type is required.',
            'entity_id.required' => 'Entity ID is required.',
            'image.required' => 'Image data is required.',
            'entity_type.max_length' => 'Entity type may not be greater than :max characters.',
            'entity_file_type.max_length' => 'Entity file type may not be greater than :max characters.',
            'folder_group.max_length' => 'Folder group may not be greater than :max characters.',
            'folder_type.max_length' => 'Folder type may not be greater than :max characters.',
        ];
    }
}
