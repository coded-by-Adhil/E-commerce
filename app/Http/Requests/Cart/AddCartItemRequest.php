<?php

namespace App\Http\Requests\Cart;

use App\Models\Product;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddCartItemRequest extends FormRequest
{
    /**
     * Only variant_id and quantity are read. cart_id, user_id or a price in the body are ignored.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'variant_id' => [
                'required',
                'integer',
                Rule::exists('variants', 'id')->where(
                    fn ($query) => $query->whereIn('product_id', Product::query()->active()->select('id'))
                ),
            ],
            'quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'variant_id.exists' => 'This item is not available.',
        ];
    }
}
