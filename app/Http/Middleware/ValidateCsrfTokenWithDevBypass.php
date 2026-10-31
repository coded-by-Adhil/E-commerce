<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;

/**
 * Local-only convenience for API clients (Postman) that cannot carry a changing CSRF token.
 * Off unless APP_ENV=local AND DEV_CSRF_BYPASS_TOKEN is set; a real CSRF token always still works.
 */
class ValidateCsrfTokenWithDevBypass extends ValidateCsrfToken
{
    public const HEADER = 'X-Dev-Csrf-Bypass';

    public function shouldBypass(Request $request): bool
    {
        $secret = config('sanctum.dev_csrf_bypass_token');

        return $this->app->environment('local')
            && is_string($secret) && $secret !== ''
            && hash_equals($secret, (string) $request->header(self::HEADER));
    }

    protected function tokensMatch($request)
    {
        return $this->shouldBypass($request) || parent::tokensMatch($request);
    }
}
