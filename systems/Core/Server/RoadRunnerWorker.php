<?php

declare(strict_types=1);

/**
 * RoadRunner Worker Entry Point
 *
 * MythPHP does not use PSR-7 internally; this worker bridges RoadRunner's
 * PSR-7 request into MythPHP by populating PHP superglobals and capturing
 * output via ob_start(), then forwarding the captured response back.
 *
 * Requirements (install via Composer before use):
 *   composer require spiral/roadrunner-http nyholm/psr7
 *
 * Start with:
 *   rr serve -c .rr.yaml
 *
 */

if (!defined('ROOT_DIR')) {
    define('ROOT_DIR', dirname(__DIR__, 3) . DIRECTORY_SEPARATOR);
}

require ROOT_DIR . 'vendor/autoload.php';
require ROOT_DIR . 'bootstrap.php';

if (!class_exists(\Spiral\RoadRunner\Worker::class)) {
    fwrite(STDERR, "RoadRunner worker requires: composer require spiral/roadrunner-http nyholm/psr7\n");
    exit(1);
}

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Worker;

$worker  = Worker::create();
$factory = new Psr17Factory();
$psr7    = new PSR7Worker($worker, $factory, $factory, $factory);

// Bootstrap kernel ONCE per worker process
$kernel = new \App\Http\Kernel();

while (true) {
    try {
        $psrRequest = $psr7->waitRequest();
    } catch (\Throwable $e) {
        break; // Worker shutdown
    }

    if ($psrRequest === null) {
        break;
    }

    try {
        // 1. Reset per-request static state — MUST be first
        \Core\Server\WorkerState::flush();

        // 2. Bridge PSR-7 request → PHP superglobals so MythPHP's routing works
        $uri  = $psrRequest->getUri();
        $body = $psrRequest->getParsedBody() ?? [];

        $_SERVER['REQUEST_METHOD']  = $psrRequest->getMethod();
        $_SERVER['REQUEST_URI']     = $uri->getPath() . ($uri->getQuery() !== '' ? '?' . $uri->getQuery() : '');
        $_SERVER['QUERY_STRING']    = $uri->getQuery();
        $_SERVER['HTTP_HOST']       = $uri->getHost();
        $_SERVER['SERVER_NAME']     = $uri->getHost();
        $_SERVER['SERVER_PORT']     = (string) ($uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80));
        $_SERVER['HTTPS']           = $uri->getScheme() === 'https' ? 'on' : '';
        $_SERVER['REMOTE_ADDR']     = $psrRequest->getServerParams()['REMOTE_ADDR'] ?? '127.0.0.1';

        foreach ($psrRequest->getHeaders() as $name => $values) {
            $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
            $_SERVER[$key] = implode(', ', $values);
        }

        parse_str($uri->getQuery(), $_GET);
        $_POST   = is_array($body) ? $body : [];
        $_COOKIE = $psrRequest->getCookieParams();
        $_FILES  = $psrRequest->getUploadedFiles();

        // 3. Capture all output MythPHP echoes during the request
        ob_start();

        $mythRequest = \Core\Http\Request::capture();
        $kernel->handle($mythRequest);

        $output = (string) ob_get_clean();

        // 4. Collect headers sent during the request (PHP output headers)
        $headers = [];
        $status  = 200;
        foreach (headers_list() as $header) {
            if (str_starts_with($header, 'HTTP/')) {
                // e.g. "HTTP/1.1 301 Moved Permanently"
                $parts  = explode(' ', $header, 3);
                $status = (int) ($parts[1] ?? 200);
                continue;
            }
            [$name, $value] = array_pad(explode(':', $header, 2), 2, '');
            $headers[trim($name)][] = trim($value);
        }
        header_remove(); // Clear headers — RoadRunner will send them from the response

        // 5. Build PSR-7 response from captured output + headers
        $psrResponse = new Response($status, $headers, $output);
        $psr7->respond($psrResponse);
    } catch (\Throwable $e) {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        $psr7->getWorker()->error((string) $e);
    }
}

