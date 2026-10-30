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

    /** `numeric` first: the loose `integer` rule alone accepts JSON `true` (as 1). */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'percent' => ['required', 'numeric', 'integer', 'min:'.OfferConstants::get('minPercent'), 'max:'.OfferConstants::get('maxPercent')],
        ];
    }
}
