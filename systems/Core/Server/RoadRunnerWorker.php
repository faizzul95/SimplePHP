<?php

declare(strict_types=1);

/**
 * RoadRunner Worker Entry Point — EXPERIMENTAL, DISABLED BY DEFAULT
 *
 * MythPHP does not use PSR-7 internally; this worker bridges RoadRunner's PSR-7
 * request into MythPHP by populating PHP superglobals and capturing output, then
 * forwarding the captured response back.
 *
 * Refuses to start unless MYTH_EXPERIMENTAL_WORKER=1. The isolation gaps that made
 * this unsafe are now addressed — WorkerState::flush() clears the service
 * singletons and the session, PsrRequestBridge maps $_FILES natively, and the HTTP
 * path no longer calls exit — but the combination has not yet been proven under
 * load, so it stays behind the flag until it has.
 *
 * through #w-04.
 *
 * Requirements (install via Composer before use):
 *   composer require spiral/roadrunner-http nyholm/psr7
 */

use Core\Http\Emitter;
use Core\Server\PsrRequestBridge;
use Core\Server\WorkerState;

if (!defined('ROOT_DIR')) {
    define('ROOT_DIR', dirname(__DIR__, 3) . DIRECTORY_SEPARATOR);
}

require ROOT_DIR . 'vendor/autoload.php';
require ROOT_DIR . 'bootstrap.php';

if (!function_exists('env') || !(bool) env('MYTH_EXPERIMENTAL_WORKER', false)) {
    fwrite(STDERR, implode(PHP_EOL, [
        'RoadRunner worker mode is disabled.',
        '',
        'Set MYTH_EXPERIMENTAL_WORKER=1 to override. Read',
        ' first — worker isolation is implemented but',
        'not yet load-proven, so this is for throwaway environments only.',
        '',
    ]));
    exit(1);
}

$workerClass = 'Spiral\\RoadRunner\\Worker';
$psr17FactoryClass = 'Nyholm\\Psr7\\Factory\\Psr17Factory';
$psr7WorkerClass = 'Spiral\\RoadRunner\\Http\\PSR7Worker';
$responseClass = 'Nyholm\\Psr7\\Response';

if (!class_exists($workerClass) || !class_exists($psr17FactoryClass) || !class_exists($psr7WorkerClass) || !class_exists($responseClass)) {
    fwrite(STDERR, "RoadRunner worker requires: composer require spiral/roadrunner-http nyholm/psr7\n");
    exit(1);
}

$worker  = $workerClass::create();
$factory = new $psr17FactoryClass();
$psr7    = new $psr7WorkerClass($worker, $factory, $factory, $factory);

// Snapshot the ambient $_SERVER before any request touches it, so each cycle can
// be restored to it rather than inheriting the previous request's headers.
WorkerState::captureBaseline();

// Bootstrap the kernel ONCE per worker process.
$kernel = new \App\Http\Kernel();

/** @var list<string> Temp files spooled for this request's uploads. */
$spooledUploads = [];

$spoolUpload = static function (object $file) use (&$spooledUploads): string {
    $tmp = tempnam(sys_get_temp_dir(), 'myth_upload_');

    if ($tmp === false) {
        throw new RuntimeException('Unable to allocate a temp file for an upload.');
    }

    $stream = $file->getStream();
    $stream->rewind();

    $handle = fopen($tmp, 'wb');
    if ($handle === false) {
        throw new RuntimeException('Unable to open the upload temp file for writing.');
    }

    while (!$stream->eof()) {
        fwrite($handle, $stream->read(8192));
    }

    fclose($handle);
    $spooledUploads[] = $tmp;

    return $tmp;
};

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
        // 1. Reset per-request state — MUST be first, so a request that threw
        //    before its own cleanup cannot contaminate this one.
        WorkerState::flush();

        // 2. Bridge PSR-7 → superglobals.
        $uri  = $psrRequest->getUri();
        $body = $psrRequest->getParsedBody();

        $_SERVER = array_merge($_SERVER, PsrRequestBridge::serverParams(
            $psrRequest->getMethod(),
            $uri->getScheme(),
            $uri->getHost(),
            $uri->getPort(),
            $uri->getPath(),
            $uri->getQuery(),
            $psrRequest->getHeaders(),
            $psrRequest->getServerParams()
        ));

        parse_str($uri->getQuery(), $_GET);
        $_POST   = is_array($body) ? $body : [];
        $_COOKIE = $psrRequest->getCookieParams();
        $_FILES  = PsrRequestBridge::files($psrRequest->getUploadedFiles(), $spoolUpload);
        $_REQUEST = array_merge($_GET, $_POST);

        // 3. Capture everything the request writes.
        ob_start();

        $mythRequest = \Core\Http\Request::capture();
        dispatch_event('request.captured', ['request' => $mythRequest]);
        $kernel->handle($mythRequest);

        $output = (string) ob_get_clean();

        // 4. Collect the headers the request emitted.
        $headers = [];
        $status  = http_response_code();
        $status  = is_int($status) ? $status : 200;

        foreach (headers_list() as $header) {
            [$name, $value] = array_pad(explode(':', $header, 2), 2, '');
            $name = trim($name);

            if ($name === '') {
                continue;
            }

            $headers[$name][] = trim($value);
        }

        header_remove();

        // 5. Respond.
        $psr7->respond(new $responseClass($status, $headers, $output));
    } catch (\Throwable $e) {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }

        $psr7->getWorker()->error((string) $e);
    } finally {
        // Session locks must be released before the next request, and spooled
        // uploads deleted — a worker that leaks temp files fills /tmp in hours.
        \Core\Session\SessionCycle::end();

        foreach ($spooledUploads as $tmp) {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }

        $spooledUploads = [];
        Emitter::reset();
    }
}
