<?php

namespace Core\Http;

class RedirectResponse
{
    private string $targetUrl;
    private int $status;
    private array $headers = [];
    private array $flash = [];
    private bool $allowExternal = false;

    public function __construct(string $targetUrl, int $status = 302, array $headers = [], bool $allowExternal = false)
    {
        $this->targetUrl = $targetUrl;
        $this->status = $status;
        $this->headers = $headers;
        $this->allowExternal = $allowExternal;
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }

    public function withHeaders(array $headers): self
    {
        $clone = clone $this;

        foreach ($headers as $name => $value) {
            if (!is_string($name) || !is_scalar($value)) {
                continue;
            }

            $clone->headers[$name] = (string) $value;
        }

        return $clone;
    }

    public function with(string $key, $value): self
    {
        if ($key === '') {
            return $this;
        }

        $clone = clone $this;
        $clone->flash[$key] = $value;
        return $clone;
    }

    public function withErrors(array $errors): self
    {
        return $this->with('_errors', $errors);
    }

    public function withInput(?array $input = null, array $except = ['_token', 'password', 'password_confirmation', 'current_password', 'new_password', 'new_password_confirmation']): self
    {
        $payload = $input ?? array_merge($_GET ?? [], $_POST ?? []);
        $filtered = [];

        foreach ($payload as $key => $value) {
            if (!is_string($key) || in_array($key, $except, true)) {
                continue;
            }

            $filtered[$key] = $value;
        }

        return $this->with('_old_input', $filtered);
    }

    public function getTargetUrl(): string
    {
        return $this->targetUrl;
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function send(): never
    {
        foreach ($this->flash as $key => $value) {
            if (function_exists('flashSession') && is_string($key) && $key !== '') {
                flashSession($key, $value);
            }
        }

        Response::sendRedirectHeaders($this->targetUrl, $this->status, $this->headers, $this->allowExternal);
        exit;
    }
}