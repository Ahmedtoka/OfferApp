<?php

namespace App\Http\Requests;

use App\Offer\OfferConstants;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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

    private function appliesTo(): string
    {
        return $this->input('appliesTo') === 'collection' ? 'collection' : 'tag';
    }

    /**
     * `numeric` first: the loose `integer` rule alone accepts JSON `true` (as 1).
     * eligibleTag is a Shopify product tag: 1-100 chars, no commas (Shopify splits tags on commas).
     * It is required, with no default, when the offer applies to a tag; collectionId when it applies to a collection.
     * The UI before Task 11 sends only enabled/percent/eligibleTag, so the newer fields are optional.
     */
    public function rules(): array
    {
        $tag = $this->appliesTo() === 'tag';

        return [
            'enabled' => ['required', 'boolean'],
            'percent' => ['required', 'numeric', 'integer', 'min:'.OfferConstants::get('minPercent'), 'max:'.OfferConstants::get('maxPercent')],
            'method' => ['sometimes', 'nullable', Rule::in((array) OfferConstants::get('methods'))],
            'appliesTo' => ['sometimes', 'nullable', Rule::in(['tag', 'collection'])],
            'eligibleTag' => $tag ? ['required', 'string', 'min:1', 'max:100', 'not_regex:/,/'] : ['nullable', 'string', 'max:100'],
            'collectionId' => $tag ? ['nullable'] : ['required', 'string', 'regex:#^gid://shopify/Collection/\d+$#'],
            'startsAt' => ['sometimes', 'nullable', 'date'],
            'endsAt' => ['sometimes', 'nullable', 'date', $this->filled('startsAt') ? 'after:startsAt' : 'date'],
            'combineWithCoupons' => ['sometimes', 'nullable', 'boolean'],
        ];
    }

    /**
     * The validated body with defaults applied; dates are ISO 8601 in UTC (null startsAt keeps the
     * discount's own start, or now for a new one).
     *
     * @return array{enabled: bool, percent: int, method: string, appliesTo: string, eligibleTag: string, collectionId: ?string, combineWithCoupons: bool, startsAt: ?string, endsAt: ?string}
     */
    public function offer(): array
    {
        $tag = $this->appliesTo() === 'tag';

        return [
            'enabled' => $this->boolean('enabled'),
            'percent' => (int) $this->input('percent'),
            'method' => (string) ($this->input('method') ?: OfferConstants::get('defaultMethod')),
            'appliesTo' => $this->appliesTo(),
            'eligibleTag' => $tag ? (string) $this->input('eligibleTag') : '',
            'collectionId' => $tag ? null : (string) $this->input('collectionId'),
            'combineWithCoupons' => $this->boolean('combineWithCoupons'),
            'startsAt' => self::utc($this->input('startsAt')),
            'endsAt' => self::utc($this->input('endsAt')),
        ];
    }

    private static function utc(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->utc()->format('Y-m-d\TH:i:s\Z') : null;
    }

    public function messages(): array
    {
        return [
            'eligibleTag.required' => 'Enter the product tag.',
            'eligibleTag.max' => 'The tag can be at most 100 characters.',
            'eligibleTag.not_regex' => 'A tag cannot contain commas.',
            'collectionId.required' => 'Choose a collection.',
            'collectionId.regex' => 'Choose a valid collection.',
            'endsAt.after' => 'The end date must be after the start date.',
        ];
    }
}
