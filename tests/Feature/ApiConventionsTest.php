<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\Support\DemoRequest;

beforeEach(function () {
    // Throwaway routes that exercise each error path. No feature endpoints exist yet.
    Route::middleware('api')->prefix('api/v1/_test')->group(function () {
        Route::get('protected', fn () => response()->json(['ok' => true]))->middleware('auth:sanctum');
        Route::get('forbidden', function () {
            Gate::authorize('never');
        });
        Route::post('validate', fn (DemoRequest $request) => response()->json(['ok' => true]));
        Route::get('limited', fn () => response()->json(['ok' => true]))->middleware('throttle:1,1');
        Route::get('boom', function () {
            throw new RuntimeException('secret database password');
        });
    });
});

function expectErrorShape($response, int $status, string $code): void
{
    $response->assertStatus($status)
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonStructure(['message', 'code', 'errors'])
        ->assertJson(['code' => $code]);
}

it('serves GET /api/v1/ping as JSON, even without an Accept header', function () {
    $this->get('/api/v1/ping')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertExactJson(['status' => 'ok']);
});

it('does not serve routes outside /api/v1', function () {
    expectErrorShape($this->get('/api/ping'), 404, 'not_found');
});

it('returns a 404 JSON error for an unknown URL', function () {
    $response = $this->get('/api/v1/does-not-exist');

    expectErrorShape($response, 404, 'not_found');
    expect($response->json('message'))->toBe('Resource not found.');
});

it('returns a 401 JSON error when not logged in, without an Accept header', function () {
    expectErrorShape($this->get('/api/v1/_test/protected'), 401, 'unauthenticated');
});

it('returns a 403 JSON error when forbidden', function () {
    Gate::define('never', fn (User $user) => false);

    expectErrorShape($this->actingAs(User::factory()->create())->get('/api/v1/_test/forbidden'), 403, 'forbidden');
});

it('returns a 405 JSON error with an Allow header', function () {
    $response = $this->post('/api/v1/ping');

    expectErrorShape($response, 405, 'method_not_allowed');
    $response->assertHeader('Allow');
});

it('returns a 422 JSON error with field errors from a Form Request', function () {
    $response = $this->post('/api/v1/_test/validate', ['name' => str_repeat('x', 11)]);

    expectErrorShape($response, 422, 'validation_failed');
    $response->assertJsonValidationErrors(['name']);
    expect($response->json('errors.name'))->toBeArray();

    expectErrorShape($this->post('/api/v1/_test/validate'), 422, 'validation_failed');
});

it('returns a 429 JSON error with Retry-After when throttled', function () {
    $this->get('/api/v1/_test/limited')->assertOk();

    $response = $this->get('/api/v1/_test/limited');

    expectErrorShape($response, 429, 'too_many_requests');
    $response->assertHeader('Retry-After');
});

it('returns a generic 500 JSON error without leaking details', function () {
    config(['app.debug' => false, 'logging.default' => 'null']);

    $response = $this->get('/api/v1/_test/boom');

    expectErrorShape($response, 500, 'server_error');
    expect($response->json('message'))->toBe('Server error.');
    expect($response->getContent())
        ->not->toContain('secret database password')
        ->not->toContain('trace')
        ->not->toContain('RuntimeException');
});

it('always returns an empty errors object when there are no field errors', function () {
    expect($this->get('/api/v1/does-not-exist')->getContent())->toContain('"errors":{}');
});

it('serializes dates as ISO 8601 UTC without fractions', function () {
    $date = Carbon::parse('2026-10-29 11:54:00.123456', 'Asia/Dubai');

    expect(json_encode(['at' => $date]))->toBe('{"at":"2026-10-29T07:54:00Z"}');
});
