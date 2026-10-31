<?php

namespace App\Http\Requests\Product;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProductIndexRequest extends FormRequest
{
    public const SORTS = ['price', '-price', 'name', 'newest'];

    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 50;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'category' => ['nullable', 'string', 'max:255'],
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'string', 'in:'.implode(',', self::SORTS)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function sort(): string
    {
        return (string) ($this->validated('sort') ?? 'newest');
    }

    /**
     * Larger values are capped, not rejected.
     */
    public function perPage(): int
    {
        return min((int) ($this->validated('per_page') ?? self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE);
    }
}
