<?php

namespace Core\Http;

class StreamedResponse implements Responsable
{
    use HeaderSanitizer;

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

    public function emitBody(): void
    {
        ($this->callback)();
    }

    /** Throws rather than exits so middleware unwind and the Kernel emits once. */
    public function send(): never
    {
        throw new ResponseEmitted($this);
    }
}
