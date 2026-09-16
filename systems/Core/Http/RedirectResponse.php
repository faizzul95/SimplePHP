<?php

namespace Core\Http;

class RedirectResponse implements Responsable
{
    use HeaderSanitizer;

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
        $payload = $input ?? array_merge($_GET, $_POST);
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

    public function status(): int
    {
        return $this->status;
    }

    /**
     * Location plus any extra headers, with the target already run through the
     * redirect allow-list so an open redirect cannot be constructed here.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        $headers = $this->sanitizeHeaders($this->headers);
        $headers['Location'] = Response::sanitizeRedirectTarget($this->targetUrl, $this->allowExternal);

        return $headers;
    }

    /**
     * Flash data has to reach the session before the redirect is written, because
     * the next request reads it. emitBody() runs after headers are sent, which is
     * still before the session is closed, so this is the right place for it.
     */
    public function emitBody(): void
    {
        foreach ($this->flash as $key => $value) {
            if (function_exists('flashSession') && is_string($key) && $key !== '') {
                flashSession($key, $value);
            }
        }
    }

    /** Throws rather than exits so middleware unwind and the Kernel emits once. */
    public function send(): never
    {
        throw new ResponseEmitted($this);
    }
}