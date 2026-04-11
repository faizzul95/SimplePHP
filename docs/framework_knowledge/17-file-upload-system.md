# 17. File Upload System

## Files Component (`Components\Files`)

## Security Component (`Components\Security`)

## Legacy Upload Helper Compatibility (`app/helpers/custom_upload_helper.php`)

The project still exposes legacy helper functions on top of the newer `Files` and `Security` components for controller/service compatibility.

Current helper behavior:

- `folder()` delegates folder normalization to `security()->sanitizeStorageSegment(...)` and configures `files()->setUploadDir(...)`
- `upload()` delegates storage to `files()->upload(...)`
- `moveFile()` delegates storage to `files()->storeFile(...)`
- `convertBase64String()` delegates decode handling to `files()->decodeBase64DataUrl(...)`
- `containsMalicious()` delegates malicious-content inspection to `security()->containsMalicious(...)`

This means helper consumers now benefit from the same centralized detection rules as the framework components instead of maintaining a second independent upload/XSS detector.

Security-sensitive upload checks now live in a shared `Components\Security` class so upload code, helper functions, and request-hardening middleware can reuse the same rules.

Current central responsibilities:

- Relative upload-path normalization and segment sanitizing
- Dangerous upload-extension blocking helpers
- Suspicious content detection for active content, wrapper abuse, and common obfuscation patterns
- Streaming document inspection for CSV, text, JSON, XML, Markdown, and YAML-like uploads
- Host-header normalization and generic length guards used by request hardening

`Components\Files` delegates document-content inspection, blocked-extension checks, and path normalization to this component.

### Defaults

- **Upload directory**: `public/upload` (relative to `ROOT_DIR`)
- **Max file size**: `4` MB (integer, megabytes)
- **Allowed MIME types**: `image/jpeg, image/png, application/pdf`
- **Blocked extensions**: `php`, `phtml`, `phar`, `php3`, `php4`, `php5`, `php7`, `phps`, `cgi`, `pl`, `asp`, `aspx`, `jsp`, `sh`, `bat`, `exe`, `dll`, `htaccess`, `htpasswd`
- **Image safety limits**: width `6000`, height `6000`, max pixels `24,000,000`

### Public API

- `setUploadDir(string $uploadDir, ?int $permission = 0775): void` — Set upload directory. Auto-creates folder if missing.
- `setMaxFileSize(int $maxFileSize): void` — Set max file size **in megabytes** (e.g., `5` = 5 MB).
- `setAllowedMimeTypes(string $allowedMimeTypes): void` — Comma-separated MIME types, or `'*'` to allow all.
- `setImageLimits(int $maxWidth, int $maxHeight, int $maxPixels = 24000000): void` — Guard against oversized or decompression-bomb-like images.
- `upload(array $file, array $options = []): array` — Upload a single `$_FILES` entry.
- `uploadMany(array $files, array $options = []): array` — Upload a bulk `$_FILES` payload or a list of normalized file arrays sequentially.
- `uploadBase64Image(string $base64Image, array $options = []): array` — Upload a base64 `data:` image safely.
- `uploadBase64Images(array $images, array $options = []): array` — Upload many base64 `data:` images sequentially.

Supported content-validation options for `upload()` / `uploadMany()`:

- `validate_content` => `true` enables content inspection for text-like documents.
- `validate_content` is `false` by default. If you do not pass it, the file is saved without content scanning.
- `reject_unsafe_content` => `true` rejects the upload when malicious content is detected.
- `reject_unsafe_content` only matters when `validate_content` is enabled.
- `content_validation` => `['max_issues' => 20, 'line_length' => 8192, 'sanitize_value' => true, 'allow_unsupported' => true]` tunes the streaming scan.

### Content Validation Controls

Current behavior in `Components\Files` is intentionally opt-in for document scanning:

- Images are always validated as images.
- Non-image files are only content-scanned when `validate_content` is explicitly set to `true`.
- If `validate_content` is omitted, the component still performs size checks, MIME detection, blocked MIME checks, blocked extension checks, and safe-name storage, but it does not inspect CSV/text/JSON/XML contents line by line.

Practical combinations:

- Save without scanning: `['validate_content' => false]`
- Save and scan, but do not reject: `['validate_content' => true, 'reject_unsafe_content' => false]`
- Save and scan, reject on hits: `['validate_content' => true, 'reject_unsafe_content' => true]`

### Performance Characteristics

The upload pipeline has two different cost profiles:

- File storage validation is cheap and constant per file: path normalization, MIME detection, blocked extension/MIME checks, and final move/write.
- Document content validation is linear in the amount of text scanned.

For CSV specifically, `Security::scanCsvDocument()` reads the file row by row with `fgetcsv()` and then scans each cell with `containsMalicious()`.

That means the rough work is:

- `rows x columns x malicious-pattern-check`

For a CSV with 10,000,000 rows and 40 columns, that is up to 400,000,000 cell inspections if the file is clean and scanning is enabled.

What this means in practice:

- Memory usage stays much safer than loading the whole CSV into RAM because scanning is streamed row by row.
- CPU time can still be very large because every cell is inspected.
- If the file is clean, the scanner must read the full file.
- If malicious content is found early, scanning stops once `max_issues` is reached.
- Running that scan inside a normal synchronous HTTP upload request is usually not appropriate for very large import files.

### Large CSV Guidance

For very large CSV imports such as 40 columns with 10M records:

- Do not rely on synchronous request-time content scanning.
- Save the file first, then process it asynchronously in a queue or CLI job if deeper inspection is required.
- Keep `validate_content` disabled on the upload endpoint unless the files are relatively small.
- Use strict MIME allowances and authenticated upload routes to reduce risk when scan is disabled.

Important practical constraint:

- The default `Files` limit is only `4` MB.
- A 10M-row CSV will exceed that by a very large margin, so it will be rejected unless you explicitly raise `setMaxFileSize()` and also raise PHP/web-server upload limits.

### Notes About `content_validation`

- `max_issues` limits how many findings are collected before the scan stops.
- `line_length` is passed to the streaming reader and should be sized for the expected row width of the CSV.
- `sanitize_value` controls whether suspicious values are sanitized in the report payload.
- `allow_unsupported` is useful when you want a best-effort pipeline and do not want unsupported text-like formats to fail validation.

When using the legacy helper `extractSafeCSVContent(...)`, the CSV helper also supports:

- `whitelist_patterns` => array of regex patterns treated as known-safe false positives
- `whitelist_contains` => array of case-insensitive substrings treated as known-safe false positives

For very wide CSV rows, increase `line_length` so rows are not artificially constrained by a small read limit.

### Upload Response Structure

```php
[
	'code'     => 200|400,           // 200 on success, 400 on failure
	'message'  => 'The file has been uploaded',
	'files'    => [
		'original_name' => 'photo.jpg',        // htmlspecialchars-escaped
		'name'          => '1718001234ab3c5d6f.jpg', // generated name
		'size'          => 204800,              // bytes (includes generated derivatives)
		'path'          => '/var/www/.../upload/1718001234ab3c5d6f.jpg', // full path on success
		'relative_path' => 'public/upload/avatars/1718001234ab3c5d6f.jpg',
		'folder'        => '/var/www/.../upload/',
		'relative_folder' => 'public/upload/avatars',
		'mime'          => 'image/jpeg',
		'extension'     => 'jpg',
		'compression'   => 1|2|3,
		'width'         => 1280,
		'height'        => 720,
		'content_scan'  => null|[
			'validated' => true,
			'scanned' => true,
			'mime' => 'text/csv',
			'issues' => [],
			'issue_count' => 0,
		],
	],
	'isUpload' => true|false,
]
```

### Bulk Upload Response Structure

```php
[
	'code' => 200|207|400,
	'message' => 'Processed 3 file(s): 2 uploaded, 1 failed.',
	'files' => [],
	'items' => [
		// Each item is the same single-upload response structure
	],
	'success_count' => 2,
	'failure_count' => 1,
	'isUpload' => true,
]
```

### File Name Generation

Files are renamed automatically using cryptographically secure randomness. Original filenames are never used directly on disk.

### Validation Order

1. Check `UPLOAD_ERR_INI_SIZE` (PHP upload limit exceeded).
2. Check file size against `maxFileSize` (in megabytes).
3. Detect MIME type from file contents using `finfo` / `mime_content_type`.
4. Map detected MIME to a safe server-side extension.
5. Reject blocked executable extensions.
6. For images, validate dimensions and max pixels with `getimagesize()`.
7. For image uploads, re-encode via GD before persistence to strip metadata/polyglot payloads.
8. Generate optional `_compress` and `_thumbnail` derivatives when compression is enabled.
9. Only when `validate_content` is enabled for non-image files, stream-scan supported document types for malicious content.

### Security Model

- Upload directories are normalized as project-relative paths only; absolute paths, drive prefixes, null bytes, and `..` traversal segments are rejected.
- File type trust is based on file contents, not user-provided file extension alone.
- Base64 uploads only accept strict `data:` URLs and currently allow JPEG/PNG only.
- Base64 payload size is checked before decode, then decoded to disk in chunks to avoid large duplicate in-memory buffers.
- Images are re-encoded through GD before storage, which strips embedded active content and reduces polyglot/RCE risk.
- Oversized images are rejected by width, height, and total pixel count to reduce decompression-bomb risk.
- Stored files are written with generated names and `0644` permissions.
- Bulk uploads are processed sequentially so each file is validated, transformed, and released before the next file is handled.
- Text, CSV, JSON, and XML documents can be scanned line by line before storage so suspicious active content can be rejected without loading the full file into memory.
- Streaming content validation also supports NDJSON, JSON-LD, Markdown, and YAML-style text payloads when the detected MIME matches one of the supported scan types.
- Document scanning is not automatic; it only runs when `validate_content` is enabled by the caller.

This reduces common upload attack classes such as path traversal, MIME spoofing, double-extension abuse, executable upload, image polyglots, and oversized image memory exhaustion. It does not replace patching PHP/GD/ImageMagick or keeping the server runtime updated.

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

$result = $files->upload($_FILES['avatar'], [
	'compress' => true,
	'file_compression' => 2,
]);

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

### 2a) Secure base64 cropper upload with compression

```php
$files = new \Components\Files();
$files->setUploadDir('public/upload/users/42/avatar');
$files->setMaxFileSize(8);
$files->setAllowedMimeTypes('image/jpeg,image/png');
$files->setImageLimits(5000, 5000, 16000000);

$result = $files->uploadBase64Image($request->validated('image'), [
	'original_name' => 'user-42-avatar.png',
	'compress' => true,
	'file_compression' => 3,
]);

if ($result['isUpload']) {
	$relativePath = $result['files']['relative_path'];
	$compressionMode = $result['files']['compression'];
}
```

Compression modes:

- `1` — original file only
- `2` — original + `_compress`
- `3` — original + `_compress` + `_thumbnail`

### 2b) Bulk upload from a multi-file input

```php
$files = new \Components\Files();
$files->setUploadDir('public/upload/documents');
$files->setMaxFileSize(5);
$files->setAllowedMimeTypes('application/pdf,image/jpeg,image/png');

$result = $files->uploadMany($_FILES['documents'], [
	'compress' => false,
]);

if ($result['success_count'] > 0) {
	foreach ($result['items'] as $item) {
		if ($item['isUpload']) {
			$savedPath = $item['files']['path'];
		}
	}
}
```

### 2c) Bulk base64 image upload

```php
$files = new \Components\Files();
$files->setUploadDir('public/upload/gallery');
$files->setMaxFileSize(8);
$files->setAllowedMimeTypes('image/jpeg,image/png');
$files->setImageLimits(5000, 5000, 16000000);

$result = $files->uploadBase64Images($payload['images'], [
	'compress' => true,
	'file_compression' => 2,
]);
```

### 2d) CSV upload with streaming content validation

```php
$files = new \Components\Files();
$files->setUploadDir('public/upload/imports');
$files->setMaxFileSize(4);
$files->setAllowedMimeTypes('text/csv,text/plain,application/json');

$result = $files->upload($_FILES['document'], [
	'validate_content' => true,
	'reject_unsafe_content' => true,
	'content_validation' => [
		'max_issues' => 10,
		'line_length' => 8192,
	],
]);

if ($result['isUpload']) {
	$scanSummary = $result['files']['content_scan'];
}
```

### 2e) CSV upload without content scanning

```php
$files = new \Components\Files();
$files->setUploadDir('public/upload/imports');
$files->setMaxFileSize(128);
$files->setAllowedMimeTypes('text/csv,text/plain');

$result = $files->upload($_FILES['csv_file'], [
	'validate_content' => false,
]);
```

This still enforces:

- file size limit
- MIME detection
- blocked MIME rejection
- blocked extension rejection
- safe generated filename

It does not inspect each CSV row or cell.

### 2f) CSV upload with scan enabled but no hard reject

```php
$files = new \Components\Files();
$files->setUploadDir('public/upload/imports');
$files->setMaxFileSize(32);
$files->setAllowedMimeTypes('text/csv,text/plain');

$result = $files->upload($_FILES['csv_file'], [
	'validate_content' => true,
	'reject_unsafe_content' => false,
	'content_validation' => [
		'max_issues' => 5,
		'line_length' => 32768,
	],
]);

if ($result['isUpload']) {
	$issues = $result['files']['content_scan']['issues'] ?? [];
	$issueCount = $result['files']['content_scan']['issue_count'] ?? 0;
}
```

Use this mode when you want to save first, log findings, and let a later import step decide what to do.

### 2g) Large CSV upload pattern: save now, inspect later

```php
$files = new \Components\Files();
$files->setUploadDir('public/upload/imports');
$files->setMaxFileSize(512);
$files->setAllowedMimeTypes('text/csv,text/plain');

$upload = $files->upload($_FILES['csv_file'], [
	'validate_content' => false,
]);

if ($upload['isUpload']) {
	$relativePath = $upload['files']['relative_path'];

	// Queue a background import or inspection job here.
	// Example idea:
	// queue()->push(new ProcessCsvImportJob($relativePath));
}
```

This is the preferred pattern for very large CSV files because it keeps the HTTP request focused on safe storage instead of row-by-row inspection.

### 2h) Legacy helper CSV scan with custom whitelist rules

```php
$result = extractSafeCSVContent($_FILES['csv_file'], false, [
	'include_data' => false,
	'sanitize_value' => true,
	'whitelist_patterns' => [
		'/\bjavascript\b.*?(explained|tutorial|example)/i',
		'/\balert\s*\(.*?\)\s+is\s+an\s+example\b/i',
	],
	'whitelist_contains' => [
		'javascript tutorial',
		'document.write explained',
	],
]);
```

Guidance for helper-level whitelists:

- Use them only for known-safe false positives such as tutorial content, training data, or formula-style strings.
- Keep them narrow and field-specific where possible.
- Do not use a whitelist to permit real executable HTML, script tags, wrapper payloads, or PHP/RCE markers.
- For very large CSV files, prefer `include_data => false` unless you explicitly need the sanitized row output in memory.

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
5. For bulk uploads, iterate over `$result['items']` and persist only entries where `$item['isUpload']` is `true`.
6. Enable `validate_content` for text-like documents when you want line-by-line content inspection before saving.
7. Leave `validate_content` disabled for large import files unless you are intentionally paying the CPU cost of scanning every line or cell.
8. For huge CSV imports, upload first and perform verification in a queue worker, console command, or batch import job.

## What To Avoid

- Avoid passing bytes to `setMaxFileSize()` — it takes **megabytes** (integer).
- Avoid broad MIME allowances when only specific file types are needed.
- Avoid saving uploads into framework directories (`systems/`, `app/`).
- Avoid using original filenames directly — the component generates safe names.
- Avoid trusting base64 MIME declarations without content validation.
- Avoid accepting SVG or script-capable formats on user-controlled image endpoints unless you sanitize them separately.
- Avoid storing raw decoded base64 image bytes directly when you can re-encode them first.
- Avoid enabling document content validation for unsupported binary formats unless you also add a format-specific parser.
- Avoid enabling request-time CSV content scanning for very large imports; streamed scanning helps memory usage, but CPU time still scales with the full dataset.

## Benefits

- Automatic dangerous extension blocking (PHP, shell scripts, executables).
- Content-based MIME validation instead of trusting extensions alone.
- Safe base64 image upload support with strict MIME checks.
- Re-encoded image storage strips metadata and reduces polyglot upload risk.
- Directory normalization prevents traversal and unsafe absolute paths.
- Centralized upload constraints and image safety limits.
- Clear response structure with upload status and file details.
- Sequential bulk processing avoids reading or decoding every file into memory at once.
- Streaming document inspection allows CSV/text validation without building the whole file in RAM.
- Legacy upload helpers now inherit centralized `Security` detection rules instead of maintaining a second divergent malicious-content implementation.

## Evidence

- `systems/Components/Files.php`
- `app/helpers/custom_upload_helper.php`
- `app/routes/api.php` (upload routes)
- `app/http/controllers/UploadController.php`
