<?php

namespace App\Http\Requests;

use App\Offer\OfferConstants;
use Illuminate\Foundation\Http\FormRequest;

class SaveSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authenticated by the shopify.session middleware
    }

    /** The tag is trimmed before validation, so " FallOffer " is saved as "FallOffer". */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('eligibleTag'))) {
            $this->merge(['eligibleTag' => trim($this->input('eligibleTag'))]);
        }
    }

    /**
     * `numeric` first: the loose `integer` rule alone accepts JSON `true` (as 1).
     * eligibleTag is a Shopify product tag: 1-100 chars, no commas (Shopify splits tags on commas).
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'percent' => ['required', 'numeric', 'integer', 'min:'.OfferConstants::get('minPercent'), 'max:'.OfferConstants::get('maxPercent')],
            'eligibleTag' => ['required', 'string', 'min:1', 'max:100', 'not_regex:/,/'],
        ];
    }

    public function messages(): array
    {
        return [
            'eligibleTag.required' => 'Enter the product tag.',
            'eligibleTag.max' => 'The tag can be at most 100 characters.',
            'eligibleTag.not_regex' => 'A tag cannot contain commas.',
        ];
    }
}
