<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A domain error with its own stable `code` (and optional field errors) in the API error shape.
 * Rendered by ApiExceptionRenderer.
 */
class ApiHttpException extends HttpException
{
    /**
     * @param  array<string, array<int, int|string>>  $errors
     */
    public function __construct(
        int $status,
        private readonly string $apiCode,
        string $message,
        private readonly array $errors = [],
    ) {
        parent::__construct($status, $message);
    }

    public function apiCode(): string
    {
        return $this->apiCode;
    }

    /**
     * @return array<string, array<int, int|string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
