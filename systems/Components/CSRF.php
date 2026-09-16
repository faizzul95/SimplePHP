<?php

namespace Components;

use Core\Http\CookieFactory;
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
     * Shared security helper used for safe host normalization.
     */
    private Security $security;

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
        'csrf_allow_missing_origin' => false,
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

    /** @throws InvalidArgumentException If configuration is invalid */
    public function __construct(array $config = [])
    {
        $this->security = new Security();
        $this->config = $this->validateAndMergeConfig($config);
        $this->currentUri = $this->getCurrentUri();
    }

    /**
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
    public function validate(?string $url, bool $force = false): bool
    {
        try {
            $this->currentUri = $url;

            // Only validate state-changing requests
            if (!$this->isWriteRequest()) {
                return true;
            }

            if (!$this->config['csrf_protection']) {
                return true;
            }

            // Opt-out model: skip CSRF for excluded URIs (e.g. API routes using Bearer tokens)
            if (!$force && $this->isExcludedUri()) {
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
            try {
                if (function_exists('logger')) {
                    logger()->log_error('CSRF validation error: ' . $e->getMessage());
                } else {
                    Logger::instance()->log_error('CSRF validation error: ' . $e->getMessage());
                }
            } catch (\Throwable) {
                // Never let a logging failure prevent a CSRF decision from being returned.
            }
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

        if ($this->tokenCache !== null) {
            return $this->tokenCache;
        }

        // The session copy first: it is the one validateToken() compares
        // against, and unlike the cookie it cannot be written from outside.
        $existingToken = $this->getTokenFromSession();
        $mirroredFromCookie = false;

        if ($existingToken === '') {
            $existingToken = $this->getTokenFromCookie();
            $mirroredFromCookie = $existingToken !== '';
        }

        if ($existingToken !== '' && $this->isValidTokenFormat($existingToken) && !$this->isTokenExpired()) {
            /*
            | A cookie that outlived its session — most often because login
            | called session_regenerate_id() — has no session copy yet. Adopt it
            | rather than issuing a new one, so the token already rendered into
            | the open page keeps working, and record it so the next request
            | validates against the session rather than falling back.
            */
            if ($mirroredFromCookie && session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION[$this->sessionTokenKey()] = $existingToken;
            }

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

        // Session before cookie, so a page renders the token the server will
        // actually accept rather than one an attacker planted in the cookie jar.
        return $this->tokenCache
            ?: ($this->getTokenFromSession() ?: $this->getTokenFromCookie());
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

        $tokenName = htmlspecialchars($this->config['csrf_token_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $tokenValue = htmlspecialchars($token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

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
     */
    private function isWriteRequest(): bool
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        return in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    /**
     * Check if current URI is excluded from CSRF verification.
     * Supports wildcard patterns (e.g. 'api/*').
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
     * @throws RuntimeException If validation fails due to system error
     */
    private function validateToken(): bool
    {
        $postToken = $this->getTokenFromPost();

        /*
        | The expected value comes from the session when there is one, and only
        | falls back to the cookie when there is not.
        |
        | Double-submit — comparing the request against a cookie — assumes an
        | attacker cannot write the cookie. On a shared parent domain that
        | assumption is false: any subdomain, including a vendor-hosted status
        | page or a compromised staging host, can set a cookie on the parent and
        | therefore supply *both* halves of the pair. The session copy cannot be
        | written from outside, so a forged pair no longer matches anything.
        |
        | The cookie stays as the transport the JavaScript client reads; it is
        | just no longer the thing we compare against.
        */
        $expected = $this->getTokenFromSession();
        $fromSession = $expected !== '';

        if (!$fromSession) {
            $expected = $this->getTokenFromCookie();
        }

        if (empty($postToken) || empty($expected)) {
            return false;
        }

        if (!$this->isValidTokenFormat($postToken) || !$this->isValidTokenFormat($expected)) {
            return false;
        }

        // Check if tokens match using timing-safe comparison
        if (!hash_equals($expected, $postToken)) {
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
        $host = $this->safeHostForOrigin((string) ($_SERVER['HTTP_HOST'] ?? ''));
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
     * Normalize the current host header before using it in origin comparisons.
     */
    private function safeHostForOrigin(string $rawHost): string
    {
        $rawHost = trim($rawHost);
        if ($rawHost === '') {
            return '';
        }

        $hostPart = $rawHost;
        $port = null;
        $wrapIpv6 = false;

        if (preg_match('/^\[([^\]]+)\](?::(\d+))?$/', $rawHost, $matches) === 1) {
            $hostPart = $matches[1];
            $port = $matches[2] ?? null;
            $wrapIpv6 = true;
        } elseif (substr_count($rawHost, ':') === 1 && preg_match('/^(.+):(\d+)$/', $rawHost, $matches) === 1) {
            $hostPart = $matches[1];
            $port = $matches[2];
        }

        $normalizedHost = $this->security->normalizeHostHeader($hostPart);
        if ($normalizedHost === '') {
            return '';
        }

        if ($wrapIpv6) {
            $normalizedHost = '[' . $normalizedHost . ']';
        }

        if ($port !== null) {
            $portNumber = (int) $port;
            if ($portNumber >= 1 && $portNumber <= 65535) {
                return strtolower($normalizedHost . ':' . $portNumber);
            }
        }

        return strtolower($normalizedHost);
    }

    /**
     * Get token from POST data or X-CSRF-TOKEN header
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
     */
    private function getTokenFromCookie(): string
    {
        return $_COOKIE[$this->config['csrf_cookie_name']] ?? '';
    }

    /** The session key holding the authoritative token. */
    private function sessionTokenKey(): string
    {
        return '_csrf_token';
    }

    /**
     * The token as the server recorded it.
     *
     * Empty when there is no session — a stateless API request, or a page served
     * before the session started. validateToken() then falls back to the cookie,
     * which is the pre-existing behaviour and no worse than it was.
     */
    private function getTokenFromSession(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }

        $token = $_SESSION[$this->sessionTokenKey()] ?? '';

        return is_string($token) ? $token : '';
    }

    /** Validate token format */
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
     * @throws RuntimeException If cookie setting fails
     */
    private function setTokenCookie(string $token): void
    {
        $cookieName = $this->config['csrf_cookie_name'];
        $expireTime = time() + $this->config['csrf_expire'];
        $secure = $this->config['csrf_secure_cookie'] && $this->isHttps();
        $httpOnly = $this->config['csrf_httponly'];
        $sameSite = $this->config['csrf_samesite'];

        if (!$this->setCookie($cookieName, $token, $expireTime, $secure, $httpOnly, $sameSite)) {
            throw new RuntimeException('Failed to set CSRF token cookie');
        }

        // The authoritative copy. The cookie above is transport for the JS
        // client; this is what validateToken() actually compares against, and
        // nothing outside this process can write it.
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[$this->sessionTokenKey()] = $token;
            $_SESSION['_csrf_token_time'] = time();
        }

        // Set timestamp cookie as fallback
        $timestampCookie = $cookieName . '_time';
        if (!$this->setCookie($timestampCookie, (string)time(), $expireTime, $secure, $httpOnly, $sameSite)) {
            throw new RuntimeException('Failed to set CSRF timestamp cookie');
        }
    }

    /**
     * @param bool $httpOnly HTTP only flag
     * @return bool Success status
     */
    private function setCookie(string $name, string $value, int $expire, bool $secure, bool $httpOnly, string $sameSite): bool
    {
        return CookieFactory::send(
            $name,
            $value,
            max(0, $expire - time()),
            '/',
            '',
            $secure,
            $httpOnly,
            $sameSite,
            false
        );
    }

    /**
     * Check if connection is HTTPS
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

        $parsedUri = parse_url($requestUri, PHP_URL_PATH);
        if ($parsedUri === false) {
            throw new RuntimeException('Malformed request URI');
        }

        return ltrim($parsedUri ?? '', '/');
    }

    /** @return mixed Configuration value */
    public function getConfig(string $key)
    {
        return $this->config[$key] ?? null;
    }

    /**
     * Check if CSRF protection is enabled
     */
    public function isEnabled(): bool
    {
        return $this->config['csrf_protection'];
    }
}
