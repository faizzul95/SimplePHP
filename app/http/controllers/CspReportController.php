<?php

namespace App\Http\Controllers;

use Core\Http\Controller;
use Core\Http\HtmlResponse;
use Core\Http\Request;
use Core\Http\Responsable;
use Core\Security\CspViolationLogger;

class CspReportController extends Controller
{
    public function store(): Responsable
    {
        $request = Request::current();
        $rawBody = $request?->rawBody();
        if ($rawBody === null || $rawBody === '') {
            $fallback = file_get_contents('php://input');
            $rawBody = is_string($fallback) ? $fallback : '';
        }

        return $this->storePayload($rawBody, $_SERVER);
    }

    /**
     * Record the violation and acknowledge it.
     *
     * Returns a 204 rather than calling http_response_code() directly: a status
     * set outside the emitter depends on call order and is silently overwritten
     * by whatever the kernel emits afterwards. The browser only needs the
     * acknowledgement, so there is no body.
     */
    public function storePayload(string $rawBody, array $server = []): Responsable
    {
        CspViolationLogger::record($rawBody, $server);

        return new HtmlResponse('', 204, ['Content-Length' => '0']);
    }
}