<?php

namespace App\Http\Requests\Checkout;

use App\Models\Address;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutRequest extends FormRequest
{
    /**
     * Only the two address ids and the Idempotency-Key header are read.
     * Items, prices, totals or a user_id in the body are ignored.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $ownAddress = Rule::exists('addresses', 'id')->where('user_id', $this->user()?->id);

        return [
            'idempotency_key' => ['required', 'string', 'max:64'],
            'shipping_address_id' => ['required', 'integer', $ownAddress],
            'billing_address_id' => ['nullable', 'integer', $ownAddress],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'The Idempotency-Key header is required.',
            'shipping_address_id.exists' => 'The selected shipping address is invalid.',
            'billing_address_id.exists' => 'The selected billing address is invalid.',
        ];
    }

    public function idempotencyKey(): string
    {
        return (string) $this->validated('idempotency_key');
    }

    public function shippingAddress(): Address
    {
        return Address::query()->findOrFail($this->validated('shipping_address_id'));
    }

    public function billingAddress(): Address
    {
        $id = $this->validated('billing_address_id') ?? $this->validated('shipping_address_id');

        return Address::query()->findOrFail($id);
    }
}
