<?php

namespace Tests\Unit\Shopify;

use App\Shopify\InvalidSessionToken;
use App\Shopify\SessionToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\SessionTokens;

class SessionTokenTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private function verify(string $jwt): array
    {
        return SessionToken::verify($jwt, SessionTokens::API_KEY, SessionTokens::API_SECRET, SessionTokens::ALLOWED_SHOPS, self::NOW);
    }

    public function test_valid_token_returns_shop_and_sub(): void
    {
        $result = $this->verify(SessionTokens::mint([], self::NOW));

        $this->assertSame(['shop' => 'levoilestores.myshopify.com', 'sub' => '42'], $result);
    }

    public function test_shop_host_is_lower_cased(): void
    {
        $jwt = SessionTokens::mint(['iss' => 'https://LeVoilest.myshopify.com/admin', 'dest' => 'https://LeVoilest.myshopify.com'], self::NOW);

        $this->assertSame('levoilest.myshopify.com', $this->verify($jwt)['shop']);
    }

    public static function invalidTokens(): array
    {
        $now = self::NOW;

        return [
            'wrong aud' => [fn () => SessionTokens::mint(['aud' => 'other-app'], $now)],
            'expired' => [fn () => SessionTokens::mint(['exp' => $now - 1], $now)],
            'exp equals now' => [fn () => SessionTokens::mint(['exp' => $now], $now)],
            'not yet valid beyond clock tolerance' => [fn () => SessionTokens::mint(['nbf' => $now + 11], $now)],
            'missing exp' => [fn () => SessionTokens::mint(['exp' => null], $now)],
            'missing nbf' => [fn () => SessionTokens::mint(['nbf' => null], $now)],
            'wrong signature' => [fn () => SessionTokens::mint([], $now, 'wrong-secret')],
            'dest not allowed' => [fn () => SessionTokens::mint(['iss' => 'https://evil.myshopify.com/admin', 'dest' => 'https://evil.myshopify.com'], $now)],
            'iss host differs from dest' => [fn () => SessionTokens::mint(['iss' => 'https://evil.myshopify.com/admin'], $now)],
            'dest not a myshopify host' => [fn () => SessionTokens::mint(['dest' => 'https://levoilestores.myshopify.com.evil.com', 'iss' => 'https://levoilestores.myshopify.com.evil.com/admin'], $now)],
            'alg none' => [fn () => SessionTokens::mint([], $now, SessionTokens::API_SECRET, ['alg' => 'none'])],
            'alg RS256' => [fn () => SessionTokens::mint([], $now, SessionTokens::API_SECRET, ['alg' => 'RS256', 'typ' => 'JWT'])],
            'string exp' => [fn () => SessionTokens::mint(['exp' => (string) ($now + 60)], $now)],
            'tampered payload' => [function () use ($now) {
                [$h, , $s] = explode('.', SessionTokens::mint([], $now));
                $p = SessionTokens::b64(json_encode(SessionTokens::claims(['sub' => '1'], $now)));

                return "{$h}.{$p}.{$s}";
            }],
            'garbage' => [fn () => 'not-a-jwt'],
            'empty' => [fn () => ''],
        ];
    }

    /**
     * A token minted in Shopify's current second must be accepted even when this server's clock
     * runs slightly behind Shopify's (observed: ~0.5 s on the dev machine), otherwise the first
     * page load intermittently fails with 401.
     */
    public function test_nbf_slightly_ahead_of_local_clock_is_accepted(): void
    {
        foreach ([1, 5, 10] as $ahead) {
            $jwt = SessionTokens::mint(['nbf' => self::NOW + $ahead, 'iat' => self::NOW + $ahead], self::NOW);
            $this->assertSame(SessionTokens::SHOP, $this->verify($jwt)['shop'], "nbf +{$ahead}s");
        }
    }

    #[DataProvider('invalidTokens')]
    public function test_invalid_tokens_are_rejected(\Closure $make): void
    {
        $this->expectException(InvalidSessionToken::class);
        $this->verify($make());
    }

    public function test_empty_allow_list_accepts_any_valid_shop(): void
    {
        $jwt = SessionTokens::mint(['iss' => 'https://any-store.myshopify.com/admin', 'dest' => 'https://any-store.myshopify.com'], self::NOW);

        $result = SessionToken::verify($jwt, SessionTokens::API_KEY, SessionTokens::API_SECRET, [], self::NOW);

        $this->assertSame('any-store.myshopify.com', $result['shop']);
    }

    public function test_empty_allow_list_still_rejects_lookalike_hosts(): void
    {
        $jwt = SessionTokens::mint(['iss' => 'https://x.myshopify.com.evil.com/admin', 'dest' => 'https://x.myshopify.com.evil.com'], self::NOW);

        $this->expectException(InvalidSessionToken::class);
        SessionToken::verify($jwt, SessionTokens::API_KEY, SessionTokens::API_SECRET, [], self::NOW);
    }

    public function test_empty_secret_is_rejected_even_with_open_allow_list(): void
    {
        $this->expectException(InvalidSessionToken::class);
        SessionToken::verify(SessionTokens::mint([], self::NOW, ''), SessionTokens::API_KEY, '', [], self::NOW);
    }

    public function test_empty_secret_is_rejected(): void
    {
        $this->expectException(InvalidSessionToken::class);
        SessionToken::verify(SessionTokens::mint([], self::NOW, ''), SessionTokens::API_KEY, '', SessionTokens::ALLOWED_SHOPS, self::NOW);
    }
}
