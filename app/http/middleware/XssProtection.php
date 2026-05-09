<?php

namespace App\Http\Middleware;

use Core\Http\Request;
use Core\Http\Middleware\MiddlewareInterface;
use Middleware\Traits\XssProtectionTrait;

/**
 * XSS protection middleware using XssProtectionTrait.
 * 
 * Scans incoming request data ($_GET, $_POST, input stream, file names)
 * for XSS patterns. Returns 400 if malicious content is detected and
 * logs the attempt.
 *
 * Usage in routes (single field):
 *   ->middleware('xss:content')
 *
 * Usage in routes (multiple fields — comma-separated after colon):
 *   ->middleware('xss:content,body,description')
 *
 * Usage with no exclusions:
 *   ->middleware('xss')
 *
 * Usage in route groups:
 *   $router->group(['middleware' => ['xss']], function ($router) { ... });
 */
class XssProtection implements MiddlewareInterface
{
    use XssProtectionTrait;

    /** @var string[] Fields to exclude from XSS scanning */
    private array $ignoreFields = [];

    public function setParameters(array $parameters): void
    {
        // Router splits 'xss:field1,field2' into ['field1', 'field2']
        // Flatten any remaining comma-separated values into a clean array
        $fields = [];
        foreach ($parameters as $param) {
            foreach (explode(',', (string) $param) as $field) {
                $trimmed = trim($field);
                if ($trimmed !== '') {
                    $fields[] = $trimmed;
                }
            }
        }
        $this->ignoreFields = $fields;
    }

    public function handle(Request $request, callable $next)
    {
        // Skip non-content methods — nothing to scan
        $method = strtoupper($request->method());
        if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        // Scan all methods (GET included) — detectXss() merges $_GET + $_POST + input stream,
        // so this closes the reflected-XSS-via-GET gap without any extra calls.
        if ($this->isXssAttack($this->ignoreFields)) {
            if ($request->expectsJson()) {
                \Core\Http\Response::json([
                    'code'    => 400,
                    'message' => 'Potentially unsafe content detected',
                ], 400);
            }

            http_response_code(400);
            echo '400 Bad Request - Potentially unsafe content detected';
            exit;
        }

        return $next($request);
    }
}
