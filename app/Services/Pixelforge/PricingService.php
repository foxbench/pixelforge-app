<?php

namespace App\Services\Pixelforge;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rates and token rules come from GET /v1/pricing (cached for 10 minutes), never from constants.
 * FALLBACK is only used when that endpoint is unreachable, and the estimate is then labelled
 * "fallback" in the UI. Actual charges never use it: they use the rates stored on each generation.
 */
class PricingService
{
    private const CACHE_KEY = 'pixelforge.pricing';

    private const FALLBACK = [
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'tokens' => [
            'characters_per_text_token' => 4,
            'text_token_variance' => 0.15,
            'per_reference_image' => 765,
            'output' => [
                'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
            ],
            'reasoning' => [
                'low' => ['min' => 0, 'max' => 500],
                'medium' => ['min' => 200, 'max' => 2000],
                'high' => ['min' => 1000, 'max' => 8000],
            ],
        ],
    ];

    public function __construct(private PixelforgeClient $client) {}

    /**
     * @return array{rates: array<string, string>, tokens: array<string, mixed>, source: string}
     */
    public function get(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $response = $this->client->pricing();
            $pricing = [
                'rates' => $response['rates'],
                'tokens' => $response['tokens'],
                'source' => 'live',
            ];
            // Touch the fields we rely on, so an unexpected response shape falls back instead of
            // producing nonsense estimates.
            $pricing['tokens']['output']['medium']['1024x1024'];
            $pricing['tokens']['reasoning']['medium']['max'];
            $pricing['tokens']['per_reference_image'];
            $pricing['rates']['output_tokens'];
        } catch (Throwable $e) {
            Log::warning('Pixelforge pricing unavailable, using fallback constants: '.$e->getMessage());

            return self::FALLBACK + ['source' => 'fallback'];
        }

        Cache::put(self::CACHE_KEY, $pricing, now()->addMinutes(10));

        return $pricing;
    }
}
