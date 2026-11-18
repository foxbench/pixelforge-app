<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Services\Ledger;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

const WEBHOOK_SECRET = 'whsec_test_secret';

const API = 'pixelforge.promptedit.dev/v1';

const RATES = [
    'input_text_tokens' => '5.00',
    'input_image_tokens' => '10.00',
    'output_tokens' => '40.00',
    'reasoning_tokens' => '12.50',
];

/**
 * Pixelforge's pricing endpoint, as in the docs. Call in tests that estimate costs.
 */
function fakePricing(): void
{
    Http::fake([
        API.'/pricing' => Http::response([
            'object' => 'pricing',
            'currency' => 'usd',
            'unit' => 'per_1m_tokens',
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
        ]),
    ]);
}

/**
 * A generation object as Pixelforge returns it. Override any key.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function providerGeneration(Generation $generation, string $status, array $overrides = []): array
{
    $base = [
        'id' => $generation->provider_id ?? 'gen_TEST'.$generation->id,
        'object' => 'generation',
        'status' => $status,
        'prompt' => $generation->prompt,
        'size' => $generation->size,
        'quality' => $generation->quality,
        'reference_images' => [],
        'metadata' => ['local_id' => $generation->uuid, 'user_id' => (string) $generation->user_id],
        'image_url' => null,
        'image_url_expires_at' => null,
        'usage' => null,
        'rates' => RATES,
        'error' => null,
        'created_at' => '2026-11-04T14:03:11Z',
        'started_at' => null,
        'completed_at' => null,
    ];

    if ($status === 'succeeded') {
        // Docs example: 305,510 token-cents -> provider cost 0.305510000 USD
        $base['usage'] = [
            'input_text_tokens' => 12,
            'input_image_tokens' => 765,
            'output_tokens' => 6450,
            'reasoning_tokens' => 3184,
            'total_tokens' => 10411,
        ];
        $base['image_url'] = 'https://pixelforge.promptedit.dev/images/x.png?signature=abc';
        $base['image_url_expires_at'] = '2026-11-05T14:03:29Z';
        $base['started_at'] = '2026-11-04T14:03:13Z';
        $base['completed_at'] = '2026-11-04T14:03:29Z';
    }

    if ($status === 'failed') {
        $base['error'] = ['code' => 'internal_error', 'message' => 'Something went wrong on our side.'];
        $base['completed_at'] = '2026-11-04T14:03:29Z';
    }

    return array_replace($base, $overrides);
}

/**
 * A user with a balance, and a generation with its hold already booked (as the submit step does).
 */
function heldGeneration(int $balancePico = 2_000_000_000_000, array $attributes = []): Generation
{
    $user = User::factory()->create();
    app(Ledger::class)->purchase($user, $balancePico, 'test credit');

    $generation = Generation::factory()->for($user)->create($attributes + [
        'status' => GenerationStatus::Queued,
        'provider_id' => 'gen_'.Str::random(10),
        'rates' => RATES,
    ]);
    app(Ledger::class)->hold($generation, $generation->estimated_max_charge_pico);

    return $generation;
}

/**
 * A webhook event for a generation, in Pixelforge's format.
 *
 * @param  array<string, mixed>  $snapshot
 * @return array<string, mixed>
 */
function webhookEvent(string $eventId, string $type, array $snapshot): array
{
    return [
        'id' => $eventId,
        'object' => 'event',
        'type' => $type,
        'created_at' => '2026-11-04T14:03:29Z',
        'data' => ['object' => $snapshot],
    ];
}

/**
 * POST a signed webhook to the app. $timestamp / $secret can be changed to test bad signatures.
 *
 * @param  array<string, mixed>  $event
 */
function postWebhook(TestCase $test, array $event, ?int $timestamp = null, string $secret = WEBHOOK_SECRET)
{
    config(['services.pixelforge.webhook_secret' => WEBHOOK_SECRET]);

    $body = json_encode($event);
    $timestamp ??= time();
    $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

    return $test->call('POST', '/webhooks/pixelforge', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}",
    ], $body);
}
