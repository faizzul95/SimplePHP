<?php

namespace Middleware\Traits;

trait XssProtectionTrait
{
	/**
	 * Check if the current request contains XSS payloads.
	 *
	 * @param string|array|null $ignoreXss  Fields to skip (comma-separated string or array)
	 * @return bool  true = XSS detected
	 */
	public function isXssAttack($ignoreXss = null): bool
	{
		$detected = request()->detectXss($ignoreXss);

		if ($detected) {
			$this->logXssAttempt();
		}

		// Also scan uploaded file names
		if (!$detected && !empty($_FILES)) {
			foreach ($_FILES as $file) {
				$names = is_array($file['name'] ?? null) ? $file['name'] : [$file['name'] ?? ''];
				foreach ($names as $name) {
					if (!empty($name) && $this->fileNameHasXss((string) $name)) {
						$this->logXssAttempt('Malicious file name: ' . $name);
						return true;
					}
				}
			}
		}

		return $detected;
	}

	/**
	 * Check a file name for XSS / path-traversal patterns.
	 */
	private function fileNameHasXss(string $name): bool
	{
		$lower = strtolower($name);

		// Block null-bytes, path traversal, HTML tags, event handlers
		if (preg_match('/[\x00<>]|\.\.\/|\.\.\\\\|on\w+\s*=/i', $name)) {
			return true;
		}

		// Dangerous file extensions that could execute in browser
		$dangerousExt = ['html', 'htm', 'svg', 'xml', 'xhtml', 'shtml', 'php', 'phtml', 'jsp', 'asp', 'aspx'];
		$ext = pathinfo($lower, PATHINFO_EXTENSION);
		if (in_array($ext, $dangerousExt, true)) {
			return true;
		}

		return false;
	}

	/**
	 * Log an XSS attempt with IP, URI and optional detail.
	 */
	private function logXssAttempt(string $detail = ''): void
	{
		try {
			$ip  = request()->ip();
			$uri = $_SERVER['REQUEST_URI'] ?? 'unknown';
			$msg = "[XSS BLOCKED] IP={$ip} URI={$uri}";
			if ($detail !== '') {
				$msg .= " Detail={$detail}";
			}
			logger()->log_error($msg);
		} catch (\Throwable $e) {
			// Silence — logging failure must not break the request
		}
	}
}
