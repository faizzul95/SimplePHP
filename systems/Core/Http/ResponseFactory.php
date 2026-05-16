<?php

namespace Core\Http;

use Core\View\BladeEngine;
use Traversable;

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

    /**
     * Stream a JSON array response from any iterable data source.
     *
     * @param iterable<mixed> $items
     */
    public function streamJson(iterable $items, int $status = 200, array $headers = [], int $encodingFlags = 0, int $flushEvery = 100): StreamedResponse
    {
        $flags = $encodingFlags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
        $flushEvery = max(1, $flushEvery);

        $headers = array_merge([
            'Content-Type' => 'application/json; charset=UTF-8',
            'X-Accel-Buffering' => 'no',
        ], $headers);

        return $this->stream(static function () use ($items, $flags, $flushEvery): void {
            echo '[';

            $first = true;
            $written = 0;

            foreach ($items as $item) {
                $encoded = json_encode($item, $flags);
                if ($encoded === false) {
                    $encoded = 'null';
                }

                if (!$first) {
                    echo ',';
                }

                echo $encoded;
                $first = false;
                $written++;

                if (($written % $flushEvery) === 0) {
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }

                    flush();
                }
            }

            echo ']';

            if (ob_get_level() > 0) {
                ob_flush();
            }

            flush();
        }, $status, $headers);
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