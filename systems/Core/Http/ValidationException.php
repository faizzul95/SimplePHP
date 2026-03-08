<?php

namespace Core\Http;

use Exception;

class ValidationException extends Exception
{
    private array $errors;
    private int $statusCode;

    public function __construct(string $message, array $errors = [], int $statusCode = 422)
    {
        parent::__construct($message, $statusCode);
        $this->errors = $errors;
        $this->statusCode = $statusCode;
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
