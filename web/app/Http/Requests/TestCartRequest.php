<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class TestCartRequest extends FormRequest
{
    public const MAX_LINES = 50;

    public const MAX_UNITS = 200;

    public function authorize(): bool
    {
        return true; // authenticated by the shopify.session middleware
    }

    /**
     * A line is either a real variant (looked up server-side; its own price is used) or typed (title + price).
     * Prices are plain non-negative decimals, so the integer math in toMinor() never sees an exponent or a sign.
     */
    public function rules(): array
    {
        $price = ['string', 'max:20', 'regex:/^\d+(\.\d+)?$/'];

        return [
            'lines' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'lines.*' => ['array'],
            'lines.*.variantId' => ['nullable', 'string', 'regex:#^gid://shopify/ProductVariant/\d+$#'],
            'lines.*.title' => ['required_without:lines.*.variantId', 'nullable', 'string', 'max:255'],
            'lines.*.price' => ['required_without:lines.*.variantId', 'nullable', ...$price],
            'lines.*.compareAt' => ['nullable', ...$price],
            'lines.*.quantity' => ['required', 'numeric', 'integer', 'min:1', 'max:'.self::MAX_UNITS],
        ];
    }

    /** The cap applies to the total across all lines, not to each line on its own. */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $total = array_sum(array_map(fn ($line) => (int) $line['quantity'], (array) $this->input('lines')));
            if ($total > self::MAX_UNITS) {
                $validator->errors()->add('lines', 'A sample cart can hold at most '.self::MAX_UNITS.' items in total.');
            }
        }];
    }

    /** @return list<array{variantId: ?string, title: ?string, price: ?string, compareAt: ?string, quantity: int}> */
    public function lines(): array
    {
        return array_map(fn (array $line) => [
            'variantId' => ($line['variantId'] ?? null) ?: null,
            'title' => isset($line['title']) ? trim((string) $line['title']) : null,
            'price' => isset($line['price']) ? (string) $line['price'] : null,
            'compareAt' => isset($line['compareAt']) ? (string) $line['compareAt'] : null,
            'quantity' => (int) $line['quantity'],
        ], array_values((array) $this->validated('lines')));
    }
}
