<?php

namespace App\Services\C6\Exceptions;

use RuntimeException;

class C6ApiException extends RuntimeException
{
    public function __construct(
        string $message,
        protected int $httpStatus = 502,
        protected ?int $upstreamStatus = null,
        protected array|string|null $upstreamBody = null,
        protected string $error = 'c6_api_error',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function upstreamStatus(): ?int
    {
        return $this->upstreamStatus;
    }

    public function upstreamBody(): array|string|null
    {
        return $this->upstreamBody;
    }

    public function error(): string
    {
        return $this->error;
    }
}
