<?php

use App\Models\Address;
use App\Models\User;

beforeEach(function () {
    // Stateful (SPA) requests, as in AuthTest.
    $this->withHeaders(['Referer' => 'http://localhost:5173']);
});

function addressPayload(array $overrides = []): array
{
    return array_merge([
        'line1' => '1 Main St',
        'city' => 'Springfield',
        'postal_code' => '12345',
        'country' => 'US',
    ], $overrides);
}

function defaultCount(User $user): int
{
    return $user->addresses()->where('is_default', true)->count();
}

it('returns 401 to a guest on every endpoint', function (string $method, string $uri) {
    $this->json($method, $uri, addressPayload())->assertUnauthorized();
})->with([
    ['GET', '/api/v1/addresses'],
    ['POST', '/api/v1/addresses'],
    ['GET', '/api/v1/addresses/1'],
    ['PUT', '/api/v1/addresses/1'],
    ['DELETE', '/api/v1/addresses/1'],
]);

it('creates an address for the logged-in user and ignores a client user_id', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/addresses', addressPayload(['user_id' => $other->id, 'line2' => 'Apt 4']))
        ->assertCreated()
        ->assertJsonPath('data.line1', '1 Main St')
        ->assertJsonPath('data.line2', 'Apt 4')
        ->assertJsonMissingPath('data.user_id');

    expect($user->addresses()->count())->toBe(1)
        ->and($other->addresses()->count())->toBe(0);
});

it('makes the first address default whatever the request says', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/addresses', addressPayload(['is_default' => false]))
        ->assertCreated()
        ->assertJsonPath('data.is_default', true);
});

it('keeps the second address non-default unless asked, then leaves exactly one default', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $first = $this->postJson('/api/v1/addresses', addressPayload())->json('data.id');
    $this->postJson('/api/v1/addresses', addressPayload())->assertJsonPath('data.is_default', false);

    $third = $this->postJson('/api/v1/addresses', addressPayload(['is_default' => true]))
        ->assertCreated()
        ->assertJsonPath('data.is_default', true)
        ->json('data.id');

    expect(defaultCount($user))->toBe(1)
        ->and(Address::findOrFail($third)->is_default)->toBeTrue()
        ->and(Address::findOrFail($first)->is_default)->toBeFalse();
});

it('uppercases the country', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/api/v1/addresses', addressPayload(['country' => 'us']))
        ->assertCreated()
        ->assertJsonPath('data.country', 'US');
});

it('rejects a missing required field or an invalid country', function (array $overrides, string $field) {
    $this->actingAs(User::factory()->create())
        ->postJson('/api/v1/addresses', addressPayload($overrides))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrors($field);
})->with([
    'no line1' => [['line1' => ''], 'line1'],
    'no city' => [['city' => ''], 'city'],
    'no postal_code' => [['postal_code' => ''], 'postal_code'],
    'no country' => [['country' => ''], 'country'],
    'country too long' => [['country' => 'USA'], 'country'],
    'country too short' => [['country' => 'U'], 'country'],
    'country digits' => [['country' => '1A'], 'country'],
    'is_default not boolean' => [['is_default' => 'maybe'], 'is_default'],
]);

it('lists only the user\'s own addresses in data, without pagination', function () {
    $user = User::factory()->create();
    Address::factory()->count(2)->for($user)->create();
    Address::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/addresses')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonMissingPath('meta');
});

it('shows the owner their address', function () {
    $address = Address::factory()->create();

    $this->actingAs($address->user)
        ->getJson("/api/v1/addresses/{$address->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $address->id);
});

it('returns 404 for an address that does not exist', function () {
    $this->actingAs(User::factory()->create())->getJson('/api/v1/addresses/999')->assertNotFound();
});

it('returns 403 to another user on show, update and delete', function (string $method, array $body) {
    $address = Address::factory()->create();

    $this->actingAs(User::factory()->create())
        ->json($method, "/api/v1/addresses/{$address->id}", $body)
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden');

    expect(Address::find($address->id))->not->toBeNull();
})->with([
    'show' => ['GET', []],
    'update' => ['PUT', addressPayload(['city' => 'Hacked'])],
    'delete' => ['DELETE', []],
]);

it('updates an address and ignores a client user_id', function () {
    $address = Address::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($address->user)
        ->putJson("/api/v1/addresses/{$address->id}", addressPayload(['city' => 'Shelbyville', 'user_id' => $other->id]))
        ->assertOk()
        ->assertJsonPath('data.city', 'Shelbyville');

    expect($address->fresh()->user_id)->toBe($address->user_id);
});

it('validates on update', function () {
    $address = Address::factory()->create();

    $this->actingAs($address->user)
        ->putJson("/api/v1/addresses/{$address->id}", addressPayload(['country' => 'USA']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('country');
});

it('moves the default when another address is updated with is_default=true', function () {
    $user = User::factory()->create();
    $old = Address::factory()->for($user)->create(['is_default' => true]);
    $new = Address::factory()->for($user)->create();

    $this->actingAs($user)
        ->putJson("/api/v1/addresses/{$new->id}", addressPayload(['is_default' => true]))
        ->assertOk()
        ->assertJsonPath('data.is_default', true);

    expect(defaultCount($user))->toBe(1)
        ->and($old->fresh()->is_default)->toBeFalse();
});

it('ignores updating the default address to is_default=false', function () {
    $address = Address::factory()->create(['is_default' => true]);

    $this->actingAs($address->user)
        ->putJson("/api/v1/addresses/{$address->id}", addressPayload(['is_default' => false]))
        ->assertOk()
        ->assertJsonPath('data.is_default', true);

    expect($address->fresh()->is_default)->toBeTrue();
});

it('promotes the most recent remaining address when the default is deleted', function () {
    $user = User::factory()->create();
    $default = Address::factory()->for($user)->create(['is_default' => true]);
    Address::factory()->for($user)->create();
    $latest = Address::factory()->for($user)->create();

    $this->actingAs($user)->deleteJson("/api/v1/addresses/{$default->id}")->assertNoContent();

    expect(Address::find($default->id))->toBeNull()
        ->and($latest->fresh()->is_default)->toBeTrue()
        ->and(defaultCount($user))->toBe(1);
});

it('keeps the default when a non-default address is deleted', function () {
    $user = User::factory()->create();
    $default = Address::factory()->for($user)->create(['is_default' => true]);
    $other = Address::factory()->for($user)->create();

    $this->actingAs($user)->deleteJson("/api/v1/addresses/{$other->id}")->assertNoContent();

    expect($default->fresh()->is_default)->toBeTrue();
});

it('deletes the last address without error', function () {
    $address = Address::factory()->create(['is_default' => true]);

    $this->actingAs($address->user)->deleteJson("/api/v1/addresses/{$address->id}")->assertNoContent();

    expect(Address::count())->toBe(0);
});
