<?php

namespace Middleware\Traits;

trait SecurityHeadersTrait
{
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
			$policies[] = $feature . '=' . $value;
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
