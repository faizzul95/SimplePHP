<?php

namespace Core\Http;

class StreamedResponse
{
    public function __construct(
        private $callback,
        private int $status = 200,
        private array $headers = []
    ) {
    }

    public function status(): int
    {
        return $this->status;
    }

    public function headers(): array
    {
        return $this->sanitizeHeaders($this->headers);
    }

    public function send(): never
    {
        if (!headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers() as $name => $value) {
                header($name . ': ' . (string) $value, true);
            }
        }

        ($this->callback)();
        exit;
    }

    private function sanitizeHeaders(array $headers): array
    {
        $sanitized = [];

        foreach ($headers as $name => $value) {
            if (!is_string($name) || !is_scalar($value)) {
                continue;
            }

            $headerName = str_replace(["\r", "\n", "\0"], '', $name);
            $headerValue = str_replace(["\r", "\n", "\0"], '', (string) $value);
            if ($headerName === '') {
                continue;
            }

            $sanitized[$headerName] = $headerValue;
        }

        return $sanitized;
    }
}