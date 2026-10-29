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

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'percent' => ['required', 'integer', 'min:'.OfferConstants::get('minPercent'), 'max:'.OfferConstants::get('maxPercent')],
        ];
    }
}
