<?php

use App\Enums\PaymentStatus;
use App\Enums\StockReason;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\WebhookEvent;
use Illuminate\Database\QueryException;

it('creates a valid model from every batch 3 factory', function (string $model) {
    expect($model::factory()->create()->exists)->toBeTrue();
})->with([
    Payment::class,
    WebhookEvent::class,
    StockMovement::class,
    AuditLog::class,
]);

it('rejects a duplicate provider_ref', function () {
    Payment::factory()->create(['provider_ref' => 'pi_1']);

    expect(fn () => Payment::factory()->create(['provider_ref' => 'pi_1']))->toThrow(QueryException::class);
});

it('rejects a duplicate webhook event_id', function () {
    WebhookEvent::factory()->create(['event_id' => 'evt_1']);

    expect(fn () => WebhookEvent::factory()->create(['event_id' => 'evt_1']))->toThrow(QueryException::class);
});

it('does not delete an order that has a payment', function () {
    $payment = Payment::factory()->create();

    expect(fn () => $payment->order?->delete())->toThrow(QueryException::class);
});

it('does not delete a variant that has stock movements', function () {
    $movement = StockMovement::factory()->create();

    expect(fn () => $movement->variant?->delete())->toThrow(QueryException::class);
});

it('stores signed stock deltas and enum reasons', function () {
    $movement = StockMovement::factory()->create(['delta' => -2, 'reason' => StockReason::Sale]);

    expect($movement->fresh()->delta)->toBe(-2)
        ->and($movement->fresh()->reason)->toBe(StockReason::Sale);
});

it('casts payment status and JSON columns', function () {
    expect(Payment::factory()->create(['status' => PaymentStatus::Succeeded])->fresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and(WebhookEvent::factory()->create()->fresh()->payload)->toBeArray()
        ->and(AuditLog::factory()->create()->fresh()->new_values)->toBe(['name' => 'New name']);
});

it('resolves the audited model through the morph relation', function () {
    $product = Product::factory()->create();
    $log = AuditLog::factory()->create(['auditable_id' => $product->id]);

    expect($log->auditable?->is($product))->toBeTrue();
});
