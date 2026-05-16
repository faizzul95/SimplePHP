<?php

namespace App\Support;

final class SecurityAuditRunner
{
    /** @var callable|null */
    private $requestClient;

    public function __construct(?callable $requestClient = null)
    {
        $this->requestClient = $requestClient;
    }

    public function run(string $environment, array $security, array $api, ?string $url = null, int $timeout = 5, array $probe = [], array $requestedChecks = []): array
    {
        $checks = [];

        if ($this->matchesRequestedCheck($requestedChecks, 'config')) {
            $this->auditConfig($checks, strtolower(trim($environment)), $security, $api);
        }

        if ($this->matchesRequestedCheck($requestedChecks, 'query-allowlist')) {
            $this->auditQueryAllowlist($checks, $security);
        }

        if ($this->matchesRequestedCheck($requestedChecks, 'target') && $url !== null && trim($url) !== '') {
            $requestOptions = [];
            $authMode = strtolower(trim((string) ($probe['auth_mode'] ?? '')));

            if ($authMode === 'session') {
                $requestOptions = $this->authenticateSession($checks, trim($url), $timeout, $security, $probe);
            }

            if (empty($requestOptions['skip_target'])) {
                $this->auditUrl($checks, trim($url), $timeout, $requestOptions);
            }
        }

        return [
            'summary' => $this->summarize($checks),
            'checks' => $checks,
        ];
    }

    private function auditQueryAllowlist(array &$checks, array $security): void
    {
        $audit = new \App\Support\QueryAllowlistAudit();
        foreach ($audit->run($security) as $check) {
            $checks[] = $check;
        }
    }

    /**
     * @param array<int, string> $requestedChecks
     */
    private function matchesRequestedCheck(array $requestedChecks, string $candidate): bool
    {
        if ($requestedChecks === []) {
            return true;
        }

        $normalizedCandidate = str_replace('_', '-', strtolower(trim($candidate)));
        foreach ($requestedChecks as $requestedCheck) {
            $normalizedRequested = str_replace('_', '-', strtolower(trim((string) $requestedCheck)));
            if ($normalizedRequested === $normalizedCandidate) {
                return true;
            }
        }

        return false;
    }

    private function auditConfig(array &$checks, string $environment, array $security, array $api): void
    {
        $csrf = (array) ($security['csrf'] ?? []);
        $requestHardening = (array) ($security['request_hardening'] ?? []);
        $trusted = (array) ($security['trusted'] ?? []);
        $csp = (array) ($security['csp'] ?? []);
        $headers = (array) ($security['headers'] ?? []);
        $permissionsPolicy = (array) ($security['permissions_policy'] ?? []);
        $cors = (array) ($api['cors'] ?? []);
        $apiAuth = (array) ($api['auth'] ?? []);
        $rateLimit = (array) ($api['rate_limit'] ?? []);

        $this->addCheck(
            $checks,
            'csrf.enabled',
            !empty($csrf['csrf_protection']) ? 'pass' : 'fail',
            'high',
            !empty($csrf['csrf_protection'])
                ? 'CSRF protection is enabled.'
                : 'CSRF protection is disabled for web requests.'
        );

        $secureCookie = !empty($csrf['csrf_secure_cookie']);
        $this->addCheck(
            $checks,
            'csrf.secure_cookie',
            $secureCookie ? 'pass' : ($environment === 'development' ? 'warn' : 'fail'),
            $environment === 'development' ? 'medium' : 'high',
            $secureCookie
                ? 'CSRF cookie is restricted to HTTPS.'
                : 'CSRF cookie is not marked Secure.'
        );

        $this->addCheck(
            $checks,
            'csrf.origin_check',
            !empty($csrf['csrf_origin_check']) ? 'pass' : 'warn',
            'medium',
            !empty($csrf['csrf_origin_check'])
                ? 'Origin and Referer enforcement is enabled for state-changing requests.'
                : 'Origin and Referer enforcement is disabled.'
        );

        $this->addCheck(
            $checks,
            'request_hardening.enabled',
            !empty($requestHardening['enabled']) ? 'pass' : 'fail',
            'high',
            !empty($requestHardening['enabled'])
                ? 'Request hardening limits are enabled.'
                : 'Request hardening limits are disabled.'
        );

        $trustedHosts = array_values(array_filter(array_map('strval', (array) ($trusted['hosts'] ?? [])), static fn(string $host): bool => trim($host) !== ''));
        $trustedHostStatus = empty($trustedHosts) && $environment !== 'development' ? 'warn' : 'pass';
        $this->addCheck(
            $checks,
            'trusted.hosts',
            $trustedHostStatus,
            'medium',
            empty($trustedHosts)
                ? 'Trusted host allow-list is empty.'
                : 'Trusted host allow-list contains ' . count($trustedHosts) . ' entr' . (count($trustedHosts) === 1 ? 'y.' : 'ies.')
        );

        $cspEnabled = !empty($csp['enabled']);
        $this->addCheck(
            $checks,
            'csp.enabled',
            $cspEnabled ? 'pass' : 'fail',
            'high',
            $cspEnabled
                ? 'Content-Security-Policy is enabled.'
                : 'Content-Security-Policy is disabled.'
        );

        $scriptSources = array_map('strval', (array) ($csp['script-src'] ?? []));
        $unsafeInline = in_array("'unsafe-inline'", $scriptSources, true);
        $this->addCheck(
            $checks,
            'csp.script_unsafe_inline',
            $unsafeInline ? 'warn' : 'pass',
            'medium',
            $unsafeInline
                ? "CSP script-src allows 'unsafe-inline', which weakens XSS protection."
                : 'CSP script-src avoids unsafe inline execution.'
        );

        $hsts = (array) ($headers['hsts'] ?? []);
        $hstsEnabled = !empty($hsts['enabled']);
        $this->addCheck(
            $checks,
            'headers.hsts',
            $hstsEnabled ? 'pass' : ($environment === 'development' ? 'warn' : 'fail'),
            $environment === 'development' ? 'low' : 'high',
            $hstsEnabled
                ? 'Strict-Transport-Security is enabled.'
                : 'Strict-Transport-Security is disabled.'
        );

        $this->addCheck(
            $checks,
            'headers.x_content_type_options',
            (($headers['x_content_type_options'] ?? null) === 'nosniff') ? 'pass' : 'warn',
            'medium',
            (($headers['x_content_type_options'] ?? null) === 'nosniff')
                ? 'X-Content-Type-Options is set to nosniff.'
                : 'X-Content-Type-Options should be set to nosniff.'
        );

        $this->addCheck(
            $checks,
            'permissions_policy.configured',
            !empty($permissionsPolicy) ? 'pass' : 'warn',
            'low',
            !empty($permissionsPolicy)
                ? 'Permissions-Policy directives are configured.'
                : 'Permissions-Policy directives are not configured.'
        );

        $allowedOrigins = array_map('strval', (array) ($cors['allow_origin'] ?? []));
        $wildcardOrigin = in_array('*', $allowedOrigins, true);
        $allowCredentials = !empty($cors['allow_credentials']);
        $corsStatus = 'pass';
        if ($wildcardOrigin && $allowCredentials) {
            $corsStatus = 'fail';
        } elseif ($wildcardOrigin) {
            $corsStatus = 'warn';
        }

        $this->addCheck(
            $checks,
            'api.cors.origins',
            $corsStatus,
            $corsStatus === 'fail' ? 'high' : 'medium',
            $wildcardOrigin && $allowCredentials
                ? 'CORS allows wildcard origins together with credentials.'
                : ($wildcardOrigin
                    ? 'CORS allows wildcard origins.'
                    : 'CORS allow_origin is restricted to explicit origins.')
        );

        $authMethods = array_values(array_filter(array_map('strval', (array) ($apiAuth['methods'] ?? [])), static fn(string $value): bool => trim($value) !== ''));
        $authStatus = !empty($apiAuth['required']) && !empty($authMethods) ? 'pass' : 'warn';
        $this->addCheck(
            $checks,
            'api.auth.required',
            $authStatus,
            'medium',
            $authStatus === 'pass'
                ? 'API authentication is required and at least one method is configured.'
                : 'API authentication is not enforced consistently.'
        );

        $this->addCheck(
            $checks,
            'api.rate_limit.enabled',
            !empty($rateLimit['enabled']) ? 'pass' : 'warn',
            'medium',
            !empty($rateLimit['enabled'])
                ? 'API rate limiting is enabled.'
                : 'API rate limiting is disabled.'
        );
    }

    private function auditUrl(array &$checks, string $url, int $timeout, array $requestOptions = []): void
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            $this->addCheck($checks, 'target.url', 'fail', 'high', 'Target URL is not a valid absolute URL.');
            return;
        }

        $response = $this->sendRequest('GET', $url, $timeout, $this->buildRequestHeaders($requestOptions));
        if ($response['error'] !== null) {
            $this->addCheck($checks, 'target.reachable', 'fail', 'high', $response['error']);
            return;
        }

        $headers = $response['headers'];
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        $this->addCheck($checks, 'target.reachable', 'pass', 'low', 'Target responded with HTTP status ' . $response['status'] . '.');

        if (($requestOptions['auth_mode'] ?? null) === 'session') {
            $locations = array_map('strtolower', $headers['location'] ?? []);
            $redirectedToLogin = false;
            foreach ($locations as $location) {
                if (str_contains($location, '/login')) {
                    $redirectedToLogin = true;
                    break;
                }
            }

            $authenticated = !$redirectedToLogin && !in_array((int) $response['status'], [401, 403], true);
            $this->addCheck(
                $checks,
                'auth.session.target_access',
                $authenticated ? 'pass' : 'fail',
                'high',
                $authenticated
                    ? 'Authenticated session reached the target without redirecting to the login screen.'
                    : 'Authenticated session could not access the target page.'
            );
        }

        $this->addHeaderPresenceCheck($checks, $headers, 'content-security-policy', 'header.csp', 'high');
        $this->addHeaderPresenceCheck($checks, $headers, 'x-content-type-options', 'header.x_content_type_options', 'medium');
        $this->addHeaderPresenceCheck($checks, $headers, 'referrer-policy', 'header.referrer_policy', 'medium');
        $this->addHeaderPresenceCheck($checks, $headers, 'permissions-policy', 'header.permissions_policy', 'low');

        $frameProtected = isset($headers['x-frame-options']) || isset($headers['content-security-policy']);
        $this->addCheck(
            $checks,
            'header.clickjacking_protection',
            $frameProtected ? 'pass' : 'warn',
            'medium',
            $frameProtected
                ? 'Response includes a clickjacking protection header.'
                : 'Response is missing X-Frame-Options and CSP frame protections.'
        );

        if ($scheme === 'https') {
            $this->addHeaderPresenceCheck($checks, $headers, 'strict-transport-security', 'header.hsts', 'high');
        }

        if (isset($headers['server'])) {
            $serverValue = strtolower(implode(' ', $headers['server']));
            $disclosesVersion = preg_match('/\/[0-9]/', $serverValue) === 1;
            $this->addCheck(
                $checks,
                'header.server_banner',
                $disclosesVersion ? 'warn' : 'pass',
                'low',
                $disclosesVersion
                    ? 'Server header appears to disclose software versions.'
                    : 'Server header does not expose obvious software versions.'
            );
        }

        $cookies = $headers['set-cookie'] ?? [];
        if ($cookies === []) {
            $this->addCheck($checks, 'cookie.flags', 'pass', 'low', 'No cookies were set by the probed response.');
            return;
        }

        foreach ($cookies as $index => $cookieHeader) {
            $cookieNumber = $index + 1;
            $hasHttpOnly = stripos($cookieHeader, 'httponly') !== false;
            $hasSecure = stripos($cookieHeader, 'secure') !== false;
            $hasSameSite = stripos($cookieHeader, 'samesite=') !== false;

            $this->addCheck(
                $checks,
                'cookie.' . $cookieNumber . '.httponly',
                $hasHttpOnly ? 'pass' : 'warn',
                'medium',
                $hasHttpOnly
                    ? 'Cookie #' . $cookieNumber . ' is marked HttpOnly.'
                    : 'Cookie #' . $cookieNumber . ' is missing the HttpOnly flag.'
            );

            $secureStatus = $scheme === 'https' && !$hasSecure ? 'warn' : 'pass';
            $this->addCheck(
                $checks,
                'cookie.' . $cookieNumber . '.secure',
                $secureStatus,
                'medium',
                $scheme === 'https'
                    ? ($hasSecure
                        ? 'Cookie #' . $cookieNumber . ' is marked Secure.'
                        : 'Cookie #' . $cookieNumber . ' is missing the Secure flag.')
                    : 'Secure cookie validation skipped for non-HTTPS target.'
            );

            $this->addCheck(
                $checks,
                'cookie.' . $cookieNumber . '.samesite',
                $hasSameSite ? 'pass' : 'warn',
                'medium',
                $hasSameSite
                    ? 'Cookie #' . $cookieNumber . ' sets SameSite.'
                    : 'Cookie #' . $cookieNumber . ' is missing a SameSite attribute.'
            );
        }
    }

    private function authenticateSession(array &$checks, string $targetUrl, int $timeout, array $security, array $probe): array
    {
        $username = trim((string) ($probe['username'] ?? ''));
        $password = (string) ($probe['password'] ?? '');

        if ($username === '' || $password === '') {
            $this->addCheck($checks, 'auth.session.credentials', 'fail', 'high', 'Session audit requires both username and password.');
            return ['skip_target' => true, 'auth_mode' => 'session'];
        }

        $baseUrl = $this->baseUrl($targetUrl);
        if ($baseUrl === null) {
            $this->addCheck($checks, 'auth.session.base_url', 'fail', 'high', 'Unable to derive the login base URL from the target.');
            return ['skip_target' => true, 'auth_mode' => 'session'];
        }

        $loginUrl = trim((string) ($probe['login_url'] ?? ''));
        if ($loginUrl === '') {
            $loginUrl = $baseUrl . '/login';
        }

        $loginSubmitUrl = trim((string) ($probe['login_submit_url'] ?? ''));
        if ($loginSubmitUrl === '') {
            $loginSubmitUrl = $baseUrl . '/auth/login';
        }

        $loginPage = $this->sendRequest('GET', $loginUrl, $timeout, [
            'Accept' => 'text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8',
        ]);

        if ($loginPage['error'] !== null || (int) $loginPage['status'] >= 400) {
            $this->addCheck(
                $checks,
                'auth.session.login_page',
                'fail',
                'high',
                $loginPage['error'] ?? ('Login page returned HTTP status ' . $loginPage['status'] . '.')
            );
            return ['skip_target' => true, 'auth_mode' => 'session'];
        }

        $this->addCheck($checks, 'auth.session.login_page', 'pass', 'low', 'Login page responded with HTTP status ' . $loginPage['status'] . '.');

        $cookies = $this->extractCookieJar($loginPage['headers']);
        $csrfRequired = !empty(((array) ($security['csrf'] ?? []))['csrf_protection']);
        $csrfFieldName = trim((string) (((array) ($security['csrf'] ?? []))['csrf_token_name'] ?? 'csrf_token'));
        $csrfToken = $this->extractCsrfToken((string) ($loginPage['body'] ?? ''), $csrfFieldName);

        if ($csrfRequired && $csrfToken === '') {
            $this->addCheck($checks, 'auth.session.csrf_token', 'fail', 'high', 'Unable to extract a CSRF token from the login page.');
            return ['skip_target' => true, 'auth_mode' => 'session'];
        }

        if ($csrfRequired) {
            $this->addCheck($checks, 'auth.session.csrf_token', 'pass', 'low', 'CSRF token extracted successfully from the login page.');
        }

        $payload = [
            'username' => $username,
            'password' => $password,
            'auth_mode' => 'session',
        ];

        if ($csrfToken !== '') {
            $payload[$csrfFieldName] = $csrfToken;
        }

        $loginHeaders = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded',
            'X-Requested-With' => 'XMLHttpRequest',
        ];

        $cookieHeader = $this->buildCookieHeader($cookies);
        if ($cookieHeader !== '') {
            $loginHeaders['Cookie'] = $cookieHeader;
        }

        $loginResponse = $this->sendRequest(
            'POST',
            $loginSubmitUrl,
            $timeout,
            $loginHeaders,
            http_build_query($payload)
        );

        $cookies = array_merge($cookies, $this->extractCookieJar($loginResponse['headers']));

        if ($loginResponse['error'] !== null) {
            $this->addCheck($checks, 'auth.session.login_success', 'fail', 'high', $loginResponse['error']);
            return ['skip_target' => true, 'auth_mode' => 'session'];
        }

        $decodedBody = $this->decodeJsonBody((string) ($loginResponse['body'] ?? ''));
        $responseCode = is_array($decodedBody) ? (int) ($decodedBody['code'] ?? $loginResponse['status']) : (int) $loginResponse['status'];
        $responseMessage = is_array($decodedBody) ? (string) ($decodedBody['message'] ?? '') : '';
        $successfulLogin = $responseCode >= 200 && $responseCode < 300;

        $this->addCheck(
            $checks,
            'auth.session.login_success',
            $successfulLogin ? 'pass' : 'fail',
            'high',
            $successfulLogin
                ? 'Session login probe succeeded.'
                : ('Session login probe failed' . ($responseMessage !== '' ? ': ' . $responseMessage : '.'))
        );

        if (!$successfulLogin) {
            return ['skip_target' => true, 'auth_mode' => 'session'];
        }

        return [
            'auth_mode' => 'session',
            'cookies' => $cookies,
            'skip_target' => false,
        ];
    }

    private function sendRequest(string $method, string $url, int $timeout, array $headers = [], ?string $body = null): array
    {
        if ($this->requestClient !== null) {
            return $this->invokeRequestClient($method, $url, $timeout, $headers, $body);
        }

        $headerLines = [
            'User-Agent: ResiEmasSecurityAudit/1.0',
        ];

        foreach ($headers as $name => $value) {
            $headerName = trim((string) $name);
            if ($headerName === '') {
                continue;
            }

            $headerLines[] = $headerName . ': ' . trim((string) $value);
        }

        $context = stream_context_create([
            'http' => array_filter([
                'method' => strtoupper($method),
                'timeout' => max(1, $timeout),
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
                'header' => implode("\r\n", $headerLines) . "\r\n",
                'content' => $body,
            ], static fn($value): bool => $value !== null),
        ]);

        set_error_handler(static function (): bool {
            return true;
        });

        try {
            $responseBody = @file_get_contents($url, false, $context);
            $responseHeaderLines = $http_response_header;
        } finally {
            restore_error_handler();
        }

        if ($responseBody === false && $responseHeaderLines === []) {
            return [
                'status' => 0,
                'headers' => [],
                'body' => '',
                'error' => 'Target URL did not return a response header block.',
            ];
        }

        $normalized = $this->normalizeHeaderLines((array) $responseHeaderLines);

        return [
            'status' => $normalized['status'],
            'headers' => $normalized['headers'],
            'body' => $responseBody === false ? '' : (string) $responseBody,
            'error' => null,
        ];
    }

    private function invokeRequestClient(string $method, string $url, int $timeout, array $headers, ?string $body): array
    {
        $request = [
            'method' => strtoupper($method),
            'url' => $url,
            'timeout' => $timeout,
            'headers' => $headers,
            'body' => $body,
        ];

        $callable = \Closure::fromCallable($this->requestClient);
        $reflection = new \ReflectionFunction($callable);
        $parameterCount = $reflection->getNumberOfParameters();

        if ($parameterCount === 0) {
            $result = call_user_func($this->requestClient);
        } elseif ($parameterCount === 1) {
            $result = call_user_func($this->requestClient, $request);
        } elseif ($parameterCount === 2) {
            $result = call_user_func($this->requestClient, $url, $timeout);
        } else {
            $result = call_user_func($this->requestClient, strtoupper($method), $url, $headers, $body, $timeout);
        }

        return $this->normalizeRequestResult($result);
    }

    private function normalizeRequestResult($rawResponse): array
    {
        $status = 0;
        $headers = [];
        $body = '';

        if (is_array($rawResponse) && isset($rawResponse['status'], $rawResponse['headers'])) {
            $status = (int) $rawResponse['status'];
            $headers = $this->normalizeHeaders((array) $rawResponse['headers']);
            $body = (string) ($rawResponse['body'] ?? '');

            return [
                'status' => $status,
                'headers' => $headers,
                'body' => $body,
                'error' => isset($rawResponse['error']) ? (string) $rawResponse['error'] : null,
            ];
        }

        if (!is_array($rawResponse)) {
            return [
                'status' => 0,
                'headers' => [],
                'body' => '',
                'error' => 'Unexpected header response received during URL audit.',
            ];
        }

        if (isset($rawResponse[0]) && is_string($rawResponse[0])) {
            $normalized = $this->normalizeHeaderLines($rawResponse);

            return [
                'status' => $normalized['status'],
                'headers' => $normalized['headers'],
                'body' => '',
                'error' => null,
            ];
        }

        return [
            'status' => $status,
            'headers' => $this->normalizeHeaders($rawResponse),
            'body' => $body,
            'error' => null,
        ];
    }

    private function normalizeHeaderLines(array $headerLines): array
    {
        $status = 0;
        $headers = [];

        foreach ($headerLines as $line) {
            if (!is_string($line)) {
                continue;
            }

            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/i', $trimmed, $matches) === 1) {
                $status = (int) $matches[1];
                continue;
            }

            if (!str_contains($trimmed, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $trimmed, 2);
            $key = strtolower(trim($name));
            if ($key === '') {
                continue;
            }

            $headers[$key][] = trim($value);
        }

        return [
            'status' => $status,
            'headers' => $headers,
        ];
    }

    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $key = strtolower(trim((string) $name));
            if ($key === '') {
                continue;
            }

            $normalized[$key] = array_map('strval', is_array($value) ? $value : [$value]);
        }

        return $normalized;
    }

    private function buildRequestHeaders(array $requestOptions): array
    {
        $headers = [
            'Accept' => 'text/html,application/json;q=0.9,*/*;q=0.8',
        ];

        $cookieHeader = $this->buildCookieHeader((array) ($requestOptions['cookies'] ?? []));
        if ($cookieHeader !== '') {
            $headers['Cookie'] = $cookieHeader;
        }

        return $headers;
    }

    private function extractCookieJar(array $headers): array
    {
        $jar = [];

        foreach ((array) ($headers['set-cookie'] ?? []) as $cookieHeader) {
            $parts = explode(';', (string) $cookieHeader, 2);
            if (!isset($parts[0]) || !str_contains($parts[0], '=')) {
                continue;
            }

            [$name, $value] = explode('=', $parts[0], 2);
            $name = trim($name);
            if ($name === '') {
                continue;
            }

            $jar[$name] = trim($value);
        }

        return $jar;
    }

    private function buildCookieHeader(array $cookies): string
    {
        $pairs = [];
        foreach ($cookies as $name => $value) {
            $cookieName = trim((string) $name);
            if ($cookieName === '') {
                continue;
            }

            $pairs[] = $cookieName . '=' . trim((string) $value);
        }

        return implode('; ', $pairs);
    }

    private function extractCsrfToken(string $body, string $csrfFieldName): string
    {
        $patterns = [
            '/<meta[^>]+name=["\']csrf-token["\'][^>]+content=["\']([^"\']+)["\']/i',
            '/<meta[^>]+name=["\']secure_token["\'][^>]+content=["\']([^"\']+)["\']/i',
            '/<input[^>]+name=["\']' . preg_quote($csrfFieldName, '/') . '["\'][^>]+value=["\']([^"\']+)["\']/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $body, $matches) === 1) {
                return html_entity_decode((string) ($matches[1] ?? ''), ENT_QUOTES, 'UTF-8');
            }
        }

        return '';
    }

    private function decodeJsonBody(string $body): ?array
    {
        if (trim($body) === '') {
            return null;
        }

        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function baseUrl(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''));

        if ($scheme === '' || $host === '') {
            return null;
        }

        $baseUrl = $scheme . '://' . $host;
        if (isset($parts['port'])) {
            $baseUrl .= ':' . (int) $parts['port'];
        }

        $path = (string) ($parts['path'] ?? '');
        $prefix = '';

        if ($path !== '' && $path !== '/') {
            if (str_ends_with($path, '/')) {
                $prefix = rtrim($path, '/');
            } else {
                $prefix = rtrim(str_replace('\\', '/', dirname($path)), '/');
            }

            if ($prefix === '.' || $prefix === DIRECTORY_SEPARATOR) {
                $prefix = '';
            }
        }

        if ($prefix !== '') {
            $baseUrl .= '/' . ltrim($prefix, '/');
        }

        return $baseUrl;
    }

    private function addHeaderPresenceCheck(array &$checks, array $headers, string $headerName, string $id, string $severity): void
    {
        $present = isset($headers[$headerName]) && $headers[$headerName] !== [];
        $label = ucwords(str_replace(['-', '_'], ' ', $headerName));

        $this->addCheck(
            $checks,
            $id,
            $present ? 'pass' : 'warn',
            $severity,
            $present
                ? $label . ' header is present.'
                : $label . ' header is missing from the response.'
        );
    }

    private function addCheck(array &$checks, string $id, string $status, string $severity, string $message): void
    {
        $checks[] = [
            'id' => $id,
            'status' => $status,
            'severity' => $severity,
            'message' => $message,
        ];
    }

    private function summarize(array $checks): array
    {
        $summary = [
            'pass' => 0,
            'warn' => 0,
            'fail' => 0,
        ];

        foreach ($checks as $check) {
            $status = (string) ($check['status'] ?? 'warn');
            if (isset($summary[$status])) {
                $summary[$status]++;
            }
        }

        return $summary;
    }
}