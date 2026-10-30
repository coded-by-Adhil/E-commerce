<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    // Browser-like client: the SPA origin makes Sanctum treat requests as stateful, and cookies persist.
    $this->withHeaders(['Referer' => 'http://localhost:5173'])->withCredentials();

    // Throwaway route to prove the admin gate; no admin feature exists yet.
    Route::middleware(['api', 'auth:sanctum', 'admin'])->get('api/v1/_test/admin', fn () => response()->json(['ok' => true]));
});

function registerPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'secret-pass-1',
        'password_confirmation' => 'secret-pass-1',
    ], $overrides);
}

it('registers a customer and logs them in', function () {
    $this->postJson('/api/v1/register', registerPayload())
        ->assertCreated()
        ->assertJsonPath('data.email', 'ada@example.com')
        ->assertJsonPath('data.role', 'customer')
        ->assertJsonPath('message', 'Successfully registered.')
        ->assertJsonMissingPath('data.password');

    // New request, new user instance (the test app reuses the one created above, which Laravel answers with 201).
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.email', 'ada@example.com');
});

it('ignores a client-sent role', function () {
    $this->postJson('/api/v1/register', registerPayload(['role' => 'admin']))
        ->assertCreated()
        ->assertJsonPath('data.role', 'customer');

    expect(User::firstOrFail()->role)->toBe(UserRole::Customer);
});

it('rejects a duplicate email, case-insensitively', function () {
    User::factory()->create(['email' => 'ada@example.com']);

    $this->postJson('/api/v1/register', registerPayload(['email' => 'ADA@example.com']))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrors('email');
});

it('validates the register fields', function (array $overrides, string $field) {
    $this->postJson('/api/v1/register', registerPayload($overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'missing name' => [['name' => ''], 'name'],
    'bad email' => [['email' => 'nope'], 'email'],
    'email without domain suffix' => [['email' => 'example@gm'], 'email'],
    'short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
    'unconfirmed password' => [['password_confirmation' => 'different-pass'], 'password'],
]);

it('stores the password hashed', function () {
    $this->postJson('/api/v1/register', registerPayload())->assertCreated();

    expect(User::firstOrFail()->password)->not->toBe('secret-pass-1');
});

it('logs in with valid credentials', function () {
    User::factory()->create(['email' => 'ada@example.com', 'password' => 'secret-pass-1']);

    $this->postJson('/api/v1/login', ['email' => 'ADA@example.com', 'password' => 'secret-pass-1'])
        ->assertOk()
        ->assertJsonPath('data.email', 'ada@example.com');

    $this->getJson('/api/v1/me')->assertOk();
});

it('gives the same generic 422 for a wrong password and an unknown email', function () {
    User::factory()->create(['email' => 'ada@example.com']);

    $wrongPassword = $this->postJson('/api/v1/login', ['email' => 'ada@example.com', 'password' => 'wrong-pass-1']);
    $unknownEmail = $this->postJson('/api/v1/login', ['email' => 'ghost@example.com', 'password' => 'wrong-pass-1']);

    $wrongPassword->assertUnprocessable()->assertJsonPath('code', 'validation_failed');
    expect($unknownEmail->json('errors.email'))->toBe($wrongPassword->json('errors.email'));
});

it('throttles login to 5 attempts per email and IP', function () {
    User::factory()->create(['email' => 'ada@example.com']);
    $attempt = fn () => $this->postJson('/api/v1/login', ['email' => 'ada@example.com', 'password' => 'wrong-pass-1']);

    foreach (range(1, 5) as $ignored) {
        $attempt()->assertUnprocessable();
    }

    $attempt()->assertStatus(429)->assertJsonPath('code', 'too_many_requests')->assertHeader('Retry-After');
});

it('does not throttle a different email', function () {
    foreach (range(1, 5) as $ignored) {
        $this->postJson('/api/v1/login', ['email' => 'a@example.com', 'password' => 'x'])->assertUnprocessable();
    }

    $this->postJson('/api/v1/login', ['email' => 'b@example.com', 'password' => 'x'])->assertUnprocessable();
});

it('regenerates the session id on login', function () {
    User::factory()->create(['email' => 'ada@example.com', 'password' => 'secret-pass-1']);
    $this->getJson('/api/v1/me');
    $before = session()->getId();

    $this->postJson('/api/v1/login', ['email' => 'ada@example.com', 'password' => 'secret-pass-1'])->assertOk();

    expect(session()->getId())->not->toBe($before);
});

it('returns 401 on me for a guest, the user when logged in, and 401 after logout', function () {
    $this->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');

    $this->postJson('/api/v1/login', ['email' => User::factory()->create(['password' => 'secret-pass-1'])->email, 'password' => 'secret-pass-1'])->assertOk();
    $this->getJson('/api/v1/me')->assertOk();

    $this->postJson('/api/v1/logout')->assertNoContent();

    app('auth')->forgetGuards();
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('rejects logout for a guest', function () {
    $this->postJson('/api/v1/logout')->assertUnauthorized();
});

it('guards the admin route: 401 guest, 403 customer, 200 admin', function () {
    $this->getJson('/api/v1/_test/admin')->assertUnauthorized();

    $this->actingAs(User::factory()->create())->getJson('/api/v1/_test/admin')
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden');

    $this->actingAs(User::factory()->admin()->create())->getJson('/api/v1/_test/admin')->assertOk();
});

it('returns a clear 400, not a 500, when the request is not from the SPA origin', function () {
    $this->withHeaders(['Referer' => 'http://evil.example'])
        ->postJson('/api/v1/register', registerPayload())
        ->assertStatus(400)
        ->assertJsonPath('code', 'bad_request');
});
