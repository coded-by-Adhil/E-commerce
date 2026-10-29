<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Single place where every exception becomes the API error shape:
 * {"message": "...", "code": "...", "errors": {"field": ["..."]}}
 */
class ApiExceptionRenderer
{
    private const CODES = [
        400 => 'bad_request',
        401 => 'unauthenticated',
        403 => 'forbidden',
        404 => 'not_found',
        405 => 'method_not_allowed',
        409 => 'conflict',
        413 => 'payload_too_large',
        415 => 'unsupported_media_type',
        419 => 'csrf_token_mismatch',
        422 => 'validation_failed',
        429 => 'too_many_requests',
        503 => 'service_unavailable',
    ];

    public function render(Throwable $e, Request $request): ?JsonResponse
    {
        if ($e instanceof ValidationException) {
            return $this->respond(422, 'validation_failed', $e->getMessage(), $e->errors());
        }

        if ($e instanceof AuthenticationException) {
            return $this->respond(401, 'unauthenticated', 'Unauthenticated.');
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            return $this->respond(
                $status,
                self::CODES[$status] ?? ($status >= 500 ? 'server_error' : 'http_error'),
                $this->messageFor($e),
                headers: $e->getHeaders(),
            );
        }

        // Local debugging keeps Laravel's detailed error; every other environment stays generic.
        if (config('app.debug') === true) {
            return null;
        }

        return $this->respond(500, 'server_error', 'Server error.');
    }

    /**
     * Framework messages for 404/405/5xx can leak internals (model names, routes), so they are fixed.
     */
    private function messageFor(HttpExceptionInterface $e): string
    {
        return match (true) {
            $e->getStatusCode() >= 500 => 'Server error.',
            $e->getStatusCode() === 404 => 'Resource not found.',
            $e->getStatusCode() === 405 => 'Method not allowed.',
            $e->getStatusCode() === 429 => 'Too many requests.',
            default => $e->getMessage() !== '' ? $e->getMessage() : 'Request failed.',
        };
    }

    /**
     * @param  array<string, array<string>>  $errors
     * @param  array<string, string>  $headers
     */
    private function respond(int $status, string $code, string $message, array $errors = [], array $headers = []): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'code' => $code,
            'errors' => $errors === [] ? new stdClass : $errors,
        ], $status, $headers);
    }
}
