<?php

namespace Components;

class Request
{
    protected static $data;
    protected static $files;
    protected $secureInput = true;

    /**
     * Constructor
     * 
     * Initializes the Request object and processes input data
     */
    public function __construct()
    {
        self::$data = $this->sanitizeInput(array_merge(
            $_GET,
            $_POST,
            $this->getInputStreamData()
        ));
        self::$files = $this->processUploadedFiles($_FILES);
    }

    /**
     * Get input stream data for PUT/PATCH requests
     * 
     * @return array
     */
    private function getInputStreamData()
    {
        if (in_array($this->method(), ['PUT', 'PATCH', 'DELETE'])) {
            $input = file_get_contents('php://input');
            $data = [];

            // Try to parse as JSON first
            $json = json_decode($input, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $data = $json;
            } else {
                // Parse as form data
                parse_str($input, $data);
            }

            return $data;
        }

        return [];
    }

    /**
     * Disable input sanitization
     * 
     * @return $this
     */
    public function unsafe()
    {
        $this->secureInput = false;
        self::$data = array_merge(
            $_GET,
            $_POST,
            $this->getInputStreamData()
        );
        return $this;
    }

    /**
     * Sanitize input data recursively
     * 
     * @param mixed $input
     * @return mixed
     */
    private function sanitizeInput($input)
    {
        if (!$this->secureInput) {
            return $input;
        }

        if (is_array($input)) {
            foreach ($input as $key => $value) {
                $input[$key] = $this->sanitizeInput($value);
            }
        } else {
            // Basic XSS protection - remove script tags and dangerous attributes
            $input = $this->xssClean($input);
        }

        return $input;
    }

    /**
     * Basic XSS cleaning function
     * 
     * @param string $str
     * @return string
     */
    private function xssClean($str)
    {
        // Handle null, empty, or non-string values
        if (empty($str) || !is_string($str)) {
             return $str;
        }

        // Remove null bytes and other control characters
        $str = str_replace([chr(0), chr(1), chr(2), chr(3), chr(4), chr(5), chr(6), chr(7), chr(8), chr(11), chr(12), chr(14), chr(15), chr(16), chr(17), chr(18), chr(19), chr(20), chr(21), chr(22), chr(23), chr(24), chr(25), chr(26), chr(27), chr(28), chr(29), chr(30), chr(31)], '', $str);

        // Decode HTML entities to catch encoded attacks
        $str = html_entity_decode($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Remove dangerous tags using strip_tags with allowed tags (none allowed here)
        $str = strip_tags($str);

        // Remove event handlers and javascript: in attributes
        $str = preg_replace('/on\w+\s*=\s*"[^"]*"/i', '', $str);
        $str = preg_replace('/on\w+\s*=\s*\'[^\']*\'/i', '', $str);
        $str = preg_replace('/on\w+\s*=\s*[^\s>]+/i', '', $str);
        $str = preg_replace('/javascript\s*:/i', '', $str);

        // Remove CSS expressions and imports
        $str = preg_replace('/expression\s*\(.*\)/i', '', $str);
        $str = preg_replace('/@import\s+[\'"][^\'"]+[\'"]/i', '', $str);

        // Remove potentially dangerous HTML tags (comprehensive list)
        $dangerous_tags = [
            'script', 'object', 'embed', 'link', 'style', 'img', 'video', 'audio',
            'iframe', 'frame', 'frameset', 'applet', 'form', 'input', 'button',
            'textarea', 'select', 'option', 'meta', 'base', 'bgsound', 'blink',
            'body', 'head', 'html', 'title', 'xml', 'xmp', 'svg', 'math',
            'details', 'summary', 'menuitem', 'source', 'track', 'canvas',
            'marquee', 'plaintext', 'listing', 'basefont', 'spacer', 'noframes', 
            'noscript', 'noembed', 'param', 'object', 'fieldset', 'legend', 'output', 
            'datalist', 'keygen', 'command', 'dialog', 'template', 'picture', 'map', 
            'area', 'colgroup', 'col', 'caption', 'del', 'ins', 'acronym', 'abbr', 
            'big', 'cite', 'code', 'dfn', 'kbd', 'samp', 'sub', 'sup', 'tt', 'u', 'var'
        ];

        foreach ($dangerous_tags as $tag) {
            $str = preg_replace('#<\s*' . $tag . '[^>]*>.*?<\s*/\s*' . $tag . '\s*>#is', '', $str);
            $str = preg_replace('#<\s*' . $tag . '[^>]*/?>#is', '', $str);
        }

        // Remove all event handlers (comprehensive list)
        $event_handlers = [
            'onload', 'onerror', 'onclick', 'onmouseover', 'onmouseout', 'onmousedown',
            'onmouseup', 'onmousemove', 'onkeypress', 'onkeydown', 'onkeyup',
            'onblur', 'onfocus', 'onchange', 'onsubmit', 'onreset', 'onselect',
            'onabort', 'ondblclick', 'ondragdrop', 'onmove', 'onresize',
            'onactivate', 'onafterprint', 'onafterupdate', 'onbeforeactivate',
            'onbeforecopy', 'onbeforecut', 'onbeforedeactivate', 'onbeforeeditfocus',
            'onbeforepaste', 'onbeforeprint', 'onbeforeunload', 'onbeforeupdate',
            'oncontextmenu', 'oncontrolselect', 'oncopy', 'oncut', 'ondrag',
            'ondragend', 'ondragenter', 'ondragleave', 'ondragover', 'ondragstart',
            'ondrop', 'onerrorupdate', 'onfilterchange', 'onfinish', 'onfocusin',
            'onfocusout', 'onhelp', 'onlosecapture', 'onmoveend', 'onmovestart',
            'onpaste', 'onpropertychange', 'onreadystatechange', 'onresizeend',
            'onresizestart', 'onrowenter', 'onrowexit', 'onrowsdelete', 'onrowsinserted',
            'onscroll', 'onstart', 'onstop', 'onunload', 'ontouchstart', 'ontouchend',
            'ontouchmove', 'ontouchcancel', 'onpointerdown', 'onpointerup',
            'onpointermove', 'onpointerover', 'onpointerout', 'onpointerenter',
            'onpointerleave', 'onpointercancel', 'onanimationstart', 'onanimationend',
            'onanimationiteration', 'ontransitionend',
            // Additional HTML5 and ARIA event handlers
            'onwheel', 'oninput', 'oninvalid', 'onsearch', 'onbeforeinput', 'onselectstart',
            'onselectionchange', 'onshow', 'ontoggle', 'onsubmit', 'onratechange',
            'onvolumechange', 'onwaiting', 'oncanplay', 'oncanplaythrough', 'ondurationchange',
            'onemptied', 'onended', 'onloadeddata', 'onloadedmetadata', 'onloadstart',
            'onpause', 'onplay', 'onplaying', 'onprogress', 'onstalled', 'onsuspend',
            'ontimeupdate', 'onseeking', 'onseeked', 'oncuechange', 'onformdata',
            'onclose', 'onopen', 'onmessage', 'onmousewheel', 'onstorage', 'onpopstate',
            'onhashchange', 'onpageshow', 'onpagehide', 'onafterprint', 'onbeforeprint',
            'onbeforeunload', 'onorientationchange', 'ondeviceorientation', 'ondevicemotion',
            'ondeviceproximity', 'onuserproximity', 'onpointerlockchange', 'onpointerlockerror',
            'onfullscreenchange', 'onfullscreenerror', 'onwebkitfullscreenchange', 'onwebkitfullscreenerror',
            'onmsfullscreenchange', 'onmsfullscreenerror', 'onmozfullscreenchange', 'onmozfullscreenerror',
            // ARIA live region events
            'onariarequest', 'onariaresponse', 'onariainvalid', 'onariaactive',
            // Misc
            'onbeforescriptexecute', 'onafterscriptexecute', 'onbeforecopy', 'onbeforecut', 'onbeforepaste'
        ];

        foreach ($event_handlers as $handler) {
            $str = preg_replace('#\s*' . $handler . '\s*=\s*["\'][^"\']*["\']#i', '', $str);
            $str = preg_replace('#\s*' . $handler . '\s*=\s*[^>\s]*#i', '', $str);
        }

        // Remove dangerous protocols (more comprehensive)
        $dangerous_protocols = [
            'javascript', 'vbscript', 'jscript', 'data', 'about', 'mocha', 'livescript',
            'behavior', 'mhtml', 'file', 'chrome', 'chrome-extension', 'resource',
            'opera', 'opera-extension', 'ms-help', 'ms-settings', 'ms-appx', 'ms-appdata',
            'x-schema', 'wss', 'ws', 'ftp', 'telnet', 'irc', 'irc6', 'ircs', 'git',
            'ssh', 'sftp', 'blob', 'filesystem', 'mailto', 'cid', 'mid', 'sms', 'tel'
        ];

        foreach ($dangerous_protocols as $protocol) {
            $str = preg_replace('#\s*' . $protocol . '\s*:#i', '', $str);
        }

        // Remove expression() CSS attacks
        $str = preg_replace('#expression\s*\(#i', '', $str);
        $str = preg_replace('#-moz-binding\s*:#i', '', $str);

        // Remove import statements that could load external stylesheets
        $str = preg_replace('#@import\s+["\']?[^"\']*["\']?#i', '', $str);

        // Remove CSS url() that could contain javascript
        $str = preg_replace('#url\s*\(\s*["\']?\s*javascript\s*:#i', '', $str);

        // Remove HTML comments that might contain malicious code
        $str = preg_replace('#<!--.*?-->#s', '', $str);

        // Remove CDATA sections
        $str = preg_replace('#<!\[CDATA\[.*?\]\]>#s', '', $str);

        // Remove backslashes that might be used for escaping
        $str = str_replace('\\', '', $str);

        // Additional cleaning for specific attack vectors
        $str = preg_replace('#<\s*/?[a-z]+[^>]*>#i', '', $str); // Remove any remaining HTML tags

        // Remove potential FSCommand or other Flash-related attacks
        $str = preg_replace('#fscommand#i', '', $str);
        $str = preg_replace('#allowscriptaccess#i', '', $str);

        // Clean up multiple spaces and normalize
        $str = preg_replace('#\s+#', ' ', $str);

        // Encode special HTML characters to prevent XSS
        $str = htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim($str);
    }

    /**
     * Process uploaded files
     * 
     * @param array $files
     * @return array
     */
    private function processUploadedFiles($files)
    {
        $processed = [];
        foreach ($files as $key => $file) {
            if (is_array($file['name'])) {
                $processed[$key] = [];
                for ($i = 0; $i < count($file['name']); $i++) {
                    $processed[$key][] = [
                        'name' => $this->sanitizeInput($file['name'][$i]),
                        'type' => $file['type'][$i],
                        'tmp_name' => $file['tmp_name'][$i],
                        'error' => $file['error'][$i],
                        'size' => $file['size'][$i]
                    ];
                }
            } else {
                $processed[$key] = [
                    'name' => $this->sanitizeInput($file['name']),
                    'type' => $file['type'],
                    'tmp_name' => $file['tmp_name'],
                    'error' => $file['error'],
                    'size' => $file['size']
                ];
            }
        }
        return $processed;
    }

    /**
     * Get all input data
     * 
     * @return array All input data
     */
    public function all()
    {
        return !empty(self::$files) ? array_merge(self::$data, ['files' => self::$files]) : self::$data;
    }

    /**
     * Get a specific input item
     * 
     * @param string $key The key of the input item
     * @param mixed $default The default value if the key doesn't exist
     * @return mixed The value of the input item or the default value
     */
    public function input($key, $default = null)
    {
        // If no segments provided, just check if the data contains the key directly
        if (strpos($key, '.') === false) {
            return self::$data[$key] ?? $default;
        }

        // Split the key by dots to handle nested arrays
        $segments = explode('.', $key);
        $data = self::$data;

        foreach ($segments as $segment) {
            // If the segment is an asterisk, replace it with a regex wildcard
            if ($segment === '*') {
                $wildcardData = [];
                foreach ($data as $item) {
                    if (is_array($item)) {
                        $wildcardData = array_merge($wildcardData, $item);
                    }
                }
                $data = $wildcardData;
            } else if (isset($data[$segment])) {
                $data = $data[$segment]; // If the segment exists, go deeper
            } else {
                // If the segment doesn't exist, return the default value
                return $default;
            }
        }

        return $data ?? $default;
    }

    /**
     * Get information about uploaded files
     * 
     * @param string|null $key
     * @return array|null
     */
    public function files($key = null)
    {
        if ($key === null) {
            return self::$files;
        }

        return self::$files[$key] ?? null;
    }

    /**
     * Check if an input item exists
     * 
     * @param string $key The key of the input item
     * @return bool True if the item exists, false otherwise
     */
    public function has($key)
    {
        return isset(self::$data[$key]);
    }

    /**
     * Get only specified input items
     * 
     * @param array|string $keys The keys to retrieve
     * @return array The specified input items
     */
    public function only($keys)
    {
        if (!is_array($keys) && !is_string($keys)) {
            throw new \InvalidArgumentException('Parameter $keys must be an array or a string.');
        }

        $keys = is_array($keys) ? $keys : func_get_args();
        $result = array_intersect_key(self::$data, array_flip($keys));
        foreach ($keys as $key) {
            if (isset(self::$files[$key])) {
                $result[$key] = self::$files[$key];
            }
        }
        return $result;
    }

    /**
     * Get all input items except the specified ones
     * 
     * @param array|string $keys The keys to exclude
     * @return array All input items except the specified ones
     */
    public function except($keys)
    {
        if (!is_array($keys) && !is_string($keys)) {
            throw new \InvalidArgumentException('Parameter $keys must be an array or a string.');
        }

        $keys = is_array($keys) ? $keys : func_get_args();
        $result = array_diff_key(self::$data, array_flip($keys));
        foreach (self::$files as $key => $value) {
            if (!in_array($key, $keys)) {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    /**
     * Retrieve a header from the request
     *
     * @param string $key The header key
     * @param mixed $default The default value if header does not exist
     * @return mixed The header value
     */
    public static function header($key, $default = null)
    {
        // Convert header name to HTTP_* format
        $headerKey = 'HTTP_' . strtoupper(str_replace('-', '_', $key));

        // Check various possible header formats
        $headers = [
            $headerKey,
            strtoupper($key),
            strtolower($key),
            ucfirst(strtolower($key))
        ];

        foreach ($headers as $header) {
            if (isset($_SERVER[$header])) {
                return $_SERVER[$header];
            }
        }

        // Check getallheaders() if available
        if (function_exists('getallheaders')) {
            $allHeaders = getallheaders();
            foreach ($allHeaders as $name => $value) {
                if (strtolower($name) === strtolower($key)) {
                    return $value;
                }
            }
        }

        return $default;
    }

    /**
     * Check if the request has a specific header
     *
     * @param string $key The header key
     * @return bool True if header exists, false otherwise
     */
    public static function hasHeader($key)
    {
        return self::header($key) !== null;
    }

    /**
     * Determine if the request is via AJAX
     * 
     * @return bool True if the request is via AJAX, false otherwise
     */
    public function ajax()
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    /**
     * Get the request method
     * 
     * @return string The request method (GET, POST, etc.)
     */
    public function method()
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /**
     * Check if the request method is GET
     * 
     * @return bool True if the method is GET, false otherwise
     */
    public function isGet()
    {
        return $this->method() === 'GET';
    }

    /**
     * Check if the request method is POST
     * 
     * @return bool True if the method is POST, false otherwise
     */
    public function isPost()
    {
        return $this->method() === 'POST';
    }

    /**
     * Get the request URI
     * 
     * @return string The request URI
     */
    public function uri()
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        // Remove query string
        if (($pos = strpos($uri, '?')) !== false) {
            $uri = substr($uri, 0, $pos);
        }

        return ltrim($uri, '/');
    }

    /**
     * Get a all part of the request URL by index
     * 
     * @return array The URL part or null if not found
     */
    public function AllSegments()
    {
        $uri = $this->uri();
        $parts = explode('/', trim($uri, '/'));
        return $parts;
    }

    /**
     * Get a specific part of the request URL by index
     * 
     * @param int $index The index of the URL part (0-based)
     * @return string|null The URL part or null if not found
     */
    public function segment($index = 0)
    {
        $uri = $this->uri();
        $parts = explode('/', trim($uri, '/'));
        return $parts[$index] ?? null;
    }

    /**
     * Get the request URL
     * 
     * @return string The full request URL
     */
    public function url()
    {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        $uri = $this->uri();

        return $protocol . '://' . $host . '/' . $uri;
    }

    /**
     * Get the full request URL with query string
     * 
     * @return string The full request URL with query string
     */
    public function fullUrl()
    {
        $query_string = $_SERVER['QUERY_STRING'] ?? '';
        return $this->url() . ($query_string ? '?' . $query_string : '');
    }

    /**
     * Get the IP address of the request
     * 
     * @return string The IP address
     */
    public function ip()
    {
        // Define trusted IP headers in priority order
		$ipKeys = [
			'HTTP_CF_CONNECTING_IP',     // Cloudflare
			'HTTP_CLIENT_IP',            // Shared internet
			'HTTP_X_FORWARDED_FOR',      // Most common proxy header
			'HTTP_X_FORWARDED',          // Alternative proxy header
			'HTTP_X_CLUSTER_CLIENT_IP',  // Cluster environments
			'HTTP_FORWARDED_FOR',        // Legacy proxy header
			'HTTP_FORWARDED',            // RFC 7239 standard
			'REMOTE_ADDR'                // Direct connection (always available)
		];

		foreach ($ipKeys as $key) {
			if (array_key_exists($key, $_SERVER) && !empty($_SERVER[$key])) {
				// Handle comma-separated IP lists (common with X-Forwarded-For)
				$ips = explode(',', $_SERVER[$key]);

				foreach ($ips as $ip) {
					// Sanitize the IP address
					$ip = trim($ip);

					// Additional security: Remove any non-IP characters
					$ip = preg_replace('/[^0-9a-fA-F:.]/', '', $ip);

					// Validate IP format and exclude private/reserved ranges
					if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
						// Additional IPv4 validation for common spoofing attempts
						if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
							// Exclude additional ranges that might be problematic
							$parts = explode('.', $ip);
							if (count($parts) === 4) {
								$first = (int)$parts[0];
								// Exclude additional suspicious ranges
								if ($first === 0 || $first === 169 || $first >= 224) {
									continue;
								}
							}
						}

						return $ip;
					}
				}
			}
		}

		// Fallback to REMOTE_ADDR with validation
		$fallbackIP = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

		// Validate fallback IP
		if (filter_var($fallbackIP, FILTER_VALIDATE_IP) !== false) {
			return $fallbackIP;
		}

		// Ultimate fallback
		return '0.0.0.0';
    }

    /**
     * Get the user agent string
     * 
     * @return string The user agent string
     */
    public function userAgent()
    {
       // Check if User Agent header exists
        if (!isset($_SERVER['HTTP_USER_AGENT']) || empty($_SERVER['HTTP_USER_AGENT'])) {
            return 'Unknown';
        }
        
        // Get and sanitize the User Agent string
        $userAgent = trim($_SERVER['HTTP_USER_AGENT']);
        
        // Security: Limit length to prevent memory issues (max 1000 characters)
        if (strlen($userAgent) > 1000) {
            $userAgent = substr($userAgent, 0, 1000);
        }
        
        // Security: Remove null bytes and control characters that could cause issues
        $userAgent = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $userAgent);
        
        // Additional security: Remove potentially dangerous characters
        $userAgent = str_replace(["\n", "\r", "\t"], '', $userAgent);
        
        // Return sanitized User Agent or fallback
        return !empty($userAgent) ? $userAgent : 'Unknown';
    }

    /**
     * Get the operating system from user agent
     * 
     * @return string The operating system name
     */
    public function platform()
    {
        $userAgent = $this->userAgent();
        
        // Return unknown if no user agent
        if ($userAgent === 'Unknown' || empty($userAgent)) {
            return 'Unknown';
        }
        
        // Convert to lowercase for easier matching
        $userAgent = strtolower($userAgent);
        
        // Define OS patterns in order of specificity
        $osPatterns = [
            // Windows versions (most specific first)
            'windows nt 10.0' => 'Windows 10/11',
            'windows nt 6.3' => 'Windows 8.1',
            'windows nt 6.2' => 'Windows 8',
            'windows nt 6.1' => 'Windows 7',
            'windows nt 6.0' => 'Windows Vista',
            'windows nt 5.2' => 'Windows XP x64',
            'windows nt 5.1' => 'Windows XP',
            'windows nt 5.0' => 'Windows 2000',
            'windows nt' => 'Windows NT',
            'windows' => 'Windows',
            'win32' => 'Windows',
            'win64' => 'Windows',
            
            // macOS/iOS
            'mac os x 10_15' => 'macOS Catalina',
            'mac os x 10_14' => 'macOS Mojave',
            'mac os x 10_13' => 'macOS High Sierra',
            'mac os x 10_12' => 'macOS Sierra',
            'mac os x 10_11' => 'OS X El Capitan',
            'mac os x 10_10' => 'OS X Yosemite',
            'mac os x' => 'macOS',
            'macintosh' => 'macOS',
            'iphone os' => 'iOS',
            'iphone' => 'iOS',
            'ipad' => 'iPadOS',
            'ipod' => 'iOS',
            
            // Android
            'android' => 'Android',
            
            // Linux distributions
            'ubuntu' => 'Ubuntu Linux',
            'debian' => 'Debian Linux',
            'fedora' => 'Fedora Linux',
            'centos' => 'CentOS Linux',
            'red hat' => 'Red Hat Linux',
            'suse' => 'SUSE Linux',
            'linux' => 'Linux',
            'unix' => 'Unix',
            
            // Other systems
            'freebsd' => 'FreeBSD',
            'openbsd' => 'OpenBSD',
            'netbsd' => 'NetBSD',
            'sunos' => 'SunOS',
            'chromeos' => 'Chrome OS',
            'cros' => 'Chrome OS',
            'blackberry' => 'BlackBerry OS',
            'webos' => 'webOS',
            'bada' => 'Bada',
            'symbian' => 'Symbian',
            'windows phone' => 'Windows Phone',
            'windows mobile' => 'Windows Mobile',
        ];
        
        // Check each pattern
        foreach ($osPatterns as $pattern => $osName) {
            if (strpos($userAgent, $pattern) !== false) {
                return $osName;
            }
        }
        
        return 'Unknown';
    }

    /**
     * Get the browser name and version from user agent
     * 
     * @return string The browser name and version
     */
    public function browser()
    {
        $userAgent = $this->userAgent();
        
        // Return unknown if no user agent
        if ($userAgent === 'Unknown' || empty($userAgent)) {
            return 'Unknown';
        }
        
        // Browser detection patterns (order matters - most specific first)
        $browserPatterns = [
            // Edge (must be before Chrome check)
            '/edg\/([\d.]+)/i' => 'Microsoft Edge',
            '/edge\/([\d.]+)/i' => 'Microsoft Edge Legacy',
            
            // Chrome-based browsers (before Chrome check)
            '/brave\/([\d.]+)/i' => 'Brave',
            '/vivaldi\/([\d.]+)/i' => 'Vivaldi',
            '/opera\/([\d.]+)/i' => 'Opera',
            '/opr\/([\d.]+)/i' => 'Opera',
            
            // Chrome (must be after other Chrome-based browsers)
            '/chrome\/([\d.]+)/i' => 'Google Chrome',
            '/chromium\/([\d.]+)/i' => 'Chromium',
            
            // Firefox
            '/firefox\/([\d.]+)/i' => 'Mozilla Firefox',
            
            // Safari (must be after Chrome check as Chrome contains Safari in UA)
            '/version\/([\d.]+).*safari/i' => 'Safari',
            '/safari\/([\d.]+)/i' => 'Safari',
            
            // Internet Explorer
            '/msie ([\d.]+)/i' => 'Internet Explorer',
            '/trident.*rv:([\d.]+)/i' => 'Internet Explorer',
            
            // Mobile browsers
            '/mobile.*firefox\/([\d.]+)/i' => 'Firefox Mobile',
            '/fxios\/([\d.]+)/i' => 'Firefox iOS',
            '/crios\/([\d.]+)/i' => 'Chrome iOS',
            '/version\/([\d.]+).*mobile.*safari/i' => 'Mobile Safari',
            
            // Other browsers
            '/seamonkey\/([\d.]+)/i' => 'SeaMonkey',
            '/iceweasel\/([\d.]+)/i' => 'Iceweasel',
            '/konqueror\/([\d.]+)/i' => 'Konqueror',
            '/epiphany\/([\d.]+)/i' => 'Epiphany',
            '/midori\/([\d.]+)/i' => 'Midori',
            '/maxthon\/([\d.]+)/i' => 'Maxthon',
            '/lynx\/([\d.]+)/i' => 'Lynx',
            '/links\s\(([\d.]+)/i' => 'Links',
            '/w3m\/([\d.]+)/i' => 'w3m',
        ];
        
        foreach ($browserPatterns as $pattern => $browserName) {
            if (preg_match($pattern, $userAgent, $matches)) {
                $version = isset($matches[1]) ? $matches[1] : '';
                
                // Clean up version (take only first 3 parts: major.minor.patch)
                if (!empty($version)) {
                    $versionParts = explode('.', $version);
                    $cleanVersion = implode('.', array_slice($versionParts, 0, 3));
                    return $browserName . ' ' . $cleanVersion;
                }
                
                return $browserName;
            }
        }
        
        // Fallback patterns for browsers without version
        $fallbackPatterns = [
            '/chrome/i' => 'Google Chrome',
            '/firefox/i' => 'Mozilla Firefox',
            '/safari/i' => 'Safari',
            '/opera/i' => 'Opera',
            '/edge/i' => 'Microsoft Edge',
            '/internet explorer/i' => 'Internet Explorer',
            '/msie/i' => 'Internet Explorer',
        ];
        
        foreach ($fallbackPatterns as $pattern => $browserName) {
            if (preg_match($pattern, $userAgent)) {
                return $browserName;
            }
        }
        
        return 'Unknown';
    }

    /**
     * Get detailed browser and OS information
     * 
     * @return array Array containing 'os', 'browser', and 'user_agent'
     */
    public function getClientInfo()
    {
        return [
            'os' => $this->platform(),
            'browser' => $this->browser(),
            'user_agent' => $this->userAgent(),
            'ip' => $this->ip()
        ];
    }

    // SECURITY REQUEST

    public function detectXss($ignoreList = null)
    {
        // Get all input data similar to unsafe() method
        $inputData = array_merge(
            $_GET,
            $_POST,
            $this->getInputStreamData()
        );

        $whitelistField = [];
        if (!empty($ignoreList)) {
            $whitelistField = is_array($ignoreList) ? $ignoreList : explode(',', $ignoreList);
        }
        
        // Check each input value for XSS patterns
        foreach ($inputData as $key => $value) {

            if (!empty($whitelistField) && in_array($key, $whitelistField)) {
                continue; // Skip whitelisted fields
            }

            if ($this->containsXss($value)) {
                return true;
            }
            
            // Also check the key itself for XSS
            if ($this->containsXss($key)) {
                return true;
            }
            
            // Handle nested arrays recursively
            if (is_array($value)) {
                if ($this->detectXssInArray($value, $whitelistField)) {
                    return true;
                }
            }
        }
        
        return false;
    }

    private function detectXssInArray($array, $ignoreList = null)
    {
        foreach ($array as $key => $value) {
            if (!empty($ignoreList) && in_array($key, $ignoreList)) {
                continue; // Skip whitelisted fields
            }

            if ($this->containsXss($key) || $this->containsXss($value)) {
                return true;
            }
            
            if (is_array($value)) {
                if ($this->detectXssInArray($value, $ignoreList)) {
                    return true;
                }
            }
        }
        
        return false;
    }

    private function containsXss($str)
    {
        // Handle null, empty, or non-string values
        if (empty($str) || !is_string($str)) {
            return false;
        }
        
        // Convert to lowercase for case-insensitive matching
        $lowerStr = strtolower($str);
        
        // Decode HTML entities to catch encoded attacks
        $decodedStr = html_entity_decode($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Check for dangerous HTML tags
        $dangerousTags = [
            'script', 'object', 'embed', 'link', 'style', 'img', 'video', 'audio',
            'iframe', 'frame', 'frameset', 'applet', 'form', 'input', 'button',
            'textarea', 'select', 'option', 'meta', 'base', 'bgsound', 'blink',
            'body', 'head', 'html', 'title', 'xml', 'xmp', 'svg', 'math',
            'details', 'summary', 'menuitem', 'source', 'track', 'canvas',
            'marquee', 'plaintext', 'listing', 'basefont', 'spacer', 'noframes', 
            'noscript', 'noembed', 'param', 'fieldset', 'legend', 'output', 
            'datalist', 'keygen', 'command', 'dialog', 'template', 'picture'
        ];
        
        foreach ($dangerousTags as $tag) {
            // Check for opening tags
            if (preg_match('#<\s*' . $tag . '[\s>]#i', $decodedStr)) {
                return true;
            }
            // Check for closing tags
            if (preg_match('#<\s*/\s*' . $tag . '\s*>#i', $decodedStr)) {
                return true;
            }
            // Check for self-closing tags
            if (preg_match('#<\s*' . $tag . '[^>]*/\s*>#i', $decodedStr)) {
                return true;
            }
        }
        
        // Check for event handlers
        $eventHandlers = [
            'onload', 'onerror', 'onclick', 'onmouseover', 'onmouseout', 'onmousedown',
            'onmouseup', 'onmousemove', 'onkeypress', 'onkeydown', 'onkeyup',
            'onblur', 'onfocus', 'onchange', 'onsubmit', 'onreset', 'onselect',
            'onabort', 'ondblclick', 'ondragdrop', 'onmove', 'onresize',
            'onactivate', 'onafterprint', 'onafterupdate', 'onbeforeactivate',
            'onbeforecopy', 'onbeforecut', 'onbeforedeactivate', 'onbeforeeditfocus',
            'onbeforepaste', 'onbeforeprint', 'onbeforeunload', 'onbeforeupdate',
            'oncontextmenu', 'oncontrolselect', 'oncopy', 'oncut', 'ondrag',
            'ondragend', 'ondragenter', 'ondragleave', 'ondragover', 'ondragstart',
            'ondrop', 'onerrorupdate', 'onfilterchange', 'onfinish', 'onfocusin',
            'onfocusout', 'onhelp', 'onlosecapture', 'onmoveend', 'onmovestart',
            'onpaste', 'onpropertychange', 'onreadystatechange', 'onresizeend',
            'onresizestart', 'onrowenter', 'onrowexit', 'onrowsdelete', 'onrowsinserted',
            'onscroll', 'onstart', 'onstop', 'onunload', 'ontouchstart', 'ontouchend',
            'ontouchmove', 'ontouchcancel', 'onpointerdown', 'onpointerup',
            'onpointermove', 'onpointerover', 'onpointerout', 'onpointerenter',
            'onpointerleave', 'onpointercancel', 'onanimationstart', 'onanimationend',
            'onanimationiteration', 'ontransitionend', 'onwheel', 'oninput', 'oninvalid'
        ];
        
        foreach ($eventHandlers as $handler) {
            if (preg_match('#\s*' . $handler . '\s*=\s*["\'][^"\']*["\']#i', $decodedStr)) {
                return true;
            }
            if (preg_match('#\s*' . $handler . '\s*=\s*[^>\s]+#i', $decodedStr)) {
                return true;
            }
        }
        
        // Check for dangerous protocols
        $dangerousProtocols = [
            'javascript', 'vbscript', 'jscript', 'data', 'about', 'mocha', 'livescript',
            'behavior', 'mhtml', 'file', 'chrome', 'chrome-extension', 'resource',
            'opera', 'opera-extension', 'ms-help', 'ms-settings', 'ms-appx', 'ms-appdata'
        ];
        
        foreach ($dangerousProtocols as $protocol) {
            if (preg_match('#\s*' . $protocol . '\s*:#i', $decodedStr)) {
                return true;
            }
        }
        
        // Check for CSS expressions and other CSS-based attacks
        if (preg_match('#expression\s*\(#i', $decodedStr)) {
            return true;
        }
        
        if (preg_match('#-moz-binding\s*:#i', $decodedStr)) {
            return true;
        }
        
        if (preg_match('#@import\s+["\']?[^"\']*["\']?#i', $decodedStr)) {
            return true;
        }
        
        // Check for CSS url() with javascript
        if (preg_match('#url\s*\(\s*["\']?\s*javascript\s*:#i', $decodedStr)) {
            return true;
        }
        
        // Check for HTML comments that might contain scripts
        if (preg_match('#<!--.*?script.*?-->#si', $decodedStr)) {
            return true;
        }
        
        // Check for CDATA sections that might contain scripts
        if (preg_match('#<!\[CDATA\[.*?script.*?\]\]>#si', $decodedStr)) {
            return true;
        }
        
        // Check for Flash-related attacks
        if (preg_match('#fscommand#i', $decodedStr)) {
            return true;
        }
        
        if (preg_match('#allowscriptaccess#i', $decodedStr)) {
            return true;
        }
        
        // Check for null bytes and control characters
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $str)) {
            return true;
        }
        
        // Check for common XSS patterns
        $xssPatterns = [
            '#<\s*script#i',
            '#</\s*script\s*>#i',
            '#alert\s*\(#i',
            '#confirm\s*\(#i',
            '#prompt\s*\(#i',
            '#eval\s*\(#i',
            '#document\s*\.\s*cookie#i',
            '#document\s*\.\s*write#i',
            '#window\s*\.\s*location#i',
            '#document\s*\.\s*location#i',
            '#setTimeout\s*\(#i',
            '#setInterval\s*\(#i',
            '#String\s*\.\s*fromCharCode#i'
        ];
        
        foreach ($xssPatterns as $pattern) {
            if (preg_match($pattern, $decodedStr)) {
                return true;
            }
        }
        
        // Check for encoded script tags and common XSS vectors
        $encodedPatterns = [
            '&lt;script',
            '&lt;/script',
            '%3Cscript',
            '%3C/script',
            '&#60;script',
            '&#x3C;script',
            'javascript:',
            'vbscript:',
            'onload=',
            'onerror=',
            'onclick='
        ];
        
        foreach ($encodedPatterns as $pattern) {
            if (stripos($lowerStr, strtolower($pattern)) !== false) {
                return true;
            }
        }
        
        return false;
    }

    public function validate($rules, $customMessage = null)
    {
        if (empty($rules) || !is_array($rules)) {
            throw new \InvalidArgumentException('Validation rules must be a non-empty array.');
        }

        $keys = array_keys($rules);
        $allData = array_merge(
            $_GET,
            $_POST,
            $this->getInputStreamData()
        );

        // Prepare data based on rule keys
        $data = [];
        foreach ($keys as $key) {
            // Handle dot notation for nested arrays
            if (strpos($key, '.') !== false) {
                $segments = explode('.', $key);
                $value = $allData;
                foreach ($segments as $segment) {
                    if (isset($value[$segment])) {
                        $value = $value[$segment];
                    } else {
                        $value = null;
                        break;
                    }
                }
                $data[$key] = $value;
            } else {
                // Handle regular keys
                $data[$key] = $allData[$key] ?? null;
            }
        }

        $validator = new \Components\Validation();
        $validator->setRules($rules);

        if (!empty($data)) {
            $validator->setData($data);
        }

        if (!empty($customMessage)) {
            $validator->setMessages($customMessage);
        }

        return $validator->validate();
    }
}
