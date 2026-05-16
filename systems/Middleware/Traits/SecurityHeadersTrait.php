<?php

namespace Middleware\Traits;

trait SecurityHeadersTrait
{
	private const CSP_META_KEYS = [
		'enabled',
		'nonce_enabled',
		'mode',
		'report_uri',
		'report_to',
		'report_only_directives',
	];

	/**
	 * Return the CSP nonce for the current request.
	 * Delegates to \Core\Security\CspNonce so the same value is shared
	 * between the security-headers middleware and the Blade view engine.
	 */
	public static function getNonce(): string
	{
		return \Core\Security\CspNonce::get();
	}

	/**
	 * Reset the nonce. Useful in tests or long-running workers between requests.
	 */
	public static function resetNonce(): void
	{
		\Core\Security\CspNonce::reset();
	}

	public function set_security_headers()
	{
		foreach ($this->buildSecurityHeaders() as $headerLine) {
			header($headerLine, false);
		}
	}

	protected function buildSecurityHeaders(): array
	{
		$security = \config('security') ?? [];
		$headersConfig = $security['headers'] ?? [];
		$headers = [];

		// Strict-Transport-Security (HSTS)
		$hsts = $headersConfig['hsts'] ?? [];
		$hstsEnabled = ($hsts['enabled'] ?? true) === true;
		$hstsHttpsOnly = ($hsts['enforce_https_only'] ?? true) === true;
		if ($hstsEnabled && (!$hstsHttpsOnly || $this->isHttpsRequest())) {
			$hstsValue = 'max-age=' . (int) ($hsts['max_age'] ?? 31536000);
			if (($hsts['include_subdomains'] ?? true) === true) {
				$hstsValue .= '; includeSubDomains';
			}
			if (($hsts['preload'] ?? true) === true) {
				$hstsValue .= '; preload';
			}

			$headers[] = 'Strict-Transport-Security: ' . $hstsValue;
		}

		$headers = array_merge($headers, $this->buildCspHeaders($security));

		// X-Frame-Options
		$headers[] = 'X-Frame-Options: ' . (string) ($headersConfig['x_frame_options'] ?? 'SAMEORIGIN');

		// X-Content-Type-Options
		$headers[] = 'X-Content-Type-Options: ' . (string) ($headersConfig['x_content_type_options'] ?? 'nosniff');

		// Referrer-Policy
		$headers[] = 'Referrer-Policy: ' . (string) ($headersConfig['referrer_policy'] ?? 'strict-origin-when-cross-origin');

		// Cross-origin isolation helpers
		$headers[] = 'Cross-Origin-Opener-Policy: ' . (string) ($headersConfig['cross_origin_opener_policy'] ?? 'same-origin');
		$headers[] = 'Cross-Origin-Resource-Policy: ' . (string) ($headersConfig['cross_origin_resource_policy'] ?? 'same-origin');
		$headers[] = 'X-DNS-Prefetch-Control: ' . (string) ($headersConfig['x_dns_prefetch_control'] ?? 'off');

		// Permissions-Policy (configurable via security.permissions_policy)
		$permPolicy = $security['permissions_policy'] ?? [
			'geolocation' => '(self)',
			'microphone'  => '()',
			'camera'      => '()',
			'fullscreen'  => '(self)',
			'sync-xhr'    => '(self)',
			'usb'         => '()',
		];

		$policies = [];
		foreach ($permPolicy as $feature => $value) {
			// Accept both legacy string format ('(self)', '()') and
			// array format (['self'] or []) as shown in CLAUDE.md security config.
			if (is_array($value)) {
				if (empty($value)) {
					// [] means deny-all
					$policies[] = $feature . '=()';
				} else {
					// ['self'] → (self), ['self', 'https://cdn.example.com'] → (self "https://cdn.example.com")
					$parts = array_map(static function (string $v): string {
						// Wrap plain words like 'self' in parens syntax;
						// full URLs are quoted.
						if (in_array($v, ['self', 'src', 'none'], true)) {
							return $v;
						}
						return '"' . $v . '"';
					}, $value);
					$policies[] = $feature . '=(' . implode(' ', $parts) . ')';
				}
			} else {
				// Legacy string value passed directly (e.g. '(self)', '()')
				$policies[] = $feature . '=' . $value;
			}
		}

		if (!empty($policies)) {
			$headers[] = 'Permissions-Policy: ' . implode(', ', $policies);
		}

		return $headers;
	}

	private function buildCspHeaders(array $security): array
	{
		$csp = $security['csp'] ?? [];
		$trustedTypes = $security['trusted_types'] ?? [];
		$trustedTypesEnabled = ($trustedTypes['enabled'] ?? false) === true;
		$trustedTypesPolicies = array_values(array_filter(
			array_map(static fn($policy): string => trim((string) $policy), (array) ($trustedTypes['policies'] ?? ['default'])),
			static fn(string $policy): bool => $policy !== ''
		));
		$trustedTypesValue = implode(' ', $trustedTypesPolicies);
		$trustedTypesReportOnly = $trustedTypesEnabled && (($trustedTypes['report_only'] ?? true) === true);

		if (isset($csp['enabled']) && $csp['enabled'] === false) {
			return $this->buildTrustedTypesHeaders([], $trustedTypesEnabled && !$trustedTypesReportOnly, $trustedTypesValue);
		}

		$cspDefaults = [
			'default-src' => ["'self'"],
			'script-src'  => ["'self'"],
			'style-src'   => ["'self'"],
			'img-src'     => ["'self'", 'data:'],
			'connect-src' => ["'self'"],
			'font-src'    => ["'self'"],
			'frame-ancestors' => ["'self'"],
			'base-uri'    => ["'self'"],
			'form-action' => ["'self'"],
		];

		$mode = strtolower((string) ($csp['mode'] ?? 'enforce'));
		if (!in_array($mode, ['enforce', 'report-only', 'both'], true)) {
			$mode = 'enforce';
		}

		$nonceEnabled = ($csp['nonce_enabled'] ?? false) === true;
		$reportUri = trim((string) ($csp['report_uri'] ?? ''));
		$reportTo = trim((string) ($csp['report_to'] ?? ''));

		$enforceConfig = array_merge($cspDefaults, $this->filterCspDirectiveConfig($csp));
		$reportOnlyConfig = $enforceConfig;
		if (isset($csp['report_only_directives']) && is_array($csp['report_only_directives'])) {
			$reportOnlyConfig = array_merge($reportOnlyConfig, $csp['report_only_directives']);
		}

		$headers = [];
		if ($mode === 'enforce' || $mode === 'both') {
			$headerValue = $this->compileCspHeaderValue($enforceConfig, $nonceEnabled, $reportUri, $reportTo);
			if ($headerValue !== null) {
				$headers[] = 'Content-Security-Policy: ' . $headerValue;
			}
		}

		$reportOnlyExtras = [];
		if ($trustedTypesReportOnly) {
			$reportOnlyExtras['require-trusted-types-for'] = ["'script'"];
			if ($trustedTypesValue !== '') {
				$reportOnlyExtras['trusted-types'] = [$trustedTypesValue];
			}
		}

		if ($mode === 'report-only' || $mode === 'both' || !empty($reportOnlyExtras)) {
			$headerValue = $this->compileCspHeaderValue($reportOnlyConfig, $nonceEnabled, $reportUri, $reportTo, $reportOnlyExtras);
			if ($headerValue !== null) {
				$headers[] = 'Content-Security-Policy-Report-Only: ' . $headerValue;
			}
		}

		return $this->buildTrustedTypesHeaders($headers, $trustedTypesEnabled && !$trustedTypesReportOnly, $trustedTypesValue);
	}

	private function buildTrustedTypesHeaders(array $headers, bool $sendEnforcedHeaders, string $trustedTypesValue): array
	{
		if ($sendEnforcedHeaders) {
			$headers[] = "Require-Trusted-Types-For: 'script'";
			if ($trustedTypesValue !== '') {
				$headers[] = 'Trusted-Types: ' . $trustedTypesValue;
			}
		}

		return $headers;
	}

	private function compileCspHeaderValue(
		array $config,
		bool $nonceEnabled,
		string $reportUri,
		string $reportTo,
		array $extraDirectives = []
	): ?string {
		$config = array_merge($config, $extraDirectives);
		$config = $this->applyNonceToDirectives($config, $nonceEnabled);
		$directives = [];

		foreach ($config as $directive => $sources) {
			if (!is_array($sources) || empty($sources)) {
				continue;
			}

			$directives[] = $directive . ' ' . implode(' ', $sources);
		}

		if ($reportUri !== '') {
			$directives[] = 'report-uri ' . $reportUri;
		}

		if ($reportTo !== '') {
			$directives[] = 'report-to ' . $reportTo;
		}

		if (empty($directives)) {
			return null;
		}

		return implode('; ', $directives) . ';';
	}

	private function filterCspDirectiveConfig(array $csp): array
	{
		foreach (self::CSP_META_KEYS as $metaKey) {
			unset($csp[$metaKey]);
		}

		return $csp;
	}

	private function applyNonceToDirectives(array $config, bool $nonceEnabled): array
	{
		$nonceSource = null;
		if ($nonceEnabled) {
			$nonceSource = "'nonce-" . \Core\Security\CspNonce::get() . "'";
		}

		foreach ($config as $directive => $sources) {
			if (!is_array($sources)) {
				continue;
			}

			$normalized = [];
			foreach ($sources as $source) {
				$source = trim((string) $source);
				if ($source === '') {
					continue;
				}

				if (str_contains($source, '{nonce}')) {
					if ($nonceEnabled && $nonceSource !== null) {
						$normalized[] = str_replace('{nonce}', substr($nonceSource, 7, -1), $source);
					}
					continue;
				}

				if ($nonceEnabled && in_array($directive, ['script-src', 'style-src'], true) && $source === "'unsafe-inline'") {
					continue;
				}

				$normalized[] = $source;
			}

			if ($nonceEnabled && $nonceSource !== null && in_array($directive, ['script-src', 'style-src'], true) && !in_array($nonceSource, $normalized, true)) {
				$normalized[] = $nonceSource;
			}

			$config[$directive] = array_values(array_unique($normalized));
		}

		return $config;
	}

	private function isHttpsRequest(): bool
	{
		if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
			return true;
		}

		if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
			return true;
		}

		$forwardedProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
		return $forwardedProto === 'https';
	}
}
