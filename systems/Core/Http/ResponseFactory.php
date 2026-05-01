<?php

namespace Core\Http;

use Core\View\BladeEngine;

class ResponseFactory
{
    public function __construct(private BladeEngine $blade)
    {
    }

    public function make(string $content, int $status = 200, array $headers = []): HtmlResponse
    {
        return new HtmlResponse($content, $status, $headers);
    }

    public function view(string $view, array $data = [], int $status = 200, array $headers = []): HtmlResponse
    {
        return $this->make($this->blade->render($view, $data), $status, $headers);
    }

    public function download(string $path, ?string $name = null, array $headers = []): BinaryFileResponse
    {
        return new BinaryFileResponse($path, $name, $headers);
    }

    public function stream(callable $callback, int $status = 200, array $headers = []): StreamedResponse
    {
        return new StreamedResponse($callback, $status, $headers);
    }

    public function streamDownload(callable $callback, string $name, array $headers = []): StreamedResponse
    {
        $headers = array_merge([
            'Content-Disposition' => BinaryFileResponse::buildContentDisposition($name),
            'X-Content-Type-Options' => 'nosniff',
        ], $headers);

        return $this->stream($callback, 200, $headers);
    }

    public function json(array $data, int $status = 200): void
    {
        Response::json($data, $status);
    }

    public function redirectTo(string $url, int $status = 302): void
    {
        Response::redirect($url, $status);
    }
}