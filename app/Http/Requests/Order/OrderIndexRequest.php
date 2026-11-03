<?php

namespace App\Http\Requests\Order;

use App\Enums\OrderStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrderIndexRequest extends FormRequest
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 50;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function status(): ?OrderStatus
    {
        $status = $this->validated('status');

        return is_string($status) ? OrderStatus::from($status) : null;
    }

    /**
     * Larger values are capped, not rejected.
     */
    public function perPage(): int
    {
        return min((int) ($this->validated('per_page') ?? self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE);
    }
}
