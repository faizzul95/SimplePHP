<?php

namespace Middleware\Traits;

trait SecurityHeadersTrait
{
	public function set_security_headers()
	{
		$security = \config('security') ?? [];

		// Strict-Transport-Security
		header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');

		// Content-Security-Policy (configurable via security.csp)
		$csp = $security['csp'] ?? [];
		if (!isset($csp['enabled']) || $csp['enabled'] !== false) {
			$directives = [];
			$cspDefaults = [
				'default-src' => ["'self'"],
				'script-src'  => ["'self'", "'unsafe-inline'", "'unsafe-eval'"],
				'style-src'   => ["'self'", "'unsafe-inline'"],
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
		header('X-Frame-Options: SAMEORIGIN');

		// X-Content-Type-Options
		header('X-Content-Type-Options: nosniff');

		// Referrer-Policy
		header('Referrer-Policy: strict-origin-when-cross-origin');

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
}
