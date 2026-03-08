# 17. File Upload System

## Files Component (`Components\Files`)

### Defaults

- **Upload directory**: `public/upload` (relative to `ROOT_DIR`)
- **Max file size**: `4` MB (integer, megabytes)
- **Allowed MIME types**: `image/jpeg, image/png, application/pdf`
- **Blocked extensions**: `php`, `phtml`, `phar`, `php3`, `php4`, `php5`, `php7`, `phps`, `cgi`, `pl`, `asp`, `aspx`, `jsp`, `sh`, `bat`, `exe`, `dll`, `htaccess`, `htpasswd`

### Public API

- `setUploadDir(string $uploadDir, ?int $permission = 0775): void` — Set upload directory. Auto-creates folder if missing.
- `setMaxFileSize(int $maxFileSize): void` — Set max file size **in megabytes** (e.g., `5` = 5 MB).
- `setAllowedMimeTypes(string $allowedMimeTypes): void` — Comma-separated MIME types, or `'*'` to allow all.
- `upload(array $file): array` — Upload a single `$_FILES` entry.

### Upload Response Structure

```php
[
	'code'     => 200|400,           // 200 on success, 400 on failure
	'message'  => 'The file has been uploaded',
	'files'    => [
		'original_name' => 'photo.jpg',        // htmlspecialchars-escaped
		'name'          => '1718001234ab3c5d6f.jpg', // generated name
		'size'          => 204800,              // bytes
		'path'          => '/var/www/.../upload/1718001234ab3c5d6f.jpg', // full path on success
		'folder'        => '/var/www/.../upload/',
		'mime'          => 'image/jpeg',
	],
	'isUpload' => true|false,
]
```

### File Name Generation

Files are renamed automatically: `time() . bin2hex(random_bytes(5)) . '.' . extension`. Original filenames are never used directly on disk.

### Validation Order

1. Check `UPLOAD_ERR_INI_SIZE` (PHP upload limit exceeded).
2. Check file size against `maxFileSize` (in megabytes).
3. Check extension against blocked extensions list.
4. Check MIME type against allowed MIME types (unless `'*'`).
5. Attempt `move_uploaded_file()`.

## Framework Upload Routes

- `/api/v1/uploads/image-cropper` — Image upload with cropper support
- `/api/v1/uploads/delete` — Delete uploaded file
- Protected by middleware: `auth`, `xss`

## Examples

### 1) Basic image upload

```php
$files = new \Components\Files();
$files->setUploadDir('public/upload/avatars');
$files->setMaxFileSize(2); // 2 MB
$files->setAllowedMimeTypes('image/jpeg,image/png,image/webp');

$result = $files->upload($_FILES['avatar']);

if ($result['isUpload']) {
	$savedPath = $result['files']['path'];
	$generatedName = $result['files']['name'];
} else {
	$error = $result['message'];
}
```

### 2) Allow all file types (with blocked extension protection)

```php
$files = new \Components\Files();
$files->setUploadDir('public/upload/documents');
$files->setMaxFileSize(10); // 10 MB
$files->setAllowedMimeTypes('*'); // All MIME types, but blocked extensions still enforced

$result = $files->upload($_FILES['document']);
```

### 3) Combined with FormRequest validation

```php
// In FormRequest
public function rules(): array
{
	return [
		'avatar' => 'file|image|max_file_size:2048|mimes:jpg,png,webp',
	];
}

// In controller
$validated = $request->validated();
$files = new \Components\Files();
$files->setUploadDir('public/upload/avatars');
$files->setMaxFileSize(2);
$result = $files->upload($request->file('avatar'));
```

## How To Use

1. Set directory, size limit, and MIME types before calling `upload()`.
2. Combine with FormRequest validation rules (`file`, `image`, `max_file_size`, `mimes`) for double protection.
3. Restrict upload endpoints behind `auth` and `xss` middleware.
4. Access uploaded file info via `$result['files']` array.

## What To Avoid

- Avoid passing bytes to `setMaxFileSize()` — it takes **megabytes** (integer).
- Avoid broad MIME allowances when only specific file types are needed.
- Avoid saving uploads into framework directories (`systems/`, `app/`).
- Avoid using original filenames directly — the component generates safe names.

## Benefits

- Automatic dangerous extension blocking (PHP, shell scripts, executables).
- Unique filename generation prevents overwrites and path traversal.
- Centralized upload constraints.
- Clear response structure with upload status and file details.

## Evidence

- `systems/Components/Files.php` (190 lines)
- `app/routes/api.php` (upload routes)
- `app/http/controllers/UploadController.php`
