<?php

declare(strict_types=1);

namespace Core\Security;

use Core\Http\Request;

class IpBlocklist
{
    public const TABLE = 'ip_blocklist';

    private array $config;

    public function __construct(?array $config = null)
    {
        $defaults = [
            'enabled' => true,
            'cache_ttl' => 60,
            'ips' => [],
            'cidrs' => [],
            'auto' => [
                'enabled' => true,
                'events' => [
                    AuditLogger::E_BRUTE_FORCE => [
                        'threshold' => 3,
                        'window_seconds' => 3600,
                        'ttl_seconds' => 86400,
                        'reason' => 'Repeated brute-force activity',
                    ],
                    AuditLogger::E_CSRF_FAILURE => [
                        'threshold' => 10,
                        'window_seconds' => 300,
                        'ttl_seconds' => 3600,
                        'reason' => 'Repeated CSRF failures',
                    ],
                    AuditLogger::E_SUSPICIOUS_INPUT => [
                        'threshold' => 5,
                        'window_seconds' => 3600,
                        'ttl_seconds' => 21600,
                        'reason' => 'Repeated suspicious input events',
                    ],
                ],
            ],
        ];

        $this->config = array_replace_recursive($defaults, $config ?? (array) config('security.blocklist', []));
    }

    public function isEnabled(): bool
    {
        return ($this->config['enabled'] ?? true) === true;
    }

    public function resolveClientIp(?Request $request = null): string
    {
        $server = $request?->server() ?? $_SERVER;
        $remoteAddr = trim((string) ($server['REMOTE_ADDR'] ?? ''));
        $trustedProxies = $this->normalizeList((array) config('security.trusted.proxies', []));

        if ($remoteAddr !== '' && $this->isTrustedProxy($remoteAddr, $trustedProxies)) {
            $candidates = [
                $server['HTTP_CF_CONNECTING_IP'] ?? null,
                $server['HTTP_CLIENT_IP'] ?? null,
                $server['HTTP_X_FORWARDED_FOR'] ?? null,
                $server['HTTP_X_CLUSTER_CLIENT_IP'] ?? null,
                $server['HTTP_FORWARDED_FOR'] ?? null,
            ];

            foreach ($candidates as $candidate) {
                $resolved = $this->extractForwardedIp((string) $candidate);
                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }

        return $remoteAddr !== '' ? $remoteAddr : '0.0.0.0';
    }

    public function decisionFor(?Request $request = null): ?array
    {
        $ip = $this->resolveClientIp($request);
        if (!$this->isValidIp($ip)) {
            return null;
        }

        return $this->decisionForIp($ip);
    }

    public function decisionForIp(string $ip): ?array
    {
        if (!$this->isEnabled() || !$this->isValidIp($ip)) {
            return null;
        }

        $cache = function_exists('cache') ? cache() : null;
        $cacheKey = $this->decisionCacheKey($ip);
        $missing = ['__missing' => true];

        if ($cache !== null) {
            $cached = $cache->get($cacheKey, $missing);
            if ($cached !== $missing) {
                return is_array($cached) && (($cached['blocked'] ?? false) === true) ? $cached : null;
            }
        }

        $decision = $this->staticDecision($ip) ?? $this->dynamicDecision($ip);

        if ($cache !== null) {
            $cache->put($cacheKey, $decision ?? ['blocked' => false], max(1, (int) ($this->config['cache_ttl'] ?? 60)));
        }

        return $decision;
    }

    public function add(string $ip, string $reason, ?string $expiresAt = null, bool $autoAdded = false): bool
    {
        $ip = trim($ip);
        if (!$this->isValidIp($ip) || trim($reason) === '') {
            return false;
        }

        try {
            $existing = $this->table()->where('ip_address', $ip)->fetch();

            $payload = [
                'ip_address' => $ip,
                'reason' => trim($reason),
                'blocked_at' => date('Y-m-d H:i:s'),
                'expires_at' => $expiresAt,
                'auto_added' => $autoAdded ? 1 : 0,
            ];

            if (is_array($existing) && !empty($existing)) {
                $this->table()->where('ip_address', $ip)->update($payload);
            } else {
                $this->table()->insert($payload);
            }

            $this->forgetDecisionCache($ip);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function remove(string $ip): bool
    {
        $ip = trim($ip);
        if (!$this->isValidIp($ip)) {
            return false;
        }

        try {
            $deleted = $this->table()->where('ip_address', $ip)->delete();
            $this->forgetDecisionCache($ip);
            return $deleted !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        try {
            $rows = $this->table()->orderBy('blocked_at', 'DESC')->get();
            return is_array($rows) ? $rows : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function prune(): int
    {
        try {
            $expired = $this->table()
                ->where('expires_at', '<', date('Y-m-d H:i:s'))
                ->get();

            $count = 0;
            foreach ((array) $expired as $row) {
                $ip = (string) ($row['ip_address'] ?? '');
                if ($ip !== '') {
                    $this->forgetDecisionCache($ip);
                }
                $count++;
            }

            $this->table()->where('expires_at', '<', date('Y-m-d H:i:s'))->delete();

            return $count;
        } catch (\Throwable) {
            return 0;
        }
    }

    public function import(string $filePath, string $reason, ?string $expiresAt = null): int
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return 0;
        }

        $count = 0;
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim((string) preg_replace('/\s+#.*$/', '', (string) $line));
            if ($line === '' || !$this->isValidIp($line)) {
                continue;
            }

            if ($this->add($line, $reason, $expiresAt, false)) {
                $count++;
            }
        }

        return $count;
    }

    public function observeAuditEvent(string $eventType, ?string $ip = null): void
    {
        if (($this->config['auto']['enabled'] ?? true) !== true) {
            return;
        }

        $rule = (array) (($this->config['auto']['events'][$eventType] ?? null) ?: []);
        if ($rule === []) {
            return;
        }

        $ip = $ip !== null ? trim($ip) : $this->resolveClientIp();
        if (!$this->isValidIp($ip)) {
            return;
        }

        $cache = function_exists('cache') ? cache() : null;
        if ($cache === null) {
            return;
        }

        $windowSeconds = max(1, (int) ($rule['window_seconds'] ?? 60));
        $threshold = max(1, (int) ($rule['threshold'] ?? 1));
        $ttlSeconds = max(1, (int) ($rule['ttl_seconds'] ?? 3600));
        $counterKey = $this->counterCacheKey($eventType, $ip);
        $state = $cache->get($counterKey, ['count' => 0, 'expires_at' => time() + $windowSeconds]);
        $now = time();

        if (!is_array($state) || ($state['expires_at'] ?? 0) < $now) {
            $state = ['count' => 0, 'expires_at' => $now + $windowSeconds];
        }

        $state['count'] = (int) ($state['count'] ?? 0) + 1;
        $remaining = max(1, (int) $state['expires_at'] - $now);
        $cache->put($counterKey, $state, $remaining);

        if ($state['count'] < $threshold) {
            return;
        }

        $expiresAt = date('Y-m-d H:i:s', $now + $ttlSeconds);
        $reason = trim((string) ($rule['reason'] ?? ('Automatic block for ' . $eventType)));
        if ($this->add($ip, $reason, $expiresAt, true)) {
            $cache->forget($counterKey);
        }
    }

    private function staticDecision(string $ip): ?array
    {
        foreach ($this->normalizeList((array) ($this->config['ips'] ?? [])) as $blockedIp) {
            if (hash_equals($blockedIp, $ip)) {
                return [
                    'blocked' => true,
                    'ip' => $ip,
                    'reason' => 'Static IP blocklist',
                    'source' => 'static-ip',
                    'expires_at' => null,
                ];
            }
        }

        foreach ($this->normalizeList((array) ($this->config['cidrs'] ?? [])) as $cidr) {
            if ($this->ipInCidr($ip, $cidr)) {
                return [
                    'blocked' => true,
                    'ip' => $ip,
                    'reason' => 'Static CIDR blocklist',
                    'source' => 'static-cidr',
                    'expires_at' => null,
                ];
            }
        }

        return null;
    }

    private function dynamicDecision(string $ip): ?array
    {
        try {
            $row = $this->table()
                ->where('ip_address', $ip)
                ->fetch();

            if (!is_array($row) || $row === []) {
                return null;
            }

            $expiresAt = trim((string) ($row['expires_at'] ?? ''));
            if ($expiresAt !== '' && strtotime($expiresAt) !== false && strtotime($expiresAt) < time()) {
                $this->remove($ip);
                return null;
            }

            return [
                'blocked' => true,
                'ip' => $ip,
                'reason' => (string) ($row['reason'] ?? 'Dynamic blocklist'),
                'source' => ((int) ($row['auto_added'] ?? 0) === 1) ? 'dynamic-auto' : 'dynamic-manual',
                'expires_at' => $expiresAt !== '' ? $expiresAt : null,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private function table()
    {
        return db()->table(self::TABLE);
    }

    private function isTrustedProxy(string $remoteAddr, array $trustedProxies): bool
    {
        foreach ($trustedProxies as $proxy) {
            if ($proxy === '') {
                continue;
            }

            if (hash_equals($remoteAddr, $proxy)) {
                return true;
            }

            if (str_contains($proxy, '/') && $this->ipInCidr($remoteAddr, $proxy)) {
                return true;
            }
        }

        return false;
    }

    private function extractForwardedIp(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        foreach (explode(',', $value) as $segment) {
            $candidate = trim($segment);
            if ($this->isValidIp($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function isValidIp(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP) !== false;
    }

    private function normalizeList(array $items): array
    {
        $normalized = [];
        foreach ($items as $item) {
            $value = trim((string) $item);
            if ($value === '') {
                continue;
            }
            $normalized[$value] = $value;
        }

        return array_values($normalized);
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return false;
        }

        [$subnet, $maskBits] = explode('/', $cidr, 2);
        $subnet = trim($subnet);
        $maskBits = (int) $maskBits;

        $ipBinary = @inet_pton($ip);
        $subnetBinary = @inet_pton($subnet);
        if ($ipBinary === false || $subnetBinary === false || strlen($ipBinary) !== strlen($subnetBinary)) {
            return false;
        }

        $bytes = intdiv($maskBits, 8);
        $bits = $maskBits % 8;

        if ($bytes > 0 && substr($ipBinary, 0, $bytes) !== substr($subnetBinary, 0, $bytes)) {
            return false;
        }

        if ($bits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $bits)) & 0xFF;

        return (ord($ipBinary[$bytes]) & $mask) === (ord($subnetBinary[$bytes]) & $mask);
    }

    private function decisionCacheKey(string $ip): string
    {
        return 'ip_blocklist:decision:' . sha1($ip);
    }

    private function counterCacheKey(string $eventType, string $ip): string
    {
        return 'ip_blocklist:auto:' . sha1($eventType . '|' . $ip);
    }

    private function forgetDecisionCache(string $ip): void
    {
        if (function_exists('cache')) {
            cache()->forget($this->decisionCacheKey($ip));
        }
    }
}