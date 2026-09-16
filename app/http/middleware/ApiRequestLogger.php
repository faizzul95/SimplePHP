<?php

namespace App\Http\Middleware;

use Components\Logger;
use Core\Http\JsonResponse;
use Core\Http\Request;
use Core\Http\Responsable;
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
    private const MAX_JSON_CAPTURE_BYTES = 16384;
    private const MAX_LOGGED_PARAMS_BYTES = 2048;

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

        $params = $this->captureRequestParams($request);

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
            $this->encodeParamsForLog($params)
        ));

        try {
            // Execute next middleware / controller
            $response = $next($request);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            [$statusCode, $responseSummary] = $this->describeResponse($response);
            $outcome = ($statusCode >= 200 && $statusCode < 400) ? 'SUCCESS' : 'FAILED';

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
    private function captureRequestParams(Request $request): array
    {
        $params = array_merge($_GET, $_POST);
        $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');

        if (stripos($contentType, 'application/json') !== false) {
            $rawBody = (string) $request->rawBody();
            if (strlen($rawBody) > self::MAX_JSON_CAPTURE_BYTES) {
                $params['_truncated_json_body'] = sprintf('%d bytes omitted from request log', strlen($rawBody));
            } else {
                $jsonData = json_decode($rawBody, true);
                if (is_array($jsonData)) {
                    $params = array_merge($params, $jsonData);
                }
            }
        }

        return $this->maskSensitiveFields($params);
    }

    private function maskSensitiveFields(array $data): array
    {
        foreach ($data as $key => &$value) {
            if ($this->isSensitiveKey((string) $key)) {
                $value = '***';
            } elseif (is_array($value)) {
                $value = $this->maskSensitiveFields($value);
            }
        }
        unset($value);

        return $data;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(trim($key));

        return preg_match('/pass|pwd|token|secret|key|auth|csrf|cookie/', $normalized) === 1;
    }

    private function encodeParamsForLog(array $params): string
    {
        $encoded = json_encode($params, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            return '{}';
        }

        if (strlen($encoded) <= self::MAX_LOGGED_PARAMS_BYTES) {
            return $encoded;
        }

        return substr($encoded, 0, self::MAX_LOGGED_PARAMS_BYTES) . '...(truncated)';
    }

    /**
     * Append a line to the log file (non-blocking, best-effort).
     */
    /**
     * Work out the status and a short body summary for the log line.
     *
     * The status used to come from http_response_code(), which is read *before*
     * the response is emitted. Once responses became objects the status lived on
     * the object and had not been sent yet, so every entry — 401, 404, 500 alike —
     * was logged as 200 and the log became useless for spotting errors.
     *
     * @return array{0:int,1:string} [status, summary]
     */
    private function describeResponse(mixed $response): array
    {
        if ($response instanceof JsonResponse) {
            $payload = $response->payload();

            // An API body carrying its own `code` is the more specific answer:
            // a 200 envelope with `code: 422` inside is a failure.
            $status = isset($payload['code']) && is_numeric($payload['code'])
                ? (int) $payload['code']
                : $response->status();

            return [$status, $this->summarisePayload($payload)];
        }

        if ($response instanceof Responsable) {
            return [$response->status(), $response::class];
        }

        if (is_array($response)) {
            $status = isset($response['code']) && is_numeric($response['code'])
                ? (int) $response['code']
                : ($this->currentStatusCode() ?: 200);

            return [$status, $this->summarisePayload($response)];
        }

        return [$this->currentStatusCode() ?: 200, 'non-array'];
    }

    /** @param array<mixed, mixed> $payload */
    private function summarisePayload(array $payload): string
    {
        // Only the envelope, never the data — a full body would bloat the log and
        // could persist personal data outside the database.
        $summary = json_encode(
            array_intersect_key($payload, array_flip(['code', 'message'])),
            JSON_UNESCAPED_SLASHES
        );

        return is_string($summary) ? $summary : '{}';
    }

    private function currentStatusCode(): int
    {
        $code = http_response_code();

        return is_int($code) ? $code : 0;
    }

    private function write(string $path, string $line): void
    {
        try {
            $this->appendStructuredLogLine($path, $line);
        } catch (\Throwable $e) {
            // Logging failure must not break the request
        }
    }

    /** Logger::instance() already caches per path; a second cache here just risks drifting from it. */
    private function loggerForPath(string $path): Logger
    {
        return Logger::instance($path);
    }

    private function appendStructuredLogLine(string $path, string $line): void
    {
        $method = 'appendRawLine';
        $this->loggerForPath($path)->{$method}($line);
    }

}
