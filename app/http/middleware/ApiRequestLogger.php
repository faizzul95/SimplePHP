<?php

namespace App\Http\Middleware;

use Core\Http\Request;
use Core\Http\Middleware\MiddlewareInterface;

/**
 * Logs every API request and response for debugging / auditing.
 *
 * Configuration (app/config/api.php):
 *   'logging' => [
 *       'enabled'  => true,
 *       'log_path' => 'logs/api.log',
 *   ]
 *
 * Usage in routes:
 *   ->middleware('api.log')
 *
 * Or in a route group:
 *   $router->group(['middleware' => ['api.log']], function ($router) { ... });
 */
class ApiRequestLogger implements MiddlewareInterface
{
    public function setParameters(array $parameters): void
    {
        // No parameters needed — config driven
    }

    public function handle(Request $request, callable $next)
    {
        $config = \config('api.logging') ?? [];

        if (empty($config['enabled'])) {
            return $next($request);
        }

        $startTime = microtime(true);
        $requestId = substr(bin2hex(random_bytes(8)), 0, 16);

        // Capture request info
        $method  = $request->method();
        $uri     = $request->path();
        $ip      = $request->ip();
        $agent   = $request->userAgent();

        // Mask sensitive fields
        $params = array_merge($_GET, $_POST);

        // Also capture JSON body if present
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            $rawBody = file_get_contents('php://input');
            $jsonData = json_decode($rawBody, true);
            if (is_array($jsonData)) {
                $params = array_merge($params, $jsonData);
            }
        }

        $params = $this->maskSensitiveFields($params);

        $logPath = $config['log_path'] ?? 'logs/api.log';

        // Resolve relative paths against ROOT_DIR
        if (!preg_match('#^[/\\\\]|^[a-zA-Z]:#', $logPath)) {
            $logPath = (defined('ROOT_DIR') ? ROOT_DIR : '') . $logPath;
        }

        $this->write($logPath, sprintf(
            "[%s][REQUEST] id=%s method=%s uri=%s ip=%s agent=%s params=%s",
            date('Y-m-d H:i:s'),
            $requestId,
            $method,
            $uri,
            $ip,
            $agent,
            json_encode($params, JSON_UNESCAPED_SLASHES)
        ));

        try {
            // Execute next middleware / controller
            $response = $next($request);

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $statusCode = http_response_code() ?: 200;
            $outcome = ($statusCode >= 200 && $statusCode < 400) ? 'SUCCESS' : 'FAILED';

            // Log a summary of the response (not the full body to avoid bloat)
            $responseSummary = 'non-array';
            if (is_array($response)) {
                $responseSummary = json_encode(
                    array_intersect_key($response, array_flip(['code', 'message'])),
                    JSON_UNESCAPED_SLASHES
                );

                // Prefer API response code when present to classify outcome.
                $apiCode = isset($response['code']) && is_numeric($response['code']) ? (int) $response['code'] : null;
                if ($apiCode !== null) {
                    $statusCode = $apiCode;
                    $outcome = ($apiCode >= 200 && $apiCode < 400) ? 'SUCCESS' : 'FAILED';
                }
            }

            $this->write($logPath, sprintf(
                "[%s][%s] RESPONSE id=%s status=%s duration=%sms body=%s",
                date('Y-m-d H:i:s'),
                $outcome,
                $requestId,
                $statusCode,
                $duration,
                $responseSummary
            ));

            return $response;
        } catch (\Throwable $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $this->write($logPath, sprintf(
                "[%s][FAILED] RESPONSE id=%s status=500 duration=%sms exception=%s",
                date('Y-m-d H:i:s'),
                $requestId,
                $duration,
                $e->getMessage()
            ));

            throw $e;
        }
    }

    /**
     * Mask password, token, and secret fields to avoid leaking credentials in logs.
     */
    private function maskSensitiveFields(array $data): array
    {
        $sensitiveKeys = ['password', 'password_confirmation', 'token', 'secret', 'api_key', 'access_token'];

        foreach ($data as $key => &$value) {
            if (in_array(strtolower($key), $sensitiveKeys, true)) {
                $value = '***';
            } elseif (is_array($value)) {
                $value = $this->maskSensitiveFields($value);
            }
        }
        unset($value);

        return $data;
    }

    /**
     * Append a line to the log file (non-blocking, best-effort).
     */
    private function write(string $path, string $line): void
    {
        try {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // Logging failure must not break the request
        }
    }
}
