<?php

declare(strict_types=1);

namespace Core\Support;

/**
 * Outbound HTTP client with SSRF protection.
 *
 * Blocks requests to private/loopback IP ranges before any connection is made.
 * Never auto-follows redirects (each hop must be validated independently).
 *
 */
final class HttpClient
{
    // Private, loopback, and link-local CIDR ranges (IPv4)
    private const BLOCKED_CIDRS = [
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '127.0.0.0/8',
        '169.254.0.0/16',   // link-local
        '100.64.0.0/10',    // shared address space
        '0.0.0.0/8',
        '240.0.0.0/4',      // reserved
        '198.18.0.0/15',    // benchmark testing
    ];

    private const DEFAULT_TIMEOUT = 10;
    private const DEFAULT_CONNECT_TIMEOUT = 5;
    private const PIN_POLICY_BLOCK = 'block';
    private const PIN_POLICY_LOG_ONLY = 'log-only';

    /**
     * Optional strict allowlist of outbound hostnames (lowercased).
     *
     * When non-empty, ONLY these hostnames may be contacted — all other
     * outbound requests are blocked regardless of their IP address.
     * This is the most secure mode: deny-all by default, allow specific
     * trusted external services.
     *
     * Configure via HttpClient::allowHosts(['api.example.com', 'haveibeenpwned.com'])
     * or set 'http_client.allowed_hosts' in app config.
     *
     * @var string[]
     */
    private static array $outboundAllowList = [];

    /**
     * Restrict all outbound requests to the given hostname list (strict mode).
     * Replaces any previously configured allowlist.
     *
     * @param string[] $hostnames Case-insensitive hostname list
     */
    public static function allowHosts(array $hostnames): void
    {
        self::$outboundAllowList = array_values(array_map('strtolower', array_filter($hostnames, 'is_string')));
    }

    /**
     * Return the current outbound allowlist (empty = not in strict mode).
     *
     * @return string[]
     */
    public static function getAllowedHosts(): array
    {
        return self::$outboundAllowList;
    }

    /**
     * Clear the allowlist (disable strict mode).
     */
    public static function clearAllowList(): void
    {
        self::$outboundAllowList = [];
    }

    /**
     * Perform a GET request to an external URL.
     *
     * @param string $url     Must use http:// or https://. Private IPs are blocked.
     * @param array  $options ['timeout' => int, 'headers' => string[]]
     * @return string Response body
     * @throws \InvalidArgumentException for disallowed URL schemes
     * @throws \RuntimeException if host resolves to a private IP or request fails
     */
    public static function get(string $url, array $options = []): string
    {
        $resolution = self::resolveSafeUrl($url);
        return self::execute('GET', $url, $options, $resolution);
    }

    /**
     * Perform a POST request to an external URL.
     *
     * @param string       $url
     * @param array|string $body    POST data
     * @param array        $options ['timeout' => int, 'headers' => string[]]
     */
    public static function post(string $url, array|string $body = [], array $options = []): string
    {
        $resolution = self::resolveSafeUrl($url);
        $options['body'] = $body;
        return self::execute('POST', $url, $options, $resolution);
    }

    /**
     * Validate a URL is safe to connect to (not a private IP, not a disallowed scheme).
     *
     * @throws \InvalidArgumentException for bad scheme or missing host
     * @throws \RuntimeException for SSRF-blocked or unresolvable URLs
     */
    public static function assertSafeUrl(string $url): void
    {
        self::resolveSafeUrl($url);
    }

    /**
     * Validate the IP cURL actually connected to.
     *
     * @throws \RuntimeException when the connected IP is private/reserved and the host is not explicitly allowlisted
     */
    public static function assertConnectedIpIsSafe(string $connectedIp, string $url): void
    {
        $runtimeConfig = self::runtimeConfig();
        if (($runtimeConfig['post_connect_ip_check'] ?? true) !== true) {
            return;
        }

        $connectedIp = trim($connectedIp);
        if ($connectedIp === '') {
            return;
        }

        $parsed = parse_url($url);
        $host = (string) ($parsed['host'] ?? '');
        $normalizedHost = self::normalizeHost($host);
        if ($normalizedHost !== '' && self::isAllowedPrivateHost($normalizedHost)) {
            return;
        }

        if (filter_var($connectedIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            if (self::isBlockedIpv4($connectedIp)) {
                \Core\Security\AuditLogger::suspiciousInput('http_client', 'dns_rebinding:' . $connectedIp);
                throw new \RuntimeException("DNS rebinding detected. Connected IP: {$connectedIp}");
            }

            return;
        }

        if (filter_var($connectedIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false && self::isBlockedIpv6($connectedIp)) {
            \Core\Security\AuditLogger::suspiciousInput('http_client', 'dns_rebinding:' . $connectedIp);
            throw new \RuntimeException("DNS rebinding detected. Connected IP: {$connectedIp}");
        }
    }

    /**
     * Validate a URL is safe to connect to and return normalized resolution details.
     *
     * @return array{normalized_host:string, port:int, resolved_ip:?string, should_pin:bool}
     * @throws \InvalidArgumentException for bad scheme or missing host
     * @throws \RuntimeException for SSRF-blocked or unresolvable URLs
     */
    private static function resolveSafeUrl(string $url): array
    {
        $parsed = parse_url($url);

        if (!in_array($parsed['scheme'] ?? '', ['https', 'http'], true)) {
            throw new \InvalidArgumentException("Disallowed URL scheme: {$url}");
        }

        $host = (string) ($parsed['host'] ?? '');
        if ($host === '') {
            throw new \InvalidArgumentException("No host in URL: {$url}");
        }

        $normalizedHost = self::normalizeHost($host);
        $allowedPrivateHost = $normalizedHost !== '' && self::isAllowedPrivateHost($normalizedHost);

        if (!empty(self::$outboundAllowList) && !in_array($normalizedHost, self::$outboundAllowList, true)) {
            throw new \RuntimeException(
                "SSRF blocked: {$url} — host is not in the outbound allowlist"
            );
        }

        $isIpv6Bracket = str_starts_with($host, '[') && str_ends_with($host, ']');
        $rawHost       = $isIpv6Bracket ? substr($host, 1, -1) : $host;
        $port = (int) ($parsed['port'] ?? (($parsed['scheme'] ?? 'https') === 'http' ? 80 : 443));

        if ($isIpv6Bracket || filter_var($rawHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            if (!$allowedPrivateHost && self::isBlockedIpv6($rawHost)) {
                throw new \RuntimeException("SSRF blocked: {$url} is a private/reserved IPv6 address");
            }

            return [
                'normalized_host' => $normalizedHost,
                'port' => $port,
                'resolved_ip' => null,
                'should_pin' => false,
            ];
        }

        if (filter_var($rawHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            if (!$allowedPrivateHost && self::isBlockedIpv4($rawHost)) {
                throw new \RuntimeException("SSRF blocked: {$url} resolves to private/reserved IP {$rawHost}");
            }

            return [
                'normalized_host' => $normalizedHost,
                'port' => $port,
                'resolved_ip' => $rawHost,
                'should_pin' => false,
            ];
        }

        $ips = gethostbynamel($rawHost);
        if ($ips === false || empty($ips)) {
            throw new \RuntimeException("Could not resolve host: {$host}");
        }

        $resolvedIp = null;
        foreach ($ips as $ip) {
            if (!$allowedPrivateHost && self::isBlockedIpv4($ip)) {
                throw new \RuntimeException(
                    "SSRF blocked: {$url} resolves to private/reserved IP {$ip}"
                );
            }

            if ($resolvedIp === null) {
                $resolvedIp = $ip;
            }
        }

        return [
            'normalized_host' => $normalizedHost,
            'port' => $port,
            'resolved_ip' => $resolvedIp,
            'should_pin' => !$allowedPrivateHost && $resolvedIp !== null,
        ];
    }

    private static function isBlockedIpv4(string $ip): bool
    {
        foreach (self::BLOCKED_CIDRS as $cidr) {
            if (self::ipInCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeHost(string $host): string
    {
        return strtolower(trim($host, '[]'));
    }

    private static function isAllowedPrivateHost(string $host): bool
    {
        $allowed = array_values(array_map(
            static fn($item): string => self::normalizeHost((string) $item),
            (array) (self::runtimeConfig()['allowed_private_hosts'] ?? [])
        ));

        return in_array(self::normalizeHost($host), $allowed, true);
    }

    private static function runtimeConfig(): array
    {
        $config = function_exists('config') ? (array) config('security.http_client', []) : [];

        $pins = [];
        foreach ((array) ($config['pins'] ?? []) as $host => $hostPins) {
            if (!is_string($host) || trim($host) === '') {
                continue;
            }

            $normalizedHost = self::normalizeHost($host);
            $normalizedPins = array_values(array_filter(array_map(static function ($pin): string {
                return trim((string) $pin);
            }, (array) $hostPins), static function (string $pin): bool {
                return $pin !== '';
            }));

            if ($normalizedHost === '' || $normalizedPins === []) {
                continue;
            }

            $pins[$normalizedHost] = $normalizedPins;
        }

        $pinOnError = strtolower(trim((string) ($config['pin_on_error'] ?? self::PIN_POLICY_BLOCK)));
        if (!in_array($pinOnError, [self::PIN_POLICY_BLOCK, self::PIN_POLICY_LOG_ONLY], true)) {
            $pinOnError = self::PIN_POLICY_BLOCK;
        }

        return [
            'post_connect_ip_check' => ($config['post_connect_ip_check'] ?? true) === true,
            'force_ipv4' => ($config['force_ipv4'] ?? true) === true,
            'connect_timeout_sec' => max(1, (int) ($config['connect_timeout_sec'] ?? self::DEFAULT_CONNECT_TIMEOUT)),
            'dns_cache_timeout' => max(0, (int) ($config['dns_cache_timeout'] ?? 0)),
            'allowed_private_hosts' => (array) ($config['allowed_private_hosts'] ?? []),
            'pins' => $pins,
            'pin_on_error' => $pinOnError,
        ];
    }

    /**
     * @return string[]
     */
    private static function pinsForHost(string $host): array
    {
        $runtimeConfig = self::runtimeConfig();
        return array_values((array) ($runtimeConfig['pins'][self::normalizeHost($host)] ?? []));
    }

    private static function pinPolicy(): string
    {
        return (string) (self::runtimeConfig()['pin_on_error'] ?? self::PIN_POLICY_BLOCK);
    }

    /**
     * Block private/reserved IPv6 addresses.
     * Covers: loopback (::1), unique local (fc00::/7), link-local (fe80::/10),
     * and IPv4-mapped (::ffff:0:0/96) addresses.
     */
    private static function isBlockedIpv6(string $ip): bool
    {
        $packed = inet_pton($ip);

        if ($packed === false || strlen($packed) !== 16) {
            return true; // Unparseable — block it
        }

        $b0 = ord($packed[0]);
        $b1 = ord($packed[1]);

        // ::1 — loopback
        if ($packed === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x01") {
            return true;
        }

        // fc00::/7 — unique local (covers fd00::/8 too)
        if (($b0 & 0xFE) === 0xFC) {
            return true;
        }

        // fe80::/10 — link-local
        if ($b0 === 0xFE && ($b1 & 0xC0) === 0x80) {
            return true;
        }

        // ::ffff:0:0/96 — IPv4-mapped; extract and check the embedded IPv4 address
        if (substr($packed, 0, 12) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff") {
            $ipv4 = inet_ntop(substr($packed, 12)); // 4 bytes → dotted-decimal IPv4
            if ($ipv4 !== false) {
                foreach (self::BLOCKED_CIDRS as $cidr) {
                    if (self::ipInCidr($ipv4, $cidr)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Check if an IPv4 address falls within a CIDR range.
     */
    private static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $ipLong     = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = -1 << (32 - (int) $bits);
        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    /**
     * Execute the cURL request.
     */
    private static function execute(string $method, string $url, array $options, array $resolution): string
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('cURL extension is not available.');
        }

        $runtimeConfig = self::runtimeConfig();
        $pins = self::pinsForHost((string) ($resolution['normalized_host'] ?? ''));
        $shouldValidatePins = $pins !== [] && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
        $ch = curl_init();
        $curlOptions = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $options['timeout'] ?? self::DEFAULT_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => $runtimeConfig['connect_timeout_sec'],
            CURLOPT_FOLLOWLOCATION => false,   // Never auto-follow — re-validate each hop
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'MythPHP/1.0',
            CURLOPT_DNS_CACHE_TIMEOUT => $runtimeConfig['dns_cache_timeout'],
        ];

        if ($shouldValidatePins) {
            if (!defined('CURLOPT_CERTINFO') || !defined('CURLINFO_CERTINFO')) {
                self::handlePinFailure(
                    (string) ($resolution['normalized_host'] ?? ''),
                    'pinning_unavailable:cURL certificate info support is missing'
                );
            } else {
                $curlOptions[CURLOPT_CERTINFO] = true;
            }
        }

        if ($runtimeConfig['force_ipv4']) {
            $curlOptions[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        }

        if (($resolution['should_pin'] ?? false) && !empty($resolution['resolved_ip']) && !empty($resolution['normalized_host'])) {
            $curlOptions[CURLOPT_RESOLVE] = [
                $resolution['normalized_host'] . ':' . (int) ($resolution['port'] ?? 443) . ':' . $resolution['resolved_ip'],
            ];
        }

        curl_setopt_array($ch, $curlOptions);

        if (!empty($options['headers']) && is_array($options['headers'])) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $options['headers']);
        }

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $options['body'] ?? []);
        }

        $result = curl_exec($ch);
        $error  = curl_error($ch);
        $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $actualIp = (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        $certInfo = ($shouldValidatePins && defined('CURLINFO_CERTINFO'))
            ? curl_getinfo($ch, CURLINFO_CERTINFO)
            : null;
        curl_close($ch);

        if ($result === false) {
            throw new \RuntimeException("HTTP request failed: {$error}");
        }


                if ($shouldValidatePins) {
                    self::assertPinnedCertificateMatches(
                        (string) ($resolution['normalized_host'] ?? ''),
                        is_array($certInfo) ? $certInfo : [],
                        $pins
                    );
                }
        self::assertConnectedIpIsSafe($actualIp, $url);

        if ($code >= 400) {
            throw new \RuntimeException("HTTP {$code} response from {$url}");
        }

        return (string) $result;
    }

    /**
     * @param string[] $pins
     */
    private static function assertPinnedCertificateMatches(string $host, array $certInfo, array $pins): void
    {
        if ($pins === []) {
            return;
        }

        try {
            $pin = self::extractSpkiPinFromCertInfo($certInfo);
        } catch (\Throwable $e) {
            self::handlePinFailure($host, 'pin_validation_failed:' . $e->getMessage());
            return;
        }

        if (!in_array($pin, $pins, true)) {
            self::handlePinFailure($host, 'pin_mismatch:' . $pin);
        }
    }

    private static function extractSpkiPinFromCertInfo(array $certInfo): string
    {
        $certificatePem = (string) ($certInfo[0]['Cert'] ?? '');
        if ($certificatePem === '') {
            throw new \RuntimeException('Peer certificate information is unavailable.');
        }

        return self::extractSpkiPinFromCertificatePem($certificatePem);
    }

    private static function extractSpkiPinFromCertificatePem(string $certificatePem): string
    {
        if (!function_exists('openssl_x509_read') || !function_exists('openssl_pkey_get_public') || !function_exists('openssl_pkey_get_details')) {
            throw new \RuntimeException('OpenSSL support is required for certificate pinning.');
        }

        $certificate = openssl_x509_read($certificatePem);
        if ($certificate === false) {
            throw new \RuntimeException('Unable to parse peer certificate.');
        }

        $publicKey = openssl_pkey_get_public($certificate);
        if ($publicKey === false) {
            throw new \RuntimeException('Unable to extract peer public key.');
        }

        $details = openssl_pkey_get_details($publicKey);
        if (!is_array($details) || !isset($details['key']) || !is_string($details['key']) || $details['key'] === '') {
            throw new \RuntimeException('Unable to inspect peer public key details.');
        }

        return 'sha256//' . base64_encode(hash('sha256', $details['key'], true));
    }

    private static function handlePinFailure(string $host, string $reason): void
    {
        \Core\Security\AuditLogger::suspiciousInput('http_client', $reason . ':' . self::normalizeHost($host));

        if (self::pinPolicy() === self::PIN_POLICY_BLOCK) {
            throw new \Core\Support\CertificatePinException('Certificate pin validation failed for ' . self::normalizeHost($host));
        }
    }
}
