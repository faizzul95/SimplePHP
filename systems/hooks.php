<?php

/*
|--------------------------------------------------------------------------
| GET PROJECT BASE URL
|--------------------------------------------------------------------------
*/

if (!function_exists('getProjectBaseUrl')) {
    function getProjectBaseUrl()
    {
        $isHttps = (
            (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') ||
            (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ||
            (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') ||
            (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
        );

        $protocol = $isHttps ? 'https' : 'http';

        // Check if we're on localhost
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $isLocalhost = (
            strpos($host, 'localhost') !== false ||
            strpos($host, '127.0.0.1') !== false ||
            strpos($host, '::1') !== false ||
            preg_match('/^192\.168\./', $host) ||
            preg_match('/^10\./', $host) ||
            preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $host)
        );

        if ($isLocalhost) {
            // For localhost, include the project folder
            $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
            $pathSegments = explode('/', trim($scriptDir, '/'));
            $projectFolder = !empty($pathSegments[0]) ? '/' . $pathSegments[0] : '';

            return $protocol . '://' . $host . $projectFolder . '/';
        } else {
            // For production, just return domain
            return $protocol . '://' . $host . '/';
        }
    }
}

/*
|--------------------------------------------------------------------------
| GET CONFIGURATION VALUE
|--------------------------------------------------------------------------
*/
if (!function_exists('config')) {
    function config($key)
    {
        global $config;

        if (empty($key)) {
            return $config;
        }

        $keys = explode('.', $key);
        $value = $config;

        foreach ($keys as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } else {
                return null;
            }
        }

        return $value;
    }
}

/*
|--------------------------------------------------------------------------
| LOAD ALL COMPONENTS SYSTEMS
|--------------------------------------------------------------------------
*/

spl_autoload_register(function ($class) {
    $baseDir = __DIR__ . '/../systems/'; // root of class files
    $classPath = str_replace('\\', DIRECTORY_SEPARATOR, $class);
    $file = $baseDir . $classPath . '.php';

    try {
        if (is_readable($file)) {
            require_once $file;
        } else {
            throw new Exception("File not readable: $file");
        }
    } catch (Exception $e) {
        die("Error: Unable to resolve file path for $file. " . $e->getMessage());
    }
});

/*
|--------------------------------------------------------------------------
| LOAD ALL HELPERS FUNCTIONS
|--------------------------------------------------------------------------
*/

if (!function_exists('loadHelperFiles')) {
    function loadHelperFiles()
    {
        $helpersDir = __DIR__ . '/../app/helpers/'; // root of helper files

        // Get all PHP files in the General folder
        $helperFiles = glob($helpersDir . '*.php');

        foreach ($helperFiles as $file) {
            try {
                if (is_readable($file)) {
                    include_once $file;
                } else {
                    throw new Exception("File not readable: $file");
                }
            } catch (Exception $e) {
                die("Error: Unable to resolve file path for $file. " . $e->getMessage());
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| LOAD ALL CONTROLLER SCOPE & MACRO FUNCTIONS
|--------------------------------------------------------------------------
*/

/**
 * Load and execute all functions from specified files and folders
 * Enhanced with better error handling and validation
 * @param mixed $params Parameters to pass to functions (can be single value or array)
 * @param string|array $filename Single filename or array of filenames
 * @param string|array $foldername Single folder name or array of folder names
 * @param string $base_path Base path for files/folders (default: '../controllers/')
 * @param bool $silent Whether to suppress error reporting (default: false)
 */
if (!function_exists('loadScopeMacroDBFunctions')) {
    function loadScopeMacroDBFunctions($params, $filename = [], $foldername = [], $base_path = '../controllers/', $silent = false)
    {
        // Input validation
        if (empty($filename) && empty($foldername)) {
            if (!$silent) {
                error_log("loadScopeMacroDBFunctions: No files or folders specified");
            }
            return;
        }

        if (!is_string($base_path) || empty(trim($base_path))) {
            if (!$silent) {
                error_log("loadScopeMacroDBFunctions: Invalid base path provided");
            }
            return;
        }

        $executed_functions = [];
        $errors = [];
        $base_path = rtrim($base_path, '/') . '/';

        // Validate base path exists
        if (!is_dir($base_path)) {
            if (!$silent) {
                error_log("loadScopeMacroDBFunctions: Base path does not exist: {$base_path}");
            }
            return;
        }

        // Convert single values to arrays and validate
        $filenames = [];
        $foldernames = [];

        if (!empty($filename)) {
            $filenames = is_array($filename) ? $filename : [$filename];
            $filenames = array_filter($filenames, function ($f) {
                return is_string($f) && !empty(trim($f));
            });
        }

        if (!empty($foldername)) {
            $foldernames = is_array($foldername) ? $foldername : [$foldername];
            $foldernames = array_filter($foldernames, function ($f) {
                return is_string($f) && !empty(trim($f));
            });
        }

        // Anonymous function to safely extract functions from file
        $extractFunctions = function ($file_path) use (&$errors, $silent) {
            if (!file_exists($file_path)) {
                if (!$silent) {
                    $errors[] = "File does not exist: {$file_path}";
                }
                return [];
            }

            if (!is_readable($file_path)) {
                if (!$silent) {
                    $errors[] = "File is not readable: {$file_path}";
                }
                return [];
            }

            $content = @file_get_contents($file_path);
            if ($content === false) {
                if (!$silent) {
                    $errors[] = "Failed to read file: {$file_path}";
                }
                return [];
            }

            $functions = [];

            try {
                // Match all function declarations with better regex
                if (preg_match_all('/(?:^|\s)function\s+([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\s*\(/im', $content, $matches)) {
                    if (!empty($matches[1])) {
                        $functions = array_unique($matches[1]);
                        // Filter out magic methods and constructors that shouldn't be called directly
                        $functions = array_filter($functions, function ($func) {
                            return !in_array(strtolower($func), ['__construct', '__destruct', '__call', '__callstatic', '__get', '__set', '__isset', '__unset', '__sleep', '__wakeup', '__serialize', '__unserialize', '__tostring', '__invoke', '__set_state', '__clone', '__debuginfo']);
                        });
                    }
                }
            } catch (Exception $e) {
                if (!$silent) {
                    $errors[] = "Error parsing file {$file_path}: " . $e->getMessage();
                }
            }

            return $functions;
        };

        // Anonymous function to safely execute function with parameters
        $executeFunction = function ($function_name, $params) use (&$errors, $silent) {
            if (!is_string($function_name) || empty($function_name)) {
                return false;
            }

            if (!function_exists($function_name)) {
                if (!$silent) {
                    $errors[] = "Function does not exist: {$function_name}";
                }
                return false;
            }

            try {
                // Get function reflection for better parameter validation
                $reflection = new ReflectionFunction($function_name);
                $required_params = $reflection->getNumberOfRequiredParameters();
                $total_params = $reflection->getNumberOfParameters();

                $param_count = is_array($params) ? count($params) : 1;

                // Check parameter count
                if ($param_count < $required_params || $param_count > $total_params) {
                    if (!$silent) {
                        $errors[] = "Function {$function_name} expects {$required_params}-{$total_params} parameters, {$param_count} given";
                    }
                    return false;
                }

                // Execute function
                if (is_array($params)) {
                    call_user_func_array($function_name, $params);
                } else {
                    call_user_func($function_name, $params);
                }

                return true;
            } catch (ReflectionException $e) {
                if (!$silent) {
                    $errors[] = "Reflection error for {$function_name}: " . $e->getMessage();
                }
                return false;
            } catch (ArgumentCountError $e) {
                if (!$silent) {
                    $errors[] = "Parameter count error in {$function_name}: " . $e->getMessage();
                }
                return false;
            } catch (TypeError $e) {
                if (!$silent) {
                    $errors[] = "Type error in {$function_name}: " . $e->getMessage();
                }
                return false;
            } catch (Error $e) {
                if (!$silent) {
                    $errors[] = "Fatal error in {$function_name}: " . $e->getMessage();
                }
                return false;
            } catch (Exception $e) {
                if (!$silent) {
                    $errors[] = "Exception in {$function_name}: " . $e->getMessage();
                }
                return false;
            }
        };

        // Anonymous function to safely process a single file
        $processFile = function ($file_path) use ($params, &$executed_functions, $extractFunctions, $executeFunction) {
            // Validate file extension
            if (pathinfo($file_path, PATHINFO_EXTENSION) !== 'php') {
                return;
            }

            if (file_exists($file_path) && is_readable($file_path)) {
                // Include file safely
                $included = @include_once $file_path;
                if ($included === false) {
                    return;
                }

                // Get all functions from file
                $functions = $extractFunctions($file_path);

                // Execute each function with params
                foreach ($functions as $function_name) {
                    if (!in_array($function_name, $executed_functions)) {
                        $result = $executeFunction($function_name, $params);
                        if ($result) {
                            $executed_functions[] = $function_name;
                        }
                    }
                }
            }
        };

        // Anonymous function to safely process a folder
        $processFolder = function ($folder_path) use ($processFile, &$errors, $silent) {
            if (!is_dir($folder_path)) {
                if (!$silent) {
                    $errors[] = "Directory does not exist: {$folder_path}";
                }
                return;
            }

            if (!is_readable($folder_path)) {
                if (!$silent) {
                    $errors[] = "Directory is not readable: {$folder_path}";
                }
                return;
            }

            $php_files = @glob($folder_path . '/*.php');
            if ($php_files === false) {
                if (!$silent) {
                    $errors[] = "Failed to read directory: {$folder_path}";
                }
                return;
            }

            foreach ($php_files as $file_path) {
                $processFile($file_path);
            }
        };

        // Process individual files
        foreach ($filenames as $file) {
            $file_path = $base_path . ltrim($file, '/');
            $processFile($file_path);
        }

        // Process folders
        foreach ($foldernames as $folder) {
            $folder_path = $base_path . ltrim($folder, '/');
            $processFolder($folder_path);
        }

        // Log errors if any occurred and not in silent mode
        if (!$silent && !empty($errors)) {
            foreach ($errors as $error) {
                error_log("loadScopeMacroDBFunctions: " . $error);
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| LOAD ALL MIDDLEWARES
|--------------------------------------------------------------------------
*/

if (!function_exists('loadMiddlewaresFiles')) {
    function loadMiddlewaresFiles(array $middlewares, $args = null)
    {
        foreach ($middlewares as $middleware) {
            $class = "Middleware\\$middleware";
            if (class_exists($class)) {
                $instance = new $class();
                if (method_exists($instance, 'run')) {
                    $instance->run($args);
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| DEBUG COMPONENT 
|--------------------------------------------------------------------------
*/

if (!function_exists('debug')) {
    function debug()
    {
        return new \Components\Debug();
    }
}

/*
|--------------------------------------------------------------------------
| LOGGER COMPONENT 
|--------------------------------------------------------------------------
*/

if (!function_exists('logger')) {
    function logger()
    {
        global $config;
        return new \Components\Logger(__DIR__ . '/../' . $config['error_log_path']);
    }
}

/*
|--------------------------------------------------------------------------
| REQUEST COMPONENT
|--------------------------------------------------------------------------
*/

if (!function_exists('request')) {
    function request()
    {
        return new \Components\Request();
    }
}

/*
|--------------------------------------------------------------------------
| VALIDATION COMPONENT
|--------------------------------------------------------------------------
*/

if (!function_exists('validator')) {
    function validator($data = [], $rules = [], $customMessage = [])
    {
        $validator = new \Components\Validation();

        if (!empty($data)) {
            $validator->setData($data);
        }

        if (!empty($rules)) {
            $validator->setRules($rules);
        }

        if (!empty($customMessage)) {
            $validator->setMessages($customMessage);
        }

        return $validator;
    }
}

/*
|--------------------------------------------------------------------------
| CSRF COMPONENT
|--------------------------------------------------------------------------
*/

if (!function_exists('csrf')) {
    function csrf()
    {
        global $config;
        return new \Components\CSRF($config['security']['csrf']);
    }
}


if (!function_exists('csrf_field')) {
    function csrf_field()
    {
        return csrf()->field();
    }
}

if (!function_exists('csrf_value')) {
    function csrf_value()
    {
        return csrf()->regenerate();
    }
}
