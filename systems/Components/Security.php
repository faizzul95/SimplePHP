<?php

namespace Components;

/**
 * Security Component
 *
 * Centralizes reusable security primitives for request hardening, upload safety,
 * path normalization, and streaming content inspection.
 *
 * The component is intentionally stateless so it can be reused safely by helpers,
 * middleware, validation rules, and file-processing flows.
 */
class Security
{
    /**
     * Replacement text used when sanitize-mode detection redacts dangerous content.
     */
    public const BLOCKED_CONTENT_PLACEHOLDER = '[CONTENT-BLOCKED]';

    /**
     * Legacy XSS signatures retained for broad compatibility checks.
     *
     * @var array<int, string>
     */
    private const LEGACY_XSS_PATTERNS = [
        '#expression\s*\(#i',
        '#-moz-binding\s*:#i',
        '#@import\s+["\']?[^"\']*["\']?#i',
        '#url\s*\(\s*["\']?\s*javascript\s*:#i',
        '#<!--.*?script.*?-->#si',
        '#<!\[CDATA\[.*?script.*?\]\]>#si',
        '#fscommand#i',
        '#allowscriptaccess#i',
        '/[\x00-\x08\x0B\x0C\x0E-\x1F]/',
        '#<\s*script#i',
        '#</\s*script\s*>#i',
        '#alert\s*\(#i',
        '#confirm\s*\(#i',
        '#prompt\s*\(#i',
        '#eval\s*\(#i',
        '#document\s*\.\s*cookie#i',
        '#document\s*\.\s*write#i',
        '#window\s*\.\s*location#i',
        '#document\s*\.\s*location#i',
        '#setTimeout\s*\(#i',
        '#setInterval\s*\(#i',
        '#String\s*\.\s*fromCharCode#i',
    ];

    /**
     * Encoded XSS markers used as a lightweight second-pass heuristic.
     *
     * @var array<int, string>
     */
    private const LEGACY_ENCODED_XSS_NEEDLES = [
        '&lt;script',
        '&lt;/script',
        '%3cscript',
        '%3c/script',
        '&#60;script',
        '&#x3c;script',
        'javascript:',
        'vbscript:',
        'onload=',
        'onerror=',
        'onclick=',
    ];

    /**
     * Maximum allowed length for a sanitized single storage path segment.
     */
    private const MAX_STORAGE_SEGMENT_LENGTH = 120;

    /**
     * Default maximum allowed user-agent length.
     */
    private const MAX_USER_AGENT_LENGTH = 1000;

    /**
     * Maximum recursion depth allowed when inspecting nested arrays.
     */
    private const MAX_INSPECTION_DEPTH = 8;

    /**
     * Cap any single string value fed into the XSS regex scanner. Pathologically
     * large inputs (mebibyte-scale blobs, deeply nested HTML) can trigger
     * catastrophic backtracking in the legacy XSS patterns. 256 KiB is well
     * above any legitimate form field while still bounding the worst case.
     */
    private const MAX_INSPECTION_LENGTH = 262144;

    /**
     * Fast-path indicators that justify running the heavier deep-content checks.
     *
     * @var array<int, string>
     */
    private const QUICK_RISK_NEEDLES = [
        '<',
        '>',
        '<?',
        '<?=',
        '<%',
        'script',
        'javascript:',
        'vbscript:',
        'data:',
        'srcdoc',
        'xlink:href',
        'foreignobject',
        'phar://',
        'php://',
        'data://',
        'zip://',
        'expect://',
        '__halt_compiler',
        'base64',
        'base href',
        'onload',
        'onerror',
        'onclick',
        'iframe',
        'object',
        'embed',
        'svg',
        'eval(',
        'assert(',
        'base64_decode(',
        'gzinflate(',
        'str_rot13(',
        'include(',
        'require(',
        'document.',
        'jndi:',
        '%3c',
        '&#',
        '\\x3c',
        '\\u003c',
    ];

    /**
     * High-risk extensions that should never be accepted for public upload storage.
     *
     * @var array<int, string>
     */
    private array $blockedUploadExtensions = [
        'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'phps',
        'cgi', 'pl', 'py', 'rb', 'asp', 'aspx', 'jsp', 'jspx', 'sh', 'bash',
        'zsh', 'ksh', 'bat', 'exe', 'dll', 'jar', 'war', 'ps1', 'psm1',
        'htaccess', 'htpasswd', 'com', 'cmd', 'scr', 'msi', 'vbs', 'js', 'mjs',
        'wsf', 'wsh', 'hta', 'reg', 'sct', 'scf', 'lnk', 'cfm', 'cfc', 'asa',
        'ashx', 'asmx', 'cer', 'csr', 'shtml', 'shtm', 'twig', 'tpl', 'latte',
        'html', 'htm', 'xhtml', 'css', 'svg', 'svgz', 'swf'
    ];

    /**
     * Browser-active or server-executable MIME types that are blocked for uploads.
     *
     * @var array<int, string>
     */
    private array $blockedUploadMimeTypes = [
        'text/html',
        'application/xhtml+xml',
        'application/javascript',
        'text/javascript',
        'application/x-javascript',
        'application/ecmascript',
        'text/ecmascript',
        'application/x-httpd-php',
        'application/x-php',
        'application/x-php-source',
        'text/x-php',
        'text/x-script.python',
        'text/x-script.perl',
        'application/x-sh',
        'text/x-shellscript',
        'text/css',
        'image/svg+xml',
        'application/x-shockwave-flash',
    ];

    /**
     * MIME types that can be safely streamed and inspected line by line.
     *
     * @var array<int, string>
     */
    private array $scannableDocumentMimeTypes = [
        'text/plain',
        'text/csv',
        'application/json',
        'application/x-ndjson',
        'application/ld+json',
        'application/xml',
        'text/xml',
        'text/markdown',
        'application/x-yaml',
        'text/yaml',
    ];

    /**
     * Normalize a project-relative path and reject traversal or absolute-path input.
     *
     * @param string $path Project-relative path candidate.
     * @return string Normalized project-relative path using forward slashes.
     * @throws \InvalidArgumentException If the path is empty, absolute, or contains invalid segments.
     */
    public function normalizeRelativeProjectPath(string $path): string
    {
        $normalizedPath = trim(str_replace('\\', '/', $path));

        if ($normalizedPath === '') {
            throw new \InvalidArgumentException('Upload directory must not be empty.');
        }

        if (str_contains($normalizedPath, "\0") || preg_match('#^[A-Za-z]:#', $normalizedPath) || str_starts_with($normalizedPath, '/')) {
            throw new \InvalidArgumentException('Upload directory must be a relative path inside the project root.');
        }

        $path = trim($normalizedPath, '/');

        $segments = array_values(array_filter(explode('/', $path), static fn($segment) => $segment !== '' && $segment !== '.'));
        foreach ($segments as $segment) {
            if ($segment === '..' || !preg_match('/^[A-Za-z0-9._-]+$/', $segment)) {
                throw new \InvalidArgumentException('Upload directory contains invalid path segments.');
            }
        }

        return implode('/', $segments);
    }

    /**
     * Sanitize a single storage path segment for legacy helper compatibility.
      *
      * @param string $value Raw folder or file-name segment.
      * @return string Sanitized segment safe for storage paths.
     */
    public function sanitizeStorageSegment(string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '';
        $value = preg_replace('/\s+/', '_', $value) ?? '';
        $value = str_replace(['\'', '/', '"', ',', ';', '<', '>', '@', '|', '\\', ':', '*', '?'], '_', $value);
        $value = trim($value, " ._\t\n\r\0\x0B");
        $value = preg_replace('/_+/', '_', $value) ?? '';

        if ($value === '') {
            return 'item';
        }

        if (strlen($value) > self::MAX_STORAGE_SEGMENT_LENGTH) {
            $value = substr($value, 0, self::MAX_STORAGE_SEGMENT_LENGTH);
        }

        return $value;
    }

    /**
     * Normalize a host header to a lower-case host name without port suffix.
      *
      * @param string $host Raw host-header value.
      * @return string Normalized host or an empty string when the input is invalid.
     */
    public function normalizeHostHeader(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('/[\x00-\x1F\x7F\s]+/', '', $host) ?? '';
        if ($host === '') {
            return '';
        }

        if (preg_match('/^\[([a-f0-9:.]+)\](?::\d+)?$/i', $host, $matches) === 1) {
            return filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? $matches[1] : '';
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }

        if (substr_count($host, ':') === 1 && preg_match('/^(.+):(\d+)$/', $host, $matches) === 1) {
            $host = $matches[1];
        }

        if (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/', $host)) {
            return '';
        }

        return $host;
    }

    /**
     * Sanitize user-agent strings for logging, fingerprinting, and request validation.
      *
      * @param string $userAgent Raw user-agent string.
      * @param int $maxLength Maximum allowed length before truncation.
      * @return string Sanitized user-agent string or 'Unknown' when empty.
     */
    public function sanitizeUserAgent(string $userAgent, int $maxLength = self::MAX_USER_AGENT_LENGTH): string
    {
        $userAgent = trim($userAgent);
        if ($maxLength > 0 && strlen($userAgent) > $maxLength) {
            $userAgent = substr($userAgent, 0, $maxLength);
        }

        $userAgent = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $userAgent) ?? '';
        $userAgent = str_replace(["\n", "\r", "\t"], '', $userAgent);

        return $userAgent !== '' ? $userAgent : 'Unknown';
    }

    /**
     * Check whether a string exceeds a configured maximum length.
      *
      * @param string $value Value to measure.
      * @param int $maxLength Maximum allowed length.
      * @return bool True when the string exceeds the configured limit.
     */
    public function exceedsMaxLength(string $value, int $maxLength): bool
    {
        return $maxLength > 0 && strlen($value) > $maxLength;
    }

    /**
     * Check if a path exists and is readable.
      *
      * @param string $path Filesystem path to test.
      * @return bool True when the path exists and is readable.
     */
    public function canReadPath(string $path): bool
    {
        $path = trim($path);

        return $path !== '' && file_exists($path) && is_readable($path);
    }

    /**
     * Check if a path or its parent directory is writable.
      *
      * @param string $path Filesystem path to test.
      * @return bool True when the path or its nearest existing parent is writable.
     */
    public function canWritePath(string $path): bool
    {
        $path = trim($path);
        if ($path === '') {
            return false;
        }

        if (file_exists($path)) {
            return is_writable($path);
        }

        $parent = dirname($path);

        while ($parent !== '' && $parent !== '.' && !is_dir($parent)) {
            $nextParent = dirname($parent);
            if ($nextParent === $parent) {
                break;
            }

            $parent = $nextParent;
        }

        return $parent !== '' && is_dir($parent) && is_writable($parent);
    }

    /**
     * Fail fast when a target path is not writable.
      *
      * @param string $path Filesystem path to validate.
      * @param string $label Human-readable label used in the exception message.
      * @throws \RuntimeException If the path is not writable.
     */
    public function assertWritablePath(string $path, string $label = 'Path'): void
    {
        if (!$this->canWritePath($path)) {
            throw new \RuntimeException($label . ' is not writable.');
        }
    }

    /**
     * Check whether an upload extension is blocked for public storage.
      *
      * @param string|null $extension File extension without trust assumptions.
      * @return bool True when the extension is blocked.
     */
    public function isBlockedUploadExtension(?string $extension): bool
    {
        if (!is_string($extension) || $extension === '') {
            return false;
        }

        return in_array(strtolower(ltrim($extension, '.')), $this->blockedUploadExtensions, true);
    }

    /**
     * Check whether a MIME type is blocked for public upload storage.
      *
      * @param string|null $mimeType Detected MIME type.
      * @return bool True when the MIME type is blocked.
     */
    public function isBlockedUploadMimeType(?string $mimeType): bool
    {
        if (!is_string($mimeType) || $mimeType === '') {
            return false;
        }

        return in_array(strtolower(trim($mimeType)), $this->blockedUploadMimeTypes, true);
    }

    /**
     * Validate an original upload filename before it is echoed back to clients or logged.
     */
    public function isSafeUploadFilename(?string $filename): bool
    {
        if (!is_string($filename)) {
            return false;
        }

        $filename = trim($filename);
        if ($filename === '' || strlen($filename) > 255) {
            return false;
        }

        if (strpos($filename, "\0") !== false) {
            return false;
        }

        if (preg_match('/(\.\.\/|\.\/|\\\\|\/)/', $filename) === 1) {
            return false;
        }

        if (preg_match('/[<>:"|?*]/', $filename) === 1) {
            return false;
        }

        if ($this->containsXss($filename)) {
            return false;
        }

        $windowsReserved = [
            'CON', 'PRN', 'AUX', 'NUL',
            'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9',
            'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9',
        ];

        $namePart = strtoupper(pathinfo($filename, PATHINFO_FILENAME));
        if (in_array($namePart, $windowsReserved, true)) {
            return false;
        }

        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        if ($extension !== '' && $this->isBlockedUploadExtension($extension)) {
            return false;
        }

        return true;
    }

    /**
     * Check whether input contains SQL injection indicators.
     *
      * @param mixed $input Scalar or nested array input to inspect.
      * @param bool $sanitizeValue Whether to redact the matching content in the returned value.
     * @return array{malicious: bool, type: ?string, value: mixed, matched_pattern?: string}
     */
    public function containsSqlInjection($input, bool $sanitizeValue = true): array
    {
        return $this->inspectPatternSet($input, $this->sqlInjectionPatterns(), 'sql', $sanitizeValue);
    }

    /**
     * Check whether input contains NoSQL operator or JavaScript-style injection indicators.
     *
      * @param mixed $input Scalar or nested array input to inspect.
      * @param bool $sanitizeValue Whether to redact the matching content in the returned value.
     * @return array{malicious: bool, type: ?string, value: mixed, matched_pattern?: string}
     */
    public function containsNoSqlInjection($input, bool $sanitizeValue = true): array
    {
        return $this->inspectPatternSet($input, $this->noSqlInjectionPatterns(), 'nosql', $sanitizeValue);
    }

    /**
     * Check whether input contains common injection indicators.
     *
      * @param mixed $input Scalar or nested array input to inspect.
      * @param bool $sanitizeValue Whether to redact dangerous content in the returned value.
     * @return array{malicious: bool, type: ?string, value: mixed, matched_pattern?: string}
     */
    public function containsInjection($input, bool $sanitizeValue = true): array
    {
        $sqlScan = $this->containsSqlInjection($input, $sanitizeValue);
        if (($sqlScan['malicious'] ?? false) === true) {
            return $sqlScan;
        }

        $noSqlScan = $this->containsNoSqlInjection($input, $sanitizeValue);
        if (($noSqlScan['malicious'] ?? false) === true) {
            return $noSqlScan;
        }

        $contentScan = $this->containsMalicious($input, $sanitizeValue);
        if (($contentScan['malicious'] ?? false) === true) {
            return [
                'malicious' => true,
                'type' => 'content',
                'value' => $contentScan['value'] ?? $input,
            ];
        }

        return [
            'malicious' => false,
            'type' => null,
            'value' => $input,
        ];
    }

    /**
     * Detect XSS-like payloads in strings or nested arrays.
      *
      * @param mixed $input Scalar or nested array input to inspect.
      * @param int $depth Current recursion depth.
      * @return bool True when the input appears to contain XSS-style content.
     */
    public function containsXss($input, int $depth = 0): bool
    {
        if ($depth > self::MAX_INSPECTION_DEPTH) {
            return true;
        }

        if (is_array($input)) {
            foreach ($input as $key => $value) {
                if ($this->containsXss((string) $key, $depth + 1) || $this->containsXss($value, $depth + 1)) {
                    return true;
                }
            }

            return false;
        }

        if (empty($input) || !is_string($input)) {
            return false;
        }

        // Fail closed on pathologically large inputs: treat them as suspicious
        // rather than paying quadratic regex cost on every request.
        if (strlen($input) > self::MAX_INSPECTION_LENGTH) {
            return true;
        }

        $decodedInput = html_entity_decode($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $loweredInput = strtolower($input);
        $contentScan = $this->containsMalicious($decodedInput, false);
        if (($contentScan['malicious'] ?? false) === true) {
            return true;
        }

        foreach (self::LEGACY_XSS_PATTERNS as $pattern) {
            if (preg_match($pattern, $decodedInput) === 1 || preg_match($pattern, $input) === 1) {
                return true;
            }
        }

        foreach (self::LEGACY_ENCODED_XSS_NEEDLES as $needle) {
            if (str_contains($loweredInput, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether content includes suspicious active content patterns.
     *
        * @param mixed $input Scalar input to inspect.
        * @param bool $sanitizeValue Whether to redact matched dangerous content.
     * @return array{malicious: bool, value: string}
     */
    public function containsMalicious($input, bool $sanitizeValue = true, array $options = []): array
    {
        if (empty($input) || !is_string($input)) {
            return ['malicious' => false, 'value' => (string) $input];
        }

        // Pathologically large strings are treated as suspicious without
        // running the heavy regex, to avoid catastrophic backtracking.
        if (strlen($input) > self::MAX_INSPECTION_LENGTH) {
            return [
                'malicious' => true,
                'value' => $sanitizeValue ? self::BLOCKED_CONTENT_PLACEHOLDER : $input,
            ];
        }

        $originalInput = $input;
        $loweredInput = strtolower($input);

        if (!$this->shouldRunDeepContentChecks($loweredInput)) {
            return ['malicious' => false, 'value' => $originalInput];
        }

        $tagsToSanitize = 'a|abbr|address|area|article|aside|audio|b|base|bdi|bdo|blockquote|body|br|button|canvas|caption|cite|code|col|colgroup|data|datalist|dd|del|details|dfn|dialog|div|dl|dt|em|embed|fieldset|figcaption|figure|footer|form|h[1-6]|head|header|hr|html|i|iframe|img|input|ins|kbd|label|legend|li|link|main|map|mark|meta|meter|nav|noscript|object|ol|optgroup|option|output|p|param|picture|pre|progress|q|rb|rp|rt|rtc|ruby|s|samp|script|section|select|small|source|span|strong|style|sub|summary|sup|svg|table|tbody|td|template|textarea|tfoot|th|thead|time|title|tr|track|u|ul|var|video|wbr|marquee';
        $combinedPattern = '#<(/?)(' . $tagsToSanitize . ')(?:[^>]*?)>#i';

        if (preg_match($combinedPattern, $loweredInput) === 1) {
            return [
                'malicious' => true,
                'value' => $sanitizeValue ? (string) preg_replace($combinedPattern, self::BLOCKED_CONTENT_PLACEHOLDER, $originalInput) : $originalInput,
            ];
        }

        $suspiciousSequences = [
            '/(.)\1{15,}/',
            '/[^\s]{250,}/',
        ];

        foreach ($suspiciousSequences as $pattern) {
            if (preg_match($pattern, $loweredInput) === 1) {
                if ($this->matchesCustomWhitelist($originalInput, $loweredInput, $options)) {
                    return ['malicious' => false, 'value' => $originalInput];
                }

                return [
                    'malicious' => true,
                    'value' => $sanitizeValue ? (string) preg_replace($pattern, self::BLOCKED_CONTENT_PLACEHOLDER, $originalInput) : $originalInput,
                ];
            }
        }

        $encodedPatterns = [
            '/&#x0*(?:3c)script/i',
            '/&#(?:x0*3c|0*60);?\s*(?:script|svg|iframe|img|object)/i',
            '/base64[^a-zA-Z0-9\+\/\=]*,\s*[a-zA-Z0-9\+\/\=]{30,}.*(?:script|eval|function)/i',
            '/\+ADw-script/i',
        ];

        foreach ($encodedPatterns as $pattern) {
            if (preg_match($pattern, $loweredInput) === 1) {
                return [
                    'malicious' => true,
                    'value' => $sanitizeValue ? (string) preg_replace($pattern, self::BLOCKED_CONTENT_PLACEHOLDER, $originalInput) : $originalInput,
                ];
            }
        }

        $eventHandlers = [
            'onload', 'onerror', 'onmouseover', 'onclick', 'onmousedown', 'onmouseup',
            'onkeypress', 'onkeydown', 'onkeyup', 'onsubmit', 'onunload', 'onchange',
            'onfocus', 'onblur'
        ];
        $eventHandlerPattern = '/\s+(' . implode('|', $eventHandlers) . ')\s*=/i';

        if (preg_match($eventHandlerPattern, $loweredInput) === 1) {
            return [
                'malicious' => true,
                'value' => $sanitizeValue ? (string) preg_replace($eventHandlerPattern, self::BLOCKED_CONTENT_PLACEHOLDER, $originalInput) : $originalInput,
            ];
        }

        $pattern =
            '/<\s*s\s*c\s*r\s*i\s*p\s*t|' .
            '\\\x3c|\\\u003c|' .
            '\\\x3e|\\\u003e|' .
            '\bfromcharcode\b|' .
            '<script|' .
            '<\?(php)?|' .
            '\bjavascript:\b|' .
            '\bvbscript:\b|' .
            '\blivescript:\b|' .
            '\bmocha:\b|' .
            '\bexpression\s*\(|' .
            '\beval\s*\(|' .
            '\bdebugger\b|' .
            '\bdocument.write\b|' .
            '\bdocument.cookie\b|' .
            '\bon\w+\s*=|' .
            '\bonclick=|' .
            '\bondblclick=|' .
            '\bonmousedown=|' .
            '\bonmousemove=|' .
            '\bonmouseout=|' .
            '\bonmouseover=|' .
            '\bonmouseup=|' .
            '\bonkeydown=|' .
            '\bonkeypress=|' .
            '\bonkeyup=|' .
            '\bonload=|' .
            '\bonunload=|' .
            '\bonerror=|' .
            '\bonsubmit=|' .
            '\bonreset=|' .
            '\bonselect=|' .
            '\bonchange=|' .
            '\bonblur=|' .
            '\bonfocus=|' .
            '<iframe|' .
            '<frame|' .
            '<object|' .
            '<embed|' .
            '<applet|' .
            '<meta|' .
            '<link|' .
            '<style|' .
            '<form|' .
            '<input|' .
            '<textarea|' .
            '<button|' .
            '<base|' .
            '<foreignobject|' .
            '<animate|' .
            '<set|' .
            '<select|' .
            '<option|' .
            '<xml|' .
            '<svg|' .
            '<math|' .
            '<canvas|' .
            '<video|' .
            '<audio|' .
            'data:\s*[^\s]*?base64|' .
            'data:\s*text\/html|' .
            'data:\s*[^\s]*?javascript|' .
            '\bblob:\b|' .
            '\bfile:\b|' .
            'href\s*=\s*[\'"]?\s*(javascript|data|vbscript):|' .
            'src\s*=\s*[\'"]?\s*(javascript|data|vbscript):|' .
            'action\s*=\s*[\'"]?\s*(javascript|data|vbscript):|' .
            'srcdoc\s*=|' .
            'xlink:href\s*=|' .
            '\bformaction\s*=|' .
            '\bposter\s*=|' .
            '\bbackground\s*=|' .
            '\bdynsrc\s*=|' .
            '\blowsrc\s*=|' .
            '\bping\s*=|' .
            '\bbehavior\s*:|' .
            '\b@import\b|' .
            'url\s*\(\s*[\'"]?\s*(javascript|data|vbscript):|' .
            '(?:\\\\u00[0-9A-Fa-f]{2}script)|' .
            '%[0-9A-Fa-f]{2}.*?(?:script|alert|eval|on\w+\s*=)|' .
            '\bcharset\s*=|' .
            '\bfunction\s*\(|' .
            '\bsetInterval\b|' .
            '\bsetTimeout\b|' .
            '\bnew\s+Function\b|' .
            '\bconstructor\s*\(|' .
            '\b__proto__\b|' .
            '\binnerHTML\b|' .
            '\bouterHTML\b|' .
            '\bprototype\[|' .
            '\[\s*"prototype"\s*\]|' .
            '\bwith\s*\(|' .
            '<!\[CDATA\[.*?<.*?>.*?\]\]>|' .
            '<!ENTITY.*?SYSTEM|' .
            '<!DOCTYPE.*?SYSTEM|' .
            '\$\{jndi:(?:ldap|ldaps|rmi|dns|iiop|http|https):|' .
            '<\s*s\s*c\s*r\s*i\s*p\s*t\s*>|' .
            '\bj\s*a\s*v\s*a\s*s\s*c\s*r\s*i\s*p\s*t\s*:|' .
            '<script[^>]*>[^<]*<\/script>|' .
            '<\?php|' .
            '\bjavascript:[^"\']*(?:alert|eval|document\.|window\.)|' .
            '\bvbscript:[^"\']*(?:msgbox|execute)|' .
            '\bdata:[^"\']*base64[^"\']*,[^"\']*<script|' .
            '\beval\s*\([^)]*(?:document|window|alert|fetch|ajax)|' .
            '<iframe[^>]*src=|' .
            '<object[^>]*data=|' .
            'data:\s*[^\s]*?(?:javascript|html).*?<script|' .
            'url\s*\(\s*[\'"]?\s*(?:javascript:|data:[^)]*script)|' .
            '&lt;script&gt;|' .
            '&#60;script&#62;|' .
            '&#x3c;script&#x3e;|' .
            '&lt;img[^&]*onerror=|' .
            '&#x3c;svg[^&]*onload=|' .
            '\\\\74script|' .
            '\\\\u003Csvg[^\\\\]*onload=|' .
            '\\\\x3Csvg[^\\\\]*onload=|' .
            '%3Cscript|' .
            '%3Csvg|' .
            '%3Cimg|' .
            '%3Ciframe|' .
            'javascript%3A|' .
            '%3Cobject|' .
            '%3Cembed|' .
            'onload%3D|' .
            'onerror%3D|' .
            'onclick%3D|' .
            'onmouseover%3D|' .
            '\\\\74script\\\\76|' .
            '\\\\74\/script\\\\76/ix';

        if (preg_match($pattern, $loweredInput) === 1) {
            if (preg_match('/^[a-zA-Z0-9\s\+\-\*\/\(\)\>\<\=\&\|\!\^\.]+$/', $loweredInput)) {
                return ['malicious' => false, 'value' => $originalInput];
            }

            if (preg_match('/^https?:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,}(\/[a-zA-Z0-9\-\._~:\/?#\[\]@!\$&\'\(\)\*\+,;=]*)?$/', $loweredInput)) {
                return ['malicious' => false, 'value' => $originalInput];
            }

            if (preg_match('/^[a-zA-Z0-9\-_]+(\.[a-zA-Z0-9\-_]+)*\/[a-zA-Z0-9\-_\/?&=\.]+$/', $loweredInput) || preg_match('/^[a-zA-Z0-9\-_\/?&=\.]+$/', $loweredInput)) {
                return ['malicious' => false, 'value' => $originalInput];
            }

            if ($this->matchesCustomWhitelist($originalInput, $loweredInput, $options)) {
                return ['malicious' => false, 'value' => $originalInput];
            }

            return [
                'malicious' => true,
                'value' => $sanitizeValue ? (string) preg_replace($pattern, self::BLOCKED_CONTENT_PLACEHOLDER, $originalInput) : $originalInput,
            ];
        }

        $mixedCasePatterns = [
            '/<[^>]*[sS][cC][rR][iI][pP][tT][^>]*>[^<]*<\/[^>]*[sS][cC][rR][iI][pP][tT][^>]*>/',
            '/[hH][rR][eE][fF]\s*=\s*["\'][jJ][aA][vV][aA][sS][cC][rR][iI][pP][tT]:/i',
            '/[oO][nN]\w+\s*=\s*["\'][^"\']*[aA][lL][eE][rR][tT]\s*\(/i',
        ];

        foreach ($mixedCasePatterns as $pattern) {
            if (preg_match($pattern, $originalInput) === 1) {
                if ($this->matchesCustomWhitelist($originalInput, $loweredInput, $options)) {
                    return ['malicious' => false, 'value' => $originalInput];
                }

                return [
                    'malicious' => true,
                    'value' => $sanitizeValue ? (string) preg_replace($pattern, self::BLOCKED_CONTENT_PLACEHOLDER, $originalInput) : $originalInput,
                ];
            }
        }

        $rcePatterns = [
            '/<\?(?:php|=)?/i',
            '/<%(?:=|@)?/i',
            '/__halt_compiler\s*\(/i',
            '/\bphar:\/\//i',
            '/\b(?:php|data|zip|expect|glob|rar|ogg|ssh2|input):\/\//i',
            '/(?:^|[;{])(?:o|c):\d+:"[^"]+"\s*:/i',
            '/\b(?:proc_open|popen|shell_exec|passthru|system|exec|assert|create_function|include|include_once|require|require_once)\s*(?:\(|[\'\"])/i',
            '/\b(?:base64_decode|gzinflate|gzuncompress|str_rot13)\s*\(/i',
            '/preg_replace\s*\([^\)]*\/e[\'\"]/i',
        ];

        foreach ($rcePatterns as $pattern) {
            if (preg_match($pattern, $originalInput) === 1) {
                return [
                    'malicious' => true,
                    'value' => $sanitizeValue ? self::BLOCKED_CONTENT_PLACEHOLDER : $originalInput,
                ];
            }
        }

        $charCount = strlen($loweredInput);
        if ($charCount > 50) {
            $specialChars = preg_match_all('/[^a-zA-Z0-9\s\p{L}]/u', $loweredInput, $matches);
            $specialCharRatio = $specialChars / $charCount;

            if ($specialCharRatio > 0.9) {
                if ($this->matchesCustomWhitelist($originalInput, $loweredInput, $options)) {
                    return ['malicious' => false, 'value' => $originalInput];
                }

                return [
                    'malicious' => true,
                    'value' => $sanitizeValue ? (string) preg_replace('/[^a-zA-Z0-9\s\p{L}]/u', self::BLOCKED_CONTENT_PLACEHOLDER, $originalInput) : $originalInput,
                ];
            }
        }

        $whitelistPatterns = [
            '/\blog(?:\d+)?\s*\(\s*\d+\s*\)\s*=\s*\d+/i',
            '/\b[xyz]\s*[<>=]\s*[xyz](?:\s*&&\s*[xyz]\s*[<>=]\s*[xyz])*\b/i',
            '/\b\([a-z]\s*\+\s*[a-z]\)²\s*=\s*[a-z]²\s*\+\s*\d[a-z]{1,2}\s*\+\s*[a-z]²/i',
            '/sqrt\(\d+\)\s*=\s*\d+/i',
            '/p\(\s*a\s*\|\s*b\s*\)\s*=\s*[\w\(\)]+/i',
        ];

        foreach ($whitelistPatterns as $pattern) {
            if (preg_match($pattern, $loweredInput) === 1) {
                return ['malicious' => false, 'value' => $originalInput];
            }
        }

        $programmingTerms = [
            '/\b(?:javascript|document\.write|alert)\b.*?(?:explained|tutorial|example|described|is a)/i',
            '/\b(?:html|css|php|jquery)\b.*?(?:explained|tutorial|example|described|is a)/i',
        ];

        foreach ($programmingTerms as $pattern) {
            if (preg_match($pattern, $loweredInput) === 1) {
                return ['malicious' => false, 'value' => $originalInput];
            }
        }

        return ['malicious' => false, 'value' => $originalInput];
    }

    /**
     * Match caller-defined whitelist patterns for known-safe false positives.
     *
     * Supported option keys:
     * - whitelist_patterns: array of regex patterns matched against original input
     * - whitelist_contains: array of case-insensitive substrings
     */
    private function matchesCustomWhitelist(string $originalInput, string $loweredInput, array $options): bool
    {
        $patterns = array_values(array_filter((array) ($options['whitelist_patterns'] ?? []), static function ($value): bool {
            return is_string($value) && trim($value) !== '';
        }));

        foreach ($patterns as $pattern) {
            $matched = @preg_match($pattern, $originalInput);
            if ($matched === 1) {
                return true;
            }
        }

        $contains = array_values(array_filter((array) ($options['whitelist_contains'] ?? []), static function ($value): bool {
            return is_string($value) && trim($value) !== '';
        }));

        foreach ($contains as $needle) {
            if (str_contains($loweredInput, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decide whether input is risky enough to justify the heavier regex scan pass.
      *
      * @param string $input Lower-cased input string.
      * @return bool True when the input should go through deep-content detection.
     */
    private function shouldRunDeepContentChecks(string $input): bool
    {
        foreach (self::QUICK_RISK_NEEDLES as $needle) {
            if (str_contains($input, $needle)) {
                return true;
            }
        }

        return preg_match('/(.)\1{15,}|[^\s]{250,}/', $input) === 1;
    }

    /**
     * @param array<int, string> $patterns
      * @param mixed $input Scalar or nested array input to inspect.
      * @param string $type Detection type label.
      * @param bool $sanitizeValue Whether to redact matching content.
      * @param int $depth Current recursion depth.
     * @return array{malicious: bool, type: ?string, value: mixed, matched_pattern?: string}
     */
    private function inspectPatternSet($input, array $patterns, string $type, bool $sanitizeValue = true, int $depth = 0): array
    {
        if ($depth > self::MAX_INSPECTION_DEPTH) {
            return [
                'malicious' => true,
                'type' => $type,
                'value' => $input,
                'matched_pattern' => 'max_depth_exceeded',
            ];
        }

        if (is_array($input)) {
            foreach ($input as $key => $value) {
                $keyScan = $this->inspectPatternSet((string) $key, $patterns, $type, $sanitizeValue, $depth + 1);
                if (($keyScan['malicious'] ?? false) === true) {
                    return $keyScan;
                }

                $valueScan = $this->inspectPatternSet($value, $patterns, $type, $sanitizeValue, $depth + 1);
                if (($valueScan['malicious'] ?? false) === true) {
                    return $valueScan;
                }
            }

            return [
                'malicious' => false,
                'type' => null,
                'value' => $input,
            ];
        }

        if (!is_string($input) || trim($input) === '') {
            return [
                'malicious' => false,
                'type' => null,
                'value' => $input,
            ];
        }

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input) !== 1) {
                continue;
            }

            return [
                'malicious' => true,
                'type' => $type,
                'value' => $sanitizeValue ? (string) preg_replace($pattern, self::BLOCKED_CONTENT_PLACEHOLDER, $input) : $input,
                'matched_pattern' => $pattern,
            ];
        }

        return [
            'malicious' => false,
            'type' => null,
            'value' => $input,
        ];
    }

    /**
     * Build the SQL injection signature set used by inspectPatternSet().
     *
     * @return array<int, string>
     */
    private function sqlInjectionPatterns(): array
    {
        return [
            '/\bunion\s+all?\s+select\b/i',
            '/\bselect\b[\s\S]{0,120}\bfrom\b/i',
            '/\binsert\s+into\b/i',
            '/\bupdate\s+[a-z0-9_`]+\s+set\b/i',
            '/\bdelete\s+from\b/i',
            '/\bdrop\s+(table|database|view|index|function|procedure|trigger)\b/i',
            '/\balter\s+table\b/i',
            '/\btruncate\s+table\b/i',
            '/;\s*(select|insert|update|delete|drop|create|alter|truncate|exec|execute|union)\b/i',
            '/(?:--|#)\s*$/i',
            '/\/\*[\s\S]*?\*\//i',
            '/(?:^|[\s\(\'"`])(?:or|and)\s+[\'"`]?[a-z0-9_]+[\'"`]?
                \s*=\s*
                [\'"`]?[a-z0-9_]+[\'"`]?/ix',
            '/(?:\'|\"|`)?\s*(or|and)\s+(?:\d+|\'[^\']*\'|\"[^\"]*\")\s*=\s*(?:\d+|\'[^\']*\'|\"[^\"]*\")/i',
            '/\b(?:sleep|benchmark|waitfor\s+delay|pg_sleep)\s*\(/i',
            '/\b(?:load_file|into\s+outfile|into\s+dumpfile)\b/i',
            '/\bfrom\s+information_schema\b/i',
            '/\b(?:char|concat|hex)\s*\(/i',
            '/0x[0-9a-f]{6,}/i',
        ];
    }

    /**
     * Build the NoSQL/operator injection signature set used by inspectPatternSet().
     *
     * @return array<int, string>
     */
    private function noSqlInjectionPatterns(): array
    {
        return [
            '/(?:^|[^a-z0-9_])\$(?:where|regex|ne|gt|gte|lt|lte|nin|in|or|and|not|nor|expr|function)\b/i',
            '/\{\s*\$(?:where|regex|ne|gt|gte|lt|lte|nin|in|or|and|not|nor|expr|function)\s*:/i',
            '/\bthis\.[a-z_][a-z0-9_]*\s*(?:==|!=|<=|>=|<|>)\s*/i',
            '/\bdb\.[a-z_][a-z0-9_]*\.[a-z_][a-z0-9_]*\s*\(/i',
            '/\b\$where\b[\s\S]{0,120}\b(?:return|function|sleep|while)\b/i',
            '/\b\$regex\b[\s\S]{0,80}[\*\+\{\[]/i',
        ];
    }

    /**
     * Stream-scan text-like documents line by line or row by row.
     *
      * @param string $path Filesystem path to inspect.
      * @param string $mime Detected MIME type.
     * @param array<string, mixed> $options
     * @return array<string, mixed>
      * @throws \RuntimeException When content validation is enforced for an unsupported type.
     */
    public function inspectDocument(string $path, string $mime, array $options = []): array
    {
        $defaults = [
            'max_issues' => 20,
            'line_length' => 8192,
            'sanitize_value' => true,
            'allow_unsupported' => true,
        ];
        $options = array_merge($defaults, $options);

        if (!in_array($mime, $this->scannableDocumentMimeTypes, true)) {
            if (($options['allow_unsupported'] ?? true) === false) {
                throw new \RuntimeException('Content validation is not supported for this file type.');
            }

            return [
                'validated' => false,
                'scanned' => false,
                'mime' => $mime,
                'issues' => [],
                'issue_count' => 0,
                'reason' => 'Content validation is only available for text, CSV, JSON, and XML documents.',
            ];
        }

        return $mime === 'text/csv'
            ? $this->scanCsvDocument($path, $mime, $options)
            : $this->scanTextDocument($path, $mime, $options);
    }

    /**
     * Stream-scan a CSV document cell by cell for suspicious content.
     *
     * @param string $path CSV file path.
     * @param string $mime MIME type for reporting.
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function scanCsvDocument(string $path, string $mime, array $options): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Failed to read uploaded CSV file for content validation.');
        }

        $issues = [];
        $maxIssues = max(1, (int) ($options['max_issues'] ?? 20));
        $lineLength = max(1024, (int) ($options['line_length'] ?? 8192));
        $rowNumber = 0;

        try {
            while (($row = fgetcsv($handle, $lineLength, ',', '"', '\\')) !== false) {
                $rowNumber++;
                foreach ($row as $columnIndex => $cellValue) {
                    $scan = $this->containsMalicious((string) $cellValue, (bool) ($options['sanitize_value'] ?? true));
                    if (($scan['malicious'] ?? false) !== true) {
                        continue;
                    }

                    $issues[] = [
                        'row' => $rowNumber,
                        'column' => $columnIndex + 1,
                        'value' => $this->truncateForReport((string) $cellValue),
                        'sanitized_value' => $this->truncateForReport((string) ($scan['value'] ?? '')),
                    ];

                    if (count($issues) >= $maxIssues) {
                        break 2;
                    }
                }

                if (function_exists('gc_collect_cycles') && $rowNumber % 500 === 0) {
                    gc_collect_cycles();
                }
            }
        } finally {
            fclose($handle);
        }

        return [
            'validated' => true,
            'scanned' => true,
            'mime' => $mime,
            'issues' => $issues,
            'issue_count' => count($issues),
        ];
    }

    /**
     * Stream-scan a plain-text style document line by line for suspicious content.
     *
     * @param string $path Document file path.
     * @param string $mime MIME type for reporting.
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function scanTextDocument(string $path, string $mime, array $options): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Failed to read uploaded document for content validation.');
        }

        $issues = [];
        $maxIssues = max(1, (int) ($options['max_issues'] ?? 20));
        $lineLength = max(1024, (int) ($options['line_length'] ?? 8192));
        $lineNumber = 0;

        try {
            while (($line = fgets($handle, $lineLength)) !== false) {
                $lineNumber++;
                $scan = $this->containsMalicious($line, (bool) ($options['sanitize_value'] ?? true));
                if (($scan['malicious'] ?? false) !== true) {
                    continue;
                }

                $issues[] = [
                    'line' => $lineNumber,
                    'value' => $this->truncateForReport($line),
                    'sanitized_value' => $this->truncateForReport((string) ($scan['value'] ?? '')),
                ];

                if (count($issues) >= $maxIssues) {
                    break;
                }

                if (function_exists('gc_collect_cycles') && $lineNumber % 500 === 0) {
                    gc_collect_cycles();
                }
            }
        } finally {
            fclose($handle);
        }

        return [
            'validated' => true,
            'scanned' => true,
            'mime' => $mime,
            'issues' => $issues,
            'issue_count' => count($issues),
        ];
    }

    /**
     * Truncate a value for issue reporting while keeping output bounded.
     *
     * @param string $value Original value.
     * @param int $limit Maximum number of characters to keep.
     * @return string Bounded report-safe preview.
     */
    private function truncateForReport(string $value, int $limit = 400): string
    {
        $value = trim($value);
        if (strlen($value) <= $limit) {
            return $value;
        }

        return substr($value, 0, $limit) . '...';
    }
}