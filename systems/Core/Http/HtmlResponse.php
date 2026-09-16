<?php

namespace Core\Http;

class HtmlResponse implements Responsable
{
    use HeaderSanitizer;

    public function __construct(
        private string $content,
        private int $status = 200,
        private array $headers = []
    ) {
    }

    public function content(): string
    {
        return $this->content;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function headers(): array
    {
        return $this->sanitizeHeaders($this->headers);
    }

    public function emitBody(): void
    {
        echo $this->content;
    }

    /**
     * Hand the response to the Kernel.
     *
     * Throws rather than exits: `never` still holds, every call site keeps working,
     * and middleware post-$next() code now runs on the way out.
     *
     */
    public function send(): never
    {
        throw new ResponseEmitted($this);
    }
}
