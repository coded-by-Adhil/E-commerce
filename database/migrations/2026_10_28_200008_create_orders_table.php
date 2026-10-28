<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->char('number', 26)->unique(); // public ULID (D2)
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->char('currency', 3);
            $table->unsignedInteger('subtotal'); // minor units (D1)
            $table->unsignedInteger('shipping_total');
            $table->unsignedInteger('grand_total');
            $table->json('shipping_address'); // snapshot (D3)
            $table->json('billing_address'); // snapshot (D3)
            $table->string('idempotency_key', 64);
            $table->timestamp('placed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key']); // D5
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
