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
        self::assertSafeUrl($url);
        return self::execute('GET', $url, $options);
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
        self::assertSafeUrl($url);
        $options['body'] = $body;
        return self::execute('POST', $url, $options);
    }

    /**
     * Validate a URL is safe to connect to (not a private IP, not a disallowed scheme).
     *
     * @throws \InvalidArgumentException for bad scheme or missing host
     * @throws \RuntimeException for SSRF-blocked or unresolvable URLs
     */
    public static function assertSafeUrl(string $url): void
    {
        $parsed = parse_url($url);

        if (!in_array($parsed['scheme'] ?? '', ['https', 'http'], true)) {
            throw new \InvalidArgumentException("Disallowed URL scheme: {$url}");
        }

        $host = $parsed['host'] ?? '';

        if ($host === '') {
            throw new \InvalidArgumentException("No host in URL: {$url}");
        }

        // Strict allowlist mode: when configured, ONLY listed hostnames may be contacted.
        // This is a defence-in-depth layer on top of the IP CIDR block — useful for
        // applications that only ever need to reach known external services.
        if (!empty(self::$outboundAllowList)) {
            $normalizedHost = strtolower(ltrim($host, '['));
            $normalizedHost = rtrim($normalizedHost, ']');
            if (!in_array($normalizedHost, self::$outboundAllowList, true)) {
                throw new \RuntimeException(
                    "SSRF blocked: {$url} — host is not in the outbound allowlist"
                );
            }
        }

        // Strip IPv6 brackets: [::1] → ::1
        $isIpv6Bracket = str_starts_with($host, '[') && str_ends_with($host, ']');
        $rawHost       = $isIpv6Bracket ? substr($host, 1, -1) : $host;

        // If host is already an IPv6 address, validate it directly.
        // gethostbynamel() only resolves A (IPv4) records, so IPv6 literals would bypass
        // the CIDR checks below without this special path.
        if ($isIpv6Bracket || filter_var($rawHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            if (self::isBlockedIpv6($rawHost)) {
                throw new \RuntimeException("SSRF blocked: {$url} is a private/reserved IPv6 address");
            }

            return; // Valid public IPv6 — allow
        }

        // Resolve hostname to IPv4 addresses and check each one
        $ips = gethostbynamel($rawHost);

        if ($ips === false || empty($ips)) {
            throw new \RuntimeException("Could not resolve host: {$host}");
        }

        foreach ($ips as $ip) {
            foreach (self::BLOCKED_CIDRS as $cidr) {
                if (self::ipInCidr($ip, $cidr)) {
                    throw new \RuntimeException(
                        "SSRF blocked: {$url} resolves to private/reserved IP {$ip}"
                    );
                }
            }
        }
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
    private static function execute(string $method, string $url, array $options): string
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('cURL extension is not available.');
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $options['timeout'] ?? self::DEFAULT_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,   // Never auto-follow — re-validate each hop
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'MythPHP/1.0',
        ]);

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
        curl_close($ch);

        if ($result === false) {
            throw new \RuntimeException("HTTP request failed: {$error}");
        }

        if ($code >= 400) {
            throw new \RuntimeException("HTTP {$code} response from {$url}");
        }

        return (string) $result;
    }
}
