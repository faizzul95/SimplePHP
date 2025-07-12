<?php

// CURRENCY & MONEY HELPERS SECTION

/**
 * Format a number as a money value with a specified number of decimals.
 *
 * @param float $amount The amount to format.
 * @param int $decimal The number of decimal places to include in the formatted amount (default is 2).
 * @return string The formatted amount as a string.
 */
if (!function_exists('money_format')) {
	function money_format($amount, $decimal = 2)
	{
		return number_format((float)$amount, $decimal, '.', ',');
	}
}

/**
 * Retrieve a mapping of currency codes to their respective locale settings.
 * This function returns an array where each currency code is associated with an array
 * containing symbol, pattern, code, and decimal settings for formatting the currency.
 * 
 * @return array An associative array where currency codes are keys and their locale settings are values.
 */
if (!function_exists('getCurrencyMapping')) {
	function getCurrencyMapping()
	{
		// Map the country codes to their respective locale codes
		return array(
			'USD' => ['symbol' => '$', 'pattern' => '$ #,##0.00', 'code' => 'en_US', 'decimal' => 2], // United States Dollar (USD)
			'JPY' => ['symbol' => '¥', 'pattern' => '¥ #,##0', 'code' => 'ja_JP', 'decimal' => 2], // Japanese Yen (JPY)
			'GBP' => ['symbol' => '£', 'pattern' => '£ #,##0.00', 'code' => 'en_GB', 'decimal' => 2], // British Pound Sterling (GBP)
			'EUR' => ['symbol' => '€', 'pattern' => '€ #,##0.00', 'code' => 'en_GB', 'decimal' => 2], // Euro (EUR) - Using en_GB for Euro
			'AUD' => ['symbol' => 'A$', 'pattern' => 'A$ #,##0.00', 'code' => 'en_AU', 'decimal' => 2], // Australian Dollar (AUD)
			'CAD' => ['symbol' => 'C$', 'pattern' => 'C$ #,##0.00', 'code' => 'en_CA', 'decimal' => 2], // Canadian Dollar (CAD)
			'CHF' => ['symbol' => 'CHF', 'pattern' => 'CHF #,##0.00', 'code' => 'de_CH', 'decimal' => 2], // Swiss Franc (CHF)
			'CNY' => ['symbol' => '¥', 'pattern' => '¥ #,##0.00', 'code' => 'zh_CN', 'decimal' => 2], // Chinese Yuan (CNY)
			'SEK' => ['symbol' => 'kr', 'pattern' => 'kr #,##0.00', 'code' => 'sv_SE', 'decimal' => 2], // Swedish Krona (SEK)
			'MYR' => ['symbol' => 'RM', 'pattern' => 'RM #,##0.00', 'code' => 'ms_MY', 'decimal' => 2], // Malaysian Ringgit (MYR)
			'SGD' => ['symbol' => 'S$', 'pattern' => 'S$ #,##0.00', 'code' => 'en_SG', 'decimal' => 2], // Singapore Dollar (SGD)
			'INR' => ['symbol' => '₹', 'pattern' => '₹ #,##0.00', 'code' => 'en_IN', 'decimal' => 2], // Indian Rupee (INR)
			'IDR' => ['symbol' => 'Rp', 'pattern' => 'Rp #,##0', 'code' => 'id_ID', 'decimal' => 0], // Indonesian Rupiah (IDR)
			'THB' => ['symbol' => '฿', 'pattern' => '฿ #,##0.00', 'code' => 'th_TH', 'decimal' => 2], // Thai Baht
			'PHP' => ['symbol' => '₱', 'pattern' => '₱ #,##0.00', 'code' => 'en_PH', 'decimal' => 2], // Philippine Peso
			'KRW' => ['symbol' => '₩', 'pattern' => '₩ #,##0', 'code' => 'ko_KR', 'decimal' => 0], // South Korean Won
			'HKD' => ['symbol' => 'HK$', 'pattern' => 'HK$ #,##0.00', 'code' => 'zh_HK', 'decimal' => 2], // Hong Kong Dollar
			'NZD' => ['symbol' => 'NZ$', 'pattern' => 'NZ$ #,##0.00', 'code' => 'en_NZ', 'decimal' => 2], // New Zealand Dollar
			'BRL' => ['symbol' => 'R$', 'pattern' => 'R$ #,##0.00', 'code' => 'pt_BR', 'decimal' => 2], // Brazilian Real
			'ZAR' => ['symbol' => 'R', 'pattern' => 'R #,##0.00', 'code' => 'en_ZA', 'decimal' => 2], // South African Rand
			'SAR' => ['symbol' => '﷼', 'pattern' => '﷼ #,##0.00', 'code' => 'ar_SA', 'decimal' => 2], // Saudi Riyal
			'AED' => ['symbol' => 'د.إ', 'pattern' => 'د.إ #,##0.00', 'code' => 'ar_AE', 'decimal' => 2], // UAE Dirham
			'TRY' => ['symbol' => '₺', 'pattern' => '₺ #,##0.00', 'code' => 'tr_TR', 'decimal' => 2], // Turkish Lira
			'RUB' => ['symbol' => '₽', 'pattern' => '₽ #,##0.00', 'code' => 'ru_RU', 'decimal' => 2], // Russian Ruble
			'MXN' => ['symbol' => '$', 'pattern' => '$ #,##0.00', 'code' => 'es_MX', 'decimal' => 2], // Mexican Peso
			'PLN' => ['symbol' => 'zł', 'pattern' => 'zł #,##0.00', 'code' => 'pl_PL', 'decimal' => 2], // Polish Zloty
			'DKK' => ['symbol' => 'kr', 'pattern' => 'kr #,##0.00', 'code' => 'da_DK', 'decimal' => 2], // Danish Krone
			'NOK' => ['symbol' => 'kr', 'pattern' => 'kr #,##0.00', 'code' => 'nb_NO', 'decimal' => 2], // Norwegian Krone
			'HUF' => ['symbol' => 'Ft', 'pattern' => '#,##0.00 Ft', 'code' => 'hu_HU', 'decimal' => 2], // Hungarian Forint
			'CZK' => ['symbol' => 'Kč', 'pattern' => '#,##0.00 Kč', 'code' => 'cs_CZ', 'decimal' => 2], // Czech Koruna
			'EGP' => ['symbol' => '£', 'pattern' => '£ #,##0.00', 'code' => 'ar_EG', 'decimal' => 2], // Egyptian Pound
		);
	}
}

/**
 * Retrieve the currency symbol for a given currency code.
 *
 * This function checks if the provided currency code exists in a currency mapping
 * and returns the corresponding currency symbol. If the currency code is not found,
 * it returns an error message indicating an invalid country code.
 *
 * @param string|null $currencyCode The currency code for which to retrieve the symbol.
 * @return string The currency symbol or an error message if the code is invalid.
 */
if (!function_exists('currencySymbol')) {
	function currencySymbol($currencyCode = NULL)
	{
		$localeMap = getCurrencyMapping();

		if (!isset($localeMap[$currencyCode])) {
			return "Error: Invalid country code.";
		}

		return $localeMap[$currencyCode]['symbol'];
	}
}

/**
 * Format a given numeric value into a localized currency representation using the "intl" extension.
 *
 * @param float $value The numeric value to format as currency.
 * @param string|null $code (Optional) The country code to determine the currency format (e.g., 'USD', 'EUR', 'JPY', etc.).
 * @param bool $includeSymbol (Optional) Whether to include the currency symbol in the formatted output (default is false).
 * @return string The formatted currency value as a string or an error message if the "intl" extension is not installed or enabled.
 */
if (!function_exists('formatCurrency')) {
	function formatCurrency($value, $code, $includeSymbol = false)
	{
		// Check if the "intl" extension is installed and enabled
		if (!extension_loaded('intl')) {
			return 'Error: The "intl" extension is not installed or enabled, which is required for number formatting.';
		}

		$value = (float)($value ?: 0.0);
		$localeMap = getCurrencyMapping();
		$code = strtoupper($code);

		if (!isset($localeMap[$code])) {
			return "Error: Invalid country code.";
		}

		// Validate the $includeSymbol parameter
		if (!is_bool($includeSymbol)) {
			return "Error: \$includeSymbol parameter must be a boolean value.";
		}

		$currencyData = $localeMap[$code];

		// Create a NumberFormatter instance with the desired locale (country code)
		$formatter = new NumberFormatter($currencyData['code'], NumberFormatter::DECIMAL);
		$formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, $currencyData['decimal']); // Set fraction digits

		if ($includeSymbol) {
			$formatter->setPattern($currencyData['pattern']);
		}

		$formatVal = $formatter->formatCurrency($value, $currencyData['code']);
		if ($formatVal === false) {
			$formatVal = $formatter->formatCurrency($value, $code);
			if ($formatVal === false) {
				die(__FUNCTION__ . ' : Failed to convert currency.');
			}
		}

		// Format the currency value using the NumberFormatter
		return $formatVal;
	}
}

// ENCODE & DECODE HELPERS SECTION

if (!function_exists('encodeID')) {
	function encodeID($id, $salt = 'w3bpr0j3ct!')
	{
		// Input validation
		if (!is_numeric($id) || $id < 0) {
			return $id;
		}

		// More complex and less predictable substitution map
		static $map = [
			'0' => 'kz0#2',
			'1' => 'qZ1$5',
			'2' => 'b72!1',
			'3' => 'Xp3@8',
			'4' => 'r#469',
			'5' => 'nE5^3',
			'6' => 'uP6&4',
			'7' => 'yC7*0',
			'8' => 'fG8%6',
			'9' => 'oS9(7'
		];

		// Single transformation pass
		$encoded = strtr($id, $map);

		// Simple checksum
		$checksum = substr(md5($encoded . $salt), 0, 5);

		$permitted_chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!@#$%^&*()';
		$uniqueURL = substr(str_shuffle($permitted_chars), 0, 5);

		return encode_base64($uniqueURL . $encoded . $checksum);
	}
}

if (!function_exists('decodeID')) {
	function decodeID($encoded, $salt = 'w3bpr0j3ct!')
	{
		if (is_numeric($encoded)) {
			return $encoded;
		}

		try {
			// Base64 decode
			$decoded = decode_base64($encoded);
			if ($decoded === false || $decoded === '') {
				return false;
			}

			// Extract parts with proper validation
			if (strlen($decoded) < 10) {
				return false;
			}

			// Extract encoded part and checksum
			$value = substr($decoded, 5, -5);
			$checksum = substr($decoded, -5);

			// Verify checksum
			if ($checksum !== substr(md5($value . $salt), 0, 5)) {
				return false;
			}

			// Static reverse map matching the encodeID map exactly
			static $reverse_map = [
				'kz0#2' => '0',
				'qZ1$5' => '1',
				'b72!1' => '2',
				'Xp3@8' => '3',
				'r#469' => '4',
				'nE5^3' => '5',
				'uP6&4' => '6',
				'yC7*0' => '7',
				'fG8%6' => '8',
				'oS9(7' => '9'
			];

			// Convert back to numbers
			$result = strtr($value, $reverse_map);

			// Validate final result is numeric
			if (!is_numeric($result)) {
				return false;
			}

			return $result;
		} catch (Exception $e) {
			return false;
		}
	}
}

/**
 * Encode a string to Base64 format, with URL-safe characters.
 *
 * @param string $sData The data to encode to Base64.
 * @return string The Base64-encoded data with URL-safe characters.
 */
if (!function_exists('encode_base64')) {
	function encode_base64($sData = NULL)
	{
		if (hasData($sData)) {
			// Encode the data to Base64
			$sBase64 = base64_encode($sData);

			// Replace URL-unsafe characters (+ and /) with URL-safe characters (- and _)
			return strtr($sBase64, '+/', '-_');
		} else {
			// Return an empty string if input data is empty or not provided
			return '';
		}
	}
}

/**
 * Decode a Base64-encoded string with URL-safe characters.
 *
 * @param string $sData The Base64-encoded data with URL-safe characters.
 * @return string|bool The decoded data, or false if decoding fails.
 */
if (!function_exists('decode_base64')) {
	function decode_base64($sData = NULL)
	{
		if (hasData($sData)) {
			// Replace URL-safe characters (- and _) with Base64 characters (+ and /)
			$sBase64 = strtr($sData, '-_', '+/');

			// Decode the Base64-encoded data
			return base64_decode($sBase64);
		} else {
			// Return an empty string if input data is empty or not provided
			return '';
		}
	}
}

// ASSETS/URL/REDIRECT HELPERS SECTION

/**
 * Generate a safe base URL for the application.
 * 
 * This function creates URLs relative to the application's base URL.
 * It sanitizes input parameters to prevent XSS attacks and ensures
 * proper URL formatting.
 *
 * @param string|null $param Optional path to append to base URL
 * @return string The complete, sanitized base URL
 * @throws InvalidArgumentException If BASE_URL constant is not defined
 */
function base_url($path = null)
{
	// Ensure BASE_URL constant is defined
	if (!defined('BASE_URL')) {
		throw new InvalidArgumentException('BASE_URL constant must be defined');
	}

	// Return base URL if no parameter provided
	if ($path === null || $path === '') {
		return rtrim(BASE_URL, '/') . '/';
	}

	// Ensure $path is a string
	$param = (string)$path;

	// Remove null bytes and control characters
	$param = preg_replace('/[\x00-\x1F\x7F]/u', '', $param);

	// Prevent directory traversal
	if (strpos($param, '..') !== false) {
		throw new InvalidArgumentException('Path cannot contain directory traversal sequences');
	}

	// Sanitize the parameter to prevent XSS
	$param = filter_var($param, FILTER_SANITIZE_URL);

	// Remove any potentially dangerous characters
	$param = preg_replace('/[<>"\'`]/', '', $param);

	// Remove leading slashes to avoid double slashes
	$param = ltrim($param, '/');

	// Combine base URL with parameter
	$url = rtrim(BASE_URL, '/') . '/' . $param;

	// Optionally encode for HTML output
	return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
}

/**
 * Generate a safe asset URL for static resources.
 *
 * This function creates URLs for static assets like CSS, JavaScript, images,
 * and other resources. It provides security by sanitizing input and proper
 * path handling to prevent directory traversal attacks.
 *
 * @param string $param The asset path (e.g., 'css/style.css', 'js/app.js')
 * @param bool $public Whether the asset is in the public directory (default: true)
 * @return string The complete, sanitized asset URL
 * @throws InvalidArgumentException If the asset path is empty or contains invalid characters
 * 
 * @example
 * // Generate URL for a CSS file in public directory
 * echo asset('css/bootstrap.min.css'); // Output: http://example.com/public/css/bootstrap.min.css
 * 
 * // Generate URL for a file outside public directory
 * echo asset('uploads/document.pdf', false); // Output: http://example.com/uploads/document.pdf
 */
if (!function_exists('asset')) {
	function asset($param, $public = true)
	{
		// Validate input parameter
		if (empty($param) || !is_string($param)) {
			throw new InvalidArgumentException('Asset path cannot be empty and must be a string');
		}

		// Sanitize the asset path
		$param = filter_var($param, FILTER_SANITIZE_URL);

		// Prevent directory traversal attacks
		if (strpos($param, '..') !== false) {
			throw new InvalidArgumentException('Asset path cannot contain directory traversal sequences');
		}

		// Ensure proper path formatting (remove leading slash)
		$param = ltrim($param, '/');

		// Determine the public directory based on the $public parameter
		$directory = $public ? 'public/' : '';

		// Return the complete asset URL using the safe base_url function
		return base_url($directory . $param);
	}
}

/**
 * Redirect to a different URL.
 *
 * @param string $path The path to redirect to.
 * @param bool $permanent Whether the redirect is permanent (301) or temporary (302).
 */
if (!function_exists('redirect')) {
	function redirect($path, $permanent = false)
	{
		// Perform the redirection and exit.
		header('Location: ' . url($path), true, $permanent ? 301 : 302);
		exit();
	}
}

/**
 * Generate a URL with proper encoding and sanitization.
 *
 * @param string $param The URL path.
 * @return string The complete URL.
 */
if (!function_exists('url')) {
	function url($param)
	{
		// Ensure $param is a string and not null
		$param = $param !== null ? (string)$param : '';

		// HTML-encode the URL parameter.
		$param = htmlspecialchars($param, ENT_NOQUOTES, 'UTF-8');

		// Return the complete URL with sanitized parameters.
		return base_url($param);
	}
}

/**
 * Add or update URL parameters to the current URL
 * 
 * @param string|array $params Parameters to add/update
 * @param bool $resetParam Whether to reset all existing parameters (default: false)
 * @return string Modified URL with parameters
 * @throws InvalidArgumentException When invalid parameter types are provided
 */
if (!function_exists('paramUrl')) {
	function paramUrl($params, $resetParam = false)
	{
		$url = $_SERVER['REQUEST_URI'] ?? '';

		// Enhanced URL sanitization to prevent XSS
		$url = filter_var($url, FILTER_SANITIZE_URL);

		// Additional security: Remove any potential script tags or javascript protocols
		$url = preg_replace('/javascript:/i', '', $url);
		$url = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $url);

		// Validate URL structure
		if (!$url || strlen($url) > 2048) { // Reasonable URL length limit
			throw new InvalidArgumentException('Invalid or excessively long URL');
		}

		// Parse the URL to separate path and query string
		$urlParts = parse_url($url);

		if ($urlParts === false) {
			throw new InvalidArgumentException('Malformed URL');
		}

		$path = $urlParts['path'] ?? '';
		$query = $urlParts['query'] ?? '';
		$fragment = isset($urlParts['fragment']) ? '#' . $urlParts['fragment'] : '';

		// Sanitize path component
		$path = filter_var($path, FILTER_SANITIZE_URL);

		// Internal function to sanitize parameters
		$sanitizeParams = function ($params) use (&$sanitizeParams) {
			$sanitized = [];

			foreach ($params as $key => $value) {
				// Sanitize parameter keys 
				$cleanKey = trim($key);
				$cleanKey = preg_replace('/[^\w\-_\.]/', '', $cleanKey);
				$cleanKey = substr($cleanKey, 0, 100); // Limit key length

				// Accept key '0' as valid
				if ($cleanKey === '' && $cleanKey !== '0') {
					continue; // Skip invalid keys except '0'
				}

				if (is_array($value)) {
					// Recursively sanitize array values
					$sanitized[$cleanKey] = $sanitizeParams($value);
				} elseif (is_null($value)) {
					// Explicitly include null values as empty string
					$sanitized[$cleanKey] = '';
				} else {
					// Sanitize individual values
					$value = (string) $value;

					// Limit value length to prevent abuse
					if (strlen($value) > 1000) {
						continue; // Skip this parameter
					}

					// Remove potentially dangerous characters and patterns
					$value = preg_replace('/[<>"\']/', '', $value);
					$value = preg_replace('/javascript:/i', '', $value);
					$value = preg_replace('/on\w+\s*= /i', '', $value);
					$value = preg_replace('/data:\s*[^;]*;base64/i', '', $value);
					$value = preg_replace('/vbscript:/i', '', $value);
					$value = preg_replace('/expression\s*\(/i', '', $value);

					// Remove control characters (modern replacement for FILTER_FLAG_STRIP_LOW)
					$value = preg_replace('/[\x00-\x1F\x7F]/', '', $value);

					// HTML entity encode for extra safety
					$cleanValue = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

					// Final validation - ensure it's not empty after sanitization
					// Accept value '0' as valid
					if ($cleanValue !== '' && $cleanValue !== false && $cleanValue !== null) {
						$sanitized[$cleanKey] = $cleanValue;
					}
				}
			}

			return $sanitized;
		};

		// Parse existing query parameters (only if not resetting)
		$existingParams = [];
		if (!$resetParam && !empty($query)) {
			parse_str($query, $existingParams);
			// Sanitize existing parameters
			$existingParams = $sanitizeParams($existingParams);
		}

		// Handle different parameter input types
		$newParams = [];

		if (is_string($params)) {
			// Validate string input for potential XSS
			if (strlen($params) > 2048) {
				throw new InvalidArgumentException('Parameter string too long');
			}

			// Parse string parameters (e.g., "id=20&status=ACTV")
			parse_str($params, $newParams);
		} elseif (is_array($params)) {
			// Use array directly
			$newParams = $params;
		} else {
			// Invalid parameter type
			throw new InvalidArgumentException('Parameters must be string or array');
		}

		// Sanitize new parameters
		$newParams = $sanitizeParams($newParams);

		// Merge parameters based on resetParam flag
		if ($resetParam) {
			// Only use new parameters, ignore existing ones
			$mergedParams = $newParams;
		} else {
			// Merge existing parameters with new ones (new ones override existing)
			$mergedParams = array_merge($existingParams, $newParams);
		}

		// Build the new query string
		$newQuery = http_build_query($mergedParams, '', '&', PHP_QUERY_RFC3986);

		// Construct the final URL
		$finalUrl = $path;
		if (!empty($newQuery)) {
			$finalUrl .= '?' . $newQuery;
		}

		// Sanitize fragment before adding
		if (!empty($fragment)) {
			$fragment = filter_var($fragment, FILTER_SANITIZE_URL);
			$finalUrl .= $fragment;
		}

		return $finalUrl;
	}
}

/**
 * Create a new instance of a class from a given namespace.
 *
 * @param string $namespace  The fully-qualified class namespace.
 * @return object            An instance of the specified class.
 * @throws Exception        If the class or method does not exist.
 */
if (!function_exists('app')) {
	function app($namespace)
	{
		return new class($namespace)
		{
			private $namespace;
			private $obj;

			public function __construct($namespace)
			{
				$this->namespace = $namespace;
				$this->obj = new $namespace();
			}

			public function __call($method, $args)
			{
				if (method_exists($this->obj, $method)) {
					return $this->obj->$method(...$args);
				}

				throw new Exception("Method $method does not exist");
			}
		};
	}
}

// GENERAL HELPERS SECTION

/**
 * Check if the request was made via AJAX.
 *
 * @return bool Returns true if the request is an AJAX request, false otherwise.
 */
if (!function_exists('isAjax')) {
	function isAjax()
	{
		return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
	}
}

/**
 * Check if the user agent corresponds to a mobile device.
 *
 * @return bool True if it's a mobile device, false otherwise.
 */
if (!function_exists('isMobileDevice')) {
	function isMobileDevice()
	{
		// Check if the HTTP_USER_AGENT server variable is not empty.
		if (!empty($_SERVER['HTTP_USER_AGENT'])) {
			// Use a regular expression to match common mobile device keywords. This pattern is case-insensitive ('i' flag).
			$pattern = "/(android|webos|avantgo|iphone|ipad|ipod|blackberry|iemobile|bolt|boost|cricket|docomo|fone|hiptop|mini|opera mini|kitkat|mobi|palm|phone|pie|tablet|up\.browser|up\.link|webos|wos)/i";

			// Use preg_match to check if the pattern matches the user agent string.
			return preg_match($pattern, $_SERVER["HTTP_USER_AGENT"]);
		}

		// If HTTP_USER_AGENT is empty, it's not a mobile device.
		return false;
	}
}

/**
 * Generate the next running number with optional prefix, suffix, separator, and leading zeros.
 *
 * @param int|null           $currentNo Current running number.
 * @param string|null   $prefix Prefix for the running number.
 * @param string|null   $separatorPrefix Separator for Prefix the running number.
 * @param string|null   $suffix Suffix for the running number.
 * @param string|null   $separatorSuffix Separator for Suffix the running number.
 * @param string|null   $separator Separator between prefix/suffix and the number.
 * @param int           $leadingZero Number of leading zeros for the running number.
 * 
 * @return array Associative array containing the generated code and the next number.
 */
if (!function_exists('genRunningNo')) {
	function genRunningNo($currentNo = NULL, $prefix = NULL, $separatorPrefix = NULL, $suffix = NULL, $separatorSuffix = NULL, $leadingZero = 5)
	{
		// Calculate the next running number
		$nextNo = empty($currentNo) ? 1 : (int)$currentNo + 1;

		// Construct prefix and suffix with optional separators
		$pref = empty($separatorPrefix) ? $prefix : (!empty($prefix) ? $prefix . $separatorPrefix : NULL);
		$suf = !empty($suffix) ? (empty($separatorSuffix) ? $suffix : $separatorSuffix . $suffix) : NULL;

		// Generate the code with leading zeros and return the result as an array
		return [
			'code' => $pref . str_pad($nextNo, $leadingZero, '0', STR_PAD_LEFT) . $suf,
			'next' => $nextNo
		];
	}
}

/**
 * Generates a unique code based on a given string and a list of existing codes.
 *
 * @param string $string The input string used to generate the code.
 * @param array $codeList An array containing existing codes to avoid duplicates.
 * @param string $codeType The code type prefix.
 * @param int $codeLength The length of the code generated from the string.
 * @param int $numLength The length of the numerical part of the code.
 * @param int $counter The starting number for generating unique codes.
 * @return string The generated unique code.
 */
if (!function_exists('genCodeByString')) {
	function genCodeByString($string, $codeList = array(), $codeType = 'S', $codeLength = 4, $numLength = 4, $counter = 1)
	{
		$code = '';

		// Convert the string to uppercase and split it into an array of words
		$nameArr = explode(' ', strtoupper($string));

		// Array to keep track of the current index of each word in the string
		$wordIdx = array();
		$word = 0;

		// Generate the code by taking characters from the input string based on the specified length
		while ($codeLength != strlen($code)) {
			// Wrap around the words if the code length is longer than the string
			if ($word >= count($nameArr)) {
				$word = 0;
			}

			// Initialize the word index if it's not set
			if (!isset($wordIdx[$word])) {
				$wordIdx[$word] = 0;
			}

			// Wrap around the characters in a word if the code length is longer than the word
			if ($wordIdx[$word] >= strlen($nameArr[$word])) {
				$wordIdx[$word] = 0;
			}

			// Append the character from the current word to the code
			$code .= $nameArr[$word][$wordIdx[$word]];

			// Move to the next character in the word
			$wordIdx[$word]++;
			$word++;
		}

		// If a list of existing codes is provided, ensure the generated code is unique
		if (hasData($codeList)) {
			$found = false;
			while (!$found) {
				$tempcode = $codeType . $code . str_pad($counter, $numLength, '0', STR_PAD_LEFT);

				// Check if the tempcode exists in the code list
				if (!in_array($tempcode, $codeList)) {
					$code = $tempcode;
					$found = true;
				}

				// Increment the counter to generate a new unique code if the current one already exists
				$counter++;
			}
		}

		return $code;
	}
}

/**
 * Truncates a given string to a specified length and appends a suffix if needed.
 *
 * @param string $string The input string to truncate.
 * @param int $length The maximum length of the truncated string.
 * @param string $suffix The suffix to append at the end of the truncated string.
 * @return string|null The truncated string or NULL if the input string is empty.
 */
if (!function_exists('truncateText')) {
	function truncateText($string, $length = 10, $suffix = '...')
	{
		$truncated = NULL;

		// Check if the input string has data (i.e., not empty or NULL)
		if (hasData($string)) {
			// If the string is shorter than or equal to the maximum length, return the string as is
			if (strlen($string) <= $length) {
				return $string;
			}

			// Truncate the string to the specified length
			$truncated = substr($string, 0, $length);

			// If the truncated string ends with a space, remove the space
			if (substr($truncated, -1) == ' ') {
				$truncated = substr($truncated, 0, -1);
			}

			// Append the suffix to the truncated string
			$truncated .= $suffix;
		}

		return $truncated;
	}
}

/**
 * Recursively delete a folder and its contents, excluding specified files.
 *
 * @param string $folder         The path to the folder to delete.
 * @param array  $excludedFiles  An array of files to exclude from deletion.
 * @return void
 */
if (!function_exists('deleteFolder')) {
	function deleteFolder($folder, $excludedFiles = [])
	{
		// Define the default files to exclude
		$defaultExcludedFiles = ['index.html', '.htaccess'];

		// Merge the default and user-defined excluded files
		$excFile = array_merge($defaultExcludedFiles, $excludedFiles);

		if (is_dir($folder)) {
			// Get a list of files and subdirectories in the folder
			$files = scandir($folder);

			foreach ($files as $file) {
				if ($file != '.' && $file != '..' && !in_array($file, $excFile)) {
					$filePath = $folder . DIRECTORY_SEPARATOR . $file;

					if (is_dir($filePath)) {
						// Recursively delete subdirectories
						deleteFolder($filePath, $excFile);
					} else {
						// Delete files
						unlink($filePath);
					}
				}
			}

			// Check if the folder is empty, then remove it
			if (count(glob("$folder/*")) === 0) {
				rmdir($folder);
			}
		}
	}
}

// PAGE ERROR (NODATA) HELPER

if (!function_exists('nodata')) {
	function nodata($showText = true, $filesName = '5.png')
	{
		echo "<div id='nodata' class='col-lg-12 mb-4 mt-2'>
		  <center>
			<img src='" . url('public/general/images/nodata/' . $filesName) . "' class='img-fluid mb-3' width='38%'>
			<h4 style='letter-spacing :2px; font-family: Quicksand, sans-serif !important;margin-bottom:15px'> 
			 <strong> NO INFORMATION FOUND </strong>
			</h4>";
		if ($showText) {
			echo "<h6 style='letter-spacing :2px; font-family: Quicksand, sans-serif !important;font-size: 13px;'> 
				Here are some action suggestions for you to try :- 
			</h6>";
		}
		echo "</center>";
		if ($showText) {
			echo "<div class='row d-flex justify-content-center w-100'>
			<div class='col-lg m-1 text-left' style='max-width: 350px !important;letter-spacing :1px; font-family: Quicksand, sans-serif !important;font-size: 12px;'>
			  1. Try the registrar function (if any).<br>
			  2. Change your word or search selection.<br>
			  3. Contact the system support immediately.<br>
			</div>
		  </div>";
		}
		echo "</div>";
	}
}

if (!function_exists('nodataAccess')) {
	function nodataAccess($filesName = '403.png')
	{
		echo "<div id='nodata' class='col-lg-12 mb-4 mt-2'>
		  <center>
			<img src='" . url('public/general/images/nodata/' . $filesName) . "' class='img-fluid mb-2' width='30%'>
			<h3 style='letter-spacing :2px; font-family: Quicksand, sans-serif !important;margin-bottom:15px'> 
			 <strong> NO ACCESS TO THIS INFORMATION </strong>
			</h3>";
		echo "</center>";
		echo "</div>";
	}
}
