<?php

use App\Http\Middleware\ValidateCsrfTokenWithDevBypass;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

/** Same middleware, but without the "CSRF is skipped while testing" shortcut, so 419 can be observed. */
class StrictCsrfForTests extends ValidateCsrfTokenWithDevBypass
{
    protected function runningUnitTests()
    {
        return false;
    }
}

function bypassRequest(?string $secret): Request
{
    $request = Request::create('/x', 'POST');
    if ($secret !== null) {
        $request->headers->set(ValidateCsrfTokenWithDevBypass::HEADER, $secret);
    }

    return $request;
}

function useEnv(string $env, ?string $secret): void
{
    app()['env'] = $env;
    config(['sanctum.dev_csrf_bypass_token' => $secret]);
}

it('bypasses only in local with a matching secret', function () {
    useEnv('local', 'dev-secret');

    expect(app(ValidateCsrfTokenWithDevBypass::class)->shouldBypass(bypassRequest('dev-secret')))->toBeTrue();
});

it('does not bypass with a wrong or missing secret', function (?string $sent) {
    useEnv('local', 'dev-secret');

    expect(app(ValidateCsrfTokenWithDevBypass::class)->shouldBypass(bypassRequest($sent)))->toBeFalse();
})->with(['wrong' => 'nope', 'missing' => null, 'empty' => '']);

it('does not bypass when no secret is configured, even with an empty header', function (?string $configured) {
    useEnv('local', $configured);

    expect(app(ValidateCsrfTokenWithDevBypass::class)->shouldBypass(bypassRequest('')))->toBeFalse();
})->with(['null' => null, 'empty string' => '']);

it('does not bypass outside local', function (string $env) {
    useEnv($env, 'dev-secret');

    expect(app(ValidateCsrfTokenWithDevBypass::class)->shouldBypass(bypassRequest('dev-secret')))->toBeFalse();
})->with(['testing', 'staging', 'production']);

it('enforces CSRF through the real stack unless the bypass applies', function () {
    Route::middleware([StartSession::class, StrictCsrfForTests::class])->post('api/v1/_test/csrf', fn () => response()->json(['ok' => true]));
    $post = fn (array $headers = []) => $this->postJson('/api/v1/_test/csrf', [], $headers);

    useEnv('testing', 'dev-secret');
    $post([ValidateCsrfTokenWithDevBypass::HEADER => 'dev-secret'])->assertStatus(419);

    useEnv('local', 'dev-secret');
    $post()->assertStatus(419);
    $post([ValidateCsrfTokenWithDevBypass::HEADER => 'wrong'])->assertStatus(419);
    $post([ValidateCsrfTokenWithDevBypass::HEADER => 'dev-secret'])->assertOk();
});
