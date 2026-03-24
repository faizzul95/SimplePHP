<?php

namespace Components;

use InvalidArgumentException;
use RuntimeException;

/**
 * CSRF Protection Class
 * 
 * Provides secure Cross-Site Request Forgery protection with token validation,
 * expiration handling, and configurable exclusions.
 * 
 */
class CSRF
{
    /**
     * Configuration array
     */
    private array $config;

    /**
     * Current request URI
     */
    private ?string $currentUri = null;

    /**
     * Token cache to avoid multiple generations
     */
    private ?string $tokenCache = null;

    /**
     * Default configuration values
     */
    private const DEFAULT_CONFIG = [
        'csrf_protection' => true,
        'csrf_token_name' => 'csrf_token',
        'csrf_cookie_name' => 'csrf_cookie',
        'csrf_expire' => 7200,
        'csrf_regenerate' => false,
        'csrf_exclude_uris' => [],      // opt-out: URIs to SKIP CSRF (e.g. 'api/*')
        'csrf_include_uris' => [],      // legacy opt-in (ignored when exclude list is non-empty)
        'csrf_secure_cookie' => true,
        'csrf_httponly' => true,
        'csrf_samesite' => 'Lax',
        'csrf_origin_check' => true,
        'csrf_allow_missing_origin' => true,
        'csrf_trusted_origins' => [],
    ];

    /**
     * Token length in bytes
     */
    private const TOKEN_LENGTH = 32;

    /**
     * Maximum URI length for security
     */
    private const MAX_URI_LENGTH = 2000;

    /**
     * Constructor
     * 
     * @param array $config Configuration array
     * @throws InvalidArgumentException If configuration is invalid
     */
    public function __construct(array $config = [])
    {
        $this->config = $this->validateAndMergeConfig($config);
        $this->currentUri = $this->getCurrentUri();
    }

    /**
     * Validate and merge configuration with defaults
     * 
     * @param array $config User configuration
     * @return array Merged configuration
     * @throws InvalidArgumentException If configuration is invalid
     */
    private function validateAndMergeConfig(array $config): array
    {
        $merged = array_merge(self::DEFAULT_CONFIG, $config);

        // Validate required configuration
        if (!is_bool($merged['csrf_protection'])) {
            throw new InvalidArgumentException('csrf_protection must be a boolean');
        }

        if (!is_string($merged['csrf_token_name']) || empty($merged['csrf_token_name'])) {
            throw new InvalidArgumentException('csrf_token_name must be a non-empty string');
        }

        if (!is_string($merged['csrf_cookie_name']) || empty($merged['csrf_cookie_name'])) {
            throw new InvalidArgumentException('csrf_cookie_name must be a non-empty string');
        }

        if (!is_int($merged['csrf_expire']) || $merged['csrf_expire'] < 1) {
            throw new InvalidArgumentException('csrf_expire must be a positive integer');
        }

        if (!is_bool($merged['csrf_regenerate'])) {
            throw new InvalidArgumentException('csrf_regenerate must be a boolean');
        }

        if (!is_array($merged['csrf_include_uris'])) {
            throw new InvalidArgumentException('csrf_include_uris must be an array');
        }

        // Validate SameSite values
        $validSameSite = ['Strict', 'Lax', 'None'];
        if (!in_array($merged['csrf_samesite'], $validSameSite, true)) {
            throw new InvalidArgumentException('csrf_samesite must be one of: ' . implode(', ', $validSameSite));
        }

        return $merged;
    }

    /**
     * Check CSRF protection for the current request
     * 
     * @return bool True if validation passes, false otherwise
     * @throws RuntimeException If validation fails due to system error
     */
    public function validate(?string $url): bool
    {
        try {
            $this->currentUri = $url;

            // Only validate state-changing requests
            if (!$this->isWriteRequest()) {
                return true;
            }

            // Check if CSRF protection is enabled
            if (!$this->config['csrf_protection']) {
                return true;
            }

            // Opt-out model: skip CSRF for excluded URIs (e.g. API routes using Bearer tokens)
            if ($this->isExcludedUri()) {
                return true;
            }

            // Legacy opt-in model: if include list is set and exclude list is empty,
            // only validate URIs in the include list
            $excludeUris = $this->config['csrf_exclude_uris'] ?? [];
            $includeUris = $this->config['csrf_include_uris'] ?? [];
            if (empty($excludeUris) && !empty($includeUris)) {
                if (!$this->isIncludedUri()) {
                    return true;
                }
            }

            // Perform token validation
            if (!$this->validateOrigin()) {
                return false;
            }

            return $this->validateToken();
        } catch (RuntimeException $e) {
            error_log('CSRF validation error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Initialize CSRF token for new requests
     * 
     * @return string The generated token
     * @throws RuntimeException If token generation fails
     */
    public function init(): string
    {
        if (!$this->config['csrf_protection']) {
            return '';
        }

        // Check if valid token already exists
        if ($this->tokenCache !== null) {
            return $this->tokenCache;
        }

        $existingToken = $this->getTokenFromCookie();
        if ($existingToken && !$this->isTokenExpired()) {
            $this->tokenCache = $existingToken;
            return $existingToken;
        }

        // Generate new token
        $token = $this->generateToken();
        $this->setTokenCookie($token);
        $this->tokenCache = $token;

        return $token;
    }

    /**
     * Get CSRF token for form output
     * 
     * @return string Current CSRF token
     */
    public function getToken(): string
    {
        if (!$this->config['csrf_protection']) {
            return '';
        }

        return $this->tokenCache ?? $this->getTokenFromCookie() ?? '';
    }

    /**
     * Generate HTML input field for forms
     * 
     * @return string HTML input field
     */
    public function field(): string
    {
        if (!$this->config['csrf_protection']) {
            return '';
        }

        $token = $this->getToken();
        if (empty($token)) {
            $token = $this->init();
        }

        $tokenName = htmlspecialchars($this->config['csrf_token_name'], ENT_QUOTES, 'UTF-8');
        $tokenValue = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');

        return sprintf(
            '<input type="hidden" name="%s" value="%s" />',
            $tokenName,
            $tokenValue
        );
    }

    /**
     * Regenerate CSRF token
     * 
     * @return string New token
     * @throws RuntimeException If token generation fails
     */
    public function regenerate(): string
    {
        $newToken = $this->generateToken();
        $this->setTokenCookie($newToken);
        $this->tokenCache = $newToken;

        return $newToken;
    }

    /**
     * Check if current request is a state-changing (write) method
     * 
     * @return bool
     */
    private function isWriteRequest(): bool
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        return in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    /**
     * Check if current URI is excluded from CSRF verification.
     * Supports wildcard patterns (e.g. 'api/*').
     * 
     * @return bool
     */
    private function isExcludedUri(): bool
    {
        $excludeUris = $this->config['csrf_exclude_uris'] ?? [];
        if (empty($excludeUris) || empty($this->currentUri)) {
            return false;
        }

        $uri = ltrim($this->currentUri, '/');

        foreach ($excludeUris as $pattern) {
            $pattern = ltrim($pattern, '/');
            // Convert wildcard pattern to regex
            $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#i';
            if (preg_match($regex, $uri)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if current URI is in inclusion list
     * 
     * @return bool
     */
    private function isIncludedUri(): bool
    {
        if (empty($this->currentUri)) {
            return false;
        }

        $includeUris = $this->config['csrf_include_uris'] ?? [];
        if (empty($includeUris)) {
            return false;
        }

        $uri = ltrim($this->currentUri, '/');

        foreach ($includeUris as $pattern) {
            $pattern = ltrim($pattern, '/');
            // Support wildcard patterns like isExcludedUri
            $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#i';
            if (preg_match($regex, $uri)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate CSRF token
     * 
     * @return bool
     * @throws RuntimeException If validation fails due to system error
     */
    private function validateToken(): bool
    {
        $postToken = $this->getTokenFromPost();
        $cookieToken = $this->getTokenFromCookie();

        // Check if tokens exist
        if (empty($postToken) || empty($cookieToken)) {
            return false;
        }

        // Validate token formats
        if (!$this->isValidTokenFormat($postToken) || !$this->isValidTokenFormat($cookieToken)) {
            return false;
        }

        // Check if tokens match using timing-safe comparison
        if (!hash_equals($cookieToken, $postToken)) {
            return false;
        }

        // Check token expiration
        if ($this->isTokenExpired()) {
            return false;
        }

        // Regenerate token if configured
        if ($this->config['csrf_regenerate']) {
            $this->regenerate();
        }

        return true;
    }

    /**
     * Validate Origin/Referer header for same-site CSRF protection.
     */
    private function validateOrigin(): bool
    {
        if (($this->config['csrf_origin_check'] ?? true) !== true) {
            return true;
        }

        $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
        $referer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
        $allowMissing = ($this->config['csrf_allow_missing_origin'] ?? true) === true;

        if ($origin === '' && $referer === '') {
            return $allowMissing;
        }

        $allowed = [];
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($host !== '') {
            $scheme = $this->isHttps() ? 'https' : 'http';
            $allowed[] = $scheme . '://' . $host;
        }

        $trusted = (array) ($this->config['csrf_trusted_origins'] ?? []);
        foreach ($trusted as $item) {
            $normalized = strtolower(trim((string) $item));
            if ($normalized !== '') {
                $allowed[] = rtrim($normalized, '/');
            }
        }

        $allowed = array_values(array_unique($allowed));
        if (empty($allowed)) {
            return $allowMissing;
        }

        $source = $origin !== '' ? $origin : $referer;
        $source = strtolower(trim($source));

        $sourceParts = parse_url($source);
        if (!is_array($sourceParts) || empty($sourceParts['scheme']) || empty($sourceParts['host'])) {
            return false;
        }

        $candidate = $sourceParts['scheme'] . '://' . $sourceParts['host'];
        if (isset($sourceParts['port'])) {
            $candidate .= ':' . (int) $sourceParts['port'];
        }

        return in_array(rtrim($candidate, '/'), $allowed, true);
    }

    /**
     * Get token from POST data or X-CSRF-TOKEN header
     * 
     * @return string
     */
    private function getTokenFromPost(): string
    {
        // Check POST body first
        $token = $_POST[$this->config['csrf_token_name']] ?? '';

        // Fall back to X-CSRF-TOKEN header (for AJAX requests)
        if (empty($token)) {
            $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        }

        // Fall back to X-XSRF-TOKEN header (for frameworks like Axios)
        if (empty($token)) {
            $token = $_SERVER['HTTP_X_XSRF_TOKEN'] ?? '';
        }

        return (string) $token;
    }

    /**
     * Get token from cookie
     * 
     * @return string
     */
    private function getTokenFromCookie(): string
    {
        return $_COOKIE[$this->config['csrf_cookie_name']] ?? '';
    }

    /**
     * Validate token format
     * 
     * @param string $token Token to validate
     * @return bool
     */
    private function isValidTokenFormat(string $token): bool
    {
        // Check length (hex representation of TOKEN_LENGTH bytes)
        if (strlen($token) !== self::TOKEN_LENGTH * 2) {
            return false;
        }

        // Check if token contains only hexadecimal characters
        return ctype_xdigit($token);
    }

    /**
     * Check if token is expired
     * 
     * @return bool
     */
    private function isTokenExpired(): bool
    {
        // Check session-based timestamp first (server-side, tamper-proof)
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['_csrf_token_time'])) {
            $tokenTime = $_SESSION['_csrf_token_time'];
        } else {
            // Fallback to cookie-based timestamp
            $timestampCookie = $this->config['csrf_cookie_name'] . '_time';
            $tokenTime = $_COOKIE[$timestampCookie] ?? 0;
        }

        if (empty($tokenTime) || !is_numeric($tokenTime)) {
            return true;
        }

        return (time() - (int)$tokenTime) > $this->config['csrf_expire'];
    }

    /**
     * Generate cryptographically secure token
     * 
     * @return string
     * @throws RuntimeException If token generation fails
     */
    private function generateToken(): string
    {
        try {
            $randomBytes = random_bytes(self::TOKEN_LENGTH);
            return bin2hex($randomBytes);
        } catch (\Exception $e) {
            throw new RuntimeException('Failed to generate secure token: ' . $e->getMessage());
        }
    }

    /**
     * Set token in cookie with security flags
     * 
     * @param string $token Token to set
     * @throws RuntimeException If cookie setting fails
     */
    private function setTokenCookie(string $token): void
    {
        $cookieName = $this->config['csrf_cookie_name'];
        $expireTime = time() + $this->config['csrf_expire'];
        $secure = $this->config['csrf_secure_cookie'] && $this->isHttps();
        $httpOnly = $this->config['csrf_httponly'];
        $sameSite = $this->config['csrf_samesite'];

        // Set main token cookie
        if (!$this->setCookie($cookieName, $token, $expireTime, $secure, $httpOnly, $sameSite)) {
            throw new RuntimeException('Failed to set CSRF token cookie');
        }

        // Store timestamp in session (server-side, tamper-proof)
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['_csrf_token_time'] = time();
        }

        // Set timestamp cookie as fallback
        $timestampCookie = $cookieName . '_time';
        if (!$this->setCookie($timestampCookie, (string)time(), $expireTime, $secure, $httpOnly, $sameSite)) {
            throw new RuntimeException('Failed to set CSRF timestamp cookie');
        }
    }

    /**
     * Set cookie with proper parameters
     * 
     * @param string $name Cookie name
     * @param string $value Cookie value
     * @param int $expire Expiration time
     * @param bool $secure Secure flag
     * @param bool $httpOnly HTTP only flag
     * @param string $sameSite SameSite attribute
     * @return bool Success status
     */
    private function setCookie(string $name, string $value, int $expire, bool $secure, bool $httpOnly, string $sameSite): bool
    {
        return setcookie($name, $value, [
            'expires' => $expire,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => $httpOnly,
            'samesite' => $sameSite
        ]);
    }

    /**
     * Check if connection is HTTPS
     * 
     * @return bool
     */
    private function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) ||
            (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    }

    /**
     * Get current request URI
     * 
     * @return string
     * @throws RuntimeException If URI is malformed or too long
     */
    public function getCurrentUri(): string
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';

        // Security check for URI length
        if (strlen($requestUri) > self::MAX_URI_LENGTH) {
            throw new RuntimeException('Request URI too long');
        }

        // Remove script name from URI if present
        if (strpos($requestUri, $scriptName) === 0) {
            $requestUri = substr($requestUri, strlen($scriptName));
        }

        // Parse and clean URI
        $parsedUri = parse_url($requestUri, PHP_URL_PATH);
        if ($parsedUri === false) {
            throw new RuntimeException('Malformed request URI');
        }

        return ltrim($parsedUri ?? '', '/');
    }

    /**
     * Get configuration value
     * 
     * @param string $key Configuration key
     * @return mixed Configuration value
     */
    public function getConfig(string $key)
    {
        return $this->config[$key] ?? null;
    }

    /**
     * Check if CSRF protection is enabled
     * 
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->config['csrf_protection'];
    }
}
