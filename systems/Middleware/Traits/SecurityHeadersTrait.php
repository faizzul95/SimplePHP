<?php

namespace Middleware\Traits;

trait SecurityHeadersTrait
{
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
		$security = \config('security') ?? [];
		$headersConfig = $security['headers'] ?? [];

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

			header('Strict-Transport-Security: ' . $hstsValue);
		}

		// Content-Security-Policy (configurable via security.csp)
		$csp = $security['csp'] ?? [];
		if (!isset($csp['enabled']) || $csp['enabled'] !== false) {
			$directives = [];
			$cspDefaults = [
				'default-src' => ["'self'"],
				// 'unsafe-inline' removed from defaults; opt-in via security.csp config if truly needed.
				'script-src'  => ["'self'"],
				'style-src'   => ["'self'"],
				'img-src'     => ["'self'", 'data:'],
				'connect-src' => ["'self'"],
				'font-src'    => ["'self'"],
				'frame-ancestors' => ["'self'"],
				'base-uri'    => ["'self'"],
				'form-action' => ["'self'"],
			];

			$cspConfig = array_merge($cspDefaults, $csp);
			unset($cspConfig['enabled']);

			// Inject per-request nonce into script-src and style-src when CSP nonce
			// is enabled via security.csp.nonce_enabled. The nonce replaces the need
			// for 'unsafe-inline' on a per-element basis.
			$nonceEnabled = ($csp['nonce_enabled'] ?? false) === true;
			if ($nonceEnabled) {
				$nonce       = \Core\Security\CspNonce::get();
				$nonceSource = "'nonce-{$nonce}'";
				foreach (['script-src', 'style-src'] as $nonceDirective) {
					if (isset($cspConfig[$nonceDirective]) && is_array($cspConfig[$nonceDirective])) {
						// Remove 'unsafe-inline' when nonce is active — nonce supersedes it
						$cspConfig[$nonceDirective] = array_filter(
							$cspConfig[$nonceDirective],
							static fn($s) => $s !== "'unsafe-inline'"
						);
						$cspConfig[$nonceDirective][] = $nonceSource;
						$cspConfig[$nonceDirective] = array_values($cspConfig[$nonceDirective]);
					}
				}
			}
			unset($cspConfig['nonce_enabled']);

			foreach ($cspConfig as $directive => $sources) {
				if (is_array($sources) && !empty($sources)) {
					$directives[] = $directive . ' ' . implode(' ', $sources);
				}
			}

			if (!empty($directives)) {
				header('Content-Security-Policy: ' . implode('; ', $directives) . ';');
			}
		}

		// X-Frame-Options
		header('X-Frame-Options: ' . (string) ($headersConfig['x_frame_options'] ?? 'SAMEORIGIN'));

		// X-Content-Type-Options
		header('X-Content-Type-Options: ' . (string) ($headersConfig['x_content_type_options'] ?? 'nosniff'));

		// Referrer-Policy
		header('Referrer-Policy: ' . (string) ($headersConfig['referrer_policy'] ?? 'strict-origin-when-cross-origin'));

		// Cross-origin isolation helpers
		header('Cross-Origin-Opener-Policy: ' . (string) ($headersConfig['cross_origin_opener_policy'] ?? 'same-origin'));
		header('Cross-Origin-Resource-Policy: ' . (string) ($headersConfig['cross_origin_resource_policy'] ?? 'same-origin'));
		header('X-DNS-Prefetch-Control: ' . (string) ($headersConfig['x_dns_prefetch_control'] ?? 'off'));

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
			header('Permissions-Policy: ' . implode(', ', $policies));
		}
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
