<?php

namespace App\Services;

use Carbon\CarbonInterface;

/** The merchant-facing state of the offer: the switch combined with the discount's dates. */
final class OfferState
{
    /**
     * @param  array{enabled?: bool}  $settings
     * @param  array{status?: string, startsAt?: ?string, endsAt?: ?string}|null  $discount
     * @return 'off'|'scheduled'|'running'|'ended'
     */
    public static function from(array $settings, ?array $discount, CarbonInterface $now): string
    {
        if (! ($settings['enabled'] ?? false) || $discount === null) {
            return 'off';
        }

        $startsAt = self::date($discount['startsAt'] ?? null);
        $endsAt = self::date($discount['endsAt'] ?? null);

        if ($startsAt !== null || $endsAt !== null) {
            if ($startsAt !== null && $startsAt->greaterThan($now)) {
                return 'scheduled';
            }

            return $endsAt !== null && $endsAt->lessThanOrEqualTo($now) ? 'ended' : 'running';
        }

        // Shopify reported no dates: its status is all there is.
        return match ($discount['status'] ?? '') {
            'SCHEDULED' => 'scheduled',
            'EXPIRED' => 'ended',
            default => 'running',
        };
    }

    private static function date(?string $value): ?\Carbon\CarbonImmutable
    {
        return $value === null || $value === '' ? null : \Carbon\CarbonImmutable::parse($value);
    }
}
