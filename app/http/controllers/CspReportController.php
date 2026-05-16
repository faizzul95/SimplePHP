<?php

namespace App\Http\Controllers;

use Core\Http\Controller;
use Core\Http\Request;
use Core\Security\CspViolationLogger;

class CspReportController extends Controller
{
    public function store(): void
    {
        $request = Request::current();
        $rawBody = $request?->rawBody();
        if ($rawBody === null || $rawBody === '') {
            $fallback = file_get_contents('php://input');
            $rawBody = is_string($fallback) ? $fallback : '';
        }

        $this->storePayload($rawBody, $_SERVER);
    }

    public function storePayload(string $rawBody, array $server = []): void
    {
        CspViolationLogger::record($rawBody, $server);
        http_response_code(204);
    }
}