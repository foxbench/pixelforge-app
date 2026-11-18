<?php

use App\Models\User;
use App\Services\CostEstimator;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    fakePricing();
});

it('estimates the documented worked example (max 0.373875 provider, 0.46734375 user)', function () {
    $estimate = app(CostEstimator::class)->estimate(400, '1536x1024', 'high', 2);

    expect($estimate->maxCostPico)->toBe(Money::parse('0.373875'))
        ->and($estimate->maxChargePico)->toBe(Money::parse('0.46734375'));
});

it('estimates a typical and a maximum cost', function () {
    // medium 1024x1024, 400 chars, no images
    // typical: 100 text + 1100 output + 1100 reasoning (midpoint of 200..2000)
    //   = 100*5 + 1100*40 + 1100*12.5 per million = 0.058250000
    // maximum: 115 text + 1100 output + 2000 reasoning = 0.069575000
    $estimate = app(CostEstimator::class)->estimate(400, '1024x1024', 'medium', 0);

    expect($estimate->typicalCostPico)->toBe(Money::parse('0.05825'))
        ->and($estimate->typicalChargePico)->toBe(Money::parse('0.0728125'))
        ->and($estimate->maxCostPico)->toBe(Money::parse('0.069575'))
        ->and($estimate->maxChargePico)->toBe(Money::parse('0.08696875'))
        ->and($estimate->pricingSource)->toBe('live');
});

it('counts text tokens as characters / 4 rounded up', function () {
    // 1 character still costs one token; the maximum rounds the +15% up too (1 -> 2)
    $one = app(CostEstimator::class)->estimate(1, '1024x1024', 'low', 0);
    $zero = app(CostEstimator::class)->estimate(0, '1024x1024', 'low', 0);

    // 1 typical text token = 5 per million = 0.000005 provider cost more than an empty prompt
    expect($one->typicalCostPico - $zero->typicalCostPico)->toBe(Money::parse('0.000005'))
        ->and($one->maxCostPico - $zero->maxCostPico)->toBe(Money::parse('0.00001'));
});

it('reads rates from the pricing endpoint and caches them for ten minutes', function () {
    app(CostEstimator::class)->estimate(10, '1024x1024', 'low', 0);
    app(CostEstimator::class)->estimate(20, '1024x1024', 'low', 0);

    Http::assertSentCount(1);
});

it('falls back to labelled constants when the pricing endpoint is down', function () {
    Cache::flush();
    Http::swap(new Illuminate\Http\Client\Factory); // drop the stub from beforeEach
    Http::fake([API.'/pricing' => Http::response('down', 503)]);

    $estimate = app(CostEstimator::class)->estimate(400, '1536x1024', 'high', 2);

    expect($estimate->pricingSource)->toBe('fallback')
        ->and($estimate->maxCostPico)->toBe(Money::parse('0.373875'));
});

it('serves the live estimate to the form and says whether the user can afford it', function () {
    $user = User::factory()->create(['balance_pico' => Money::parse('0.05')]);

    $this->actingAs($user)
        ->getJson('/generations/estimate?chars=400&size=1024x1024&quality=medium&images=0')
        ->assertOk()
        ->assertJson([
            'typical' => ['exact' => '0.0728125'],
            'max' => ['exact' => '0.08696875'],
            'affordable' => false,
            'pricing_source' => 'live',
        ]);
});

it('requires login and valid parameters for the estimate', function () {
    $this->getJson('/generations/estimate?chars=1&size=1024x1024&quality=low&images=0')->assertUnauthorized();

    $this->actingAs(User::factory()->create())
        ->getJson('/generations/estimate?chars=1&size=huge&quality=low&images=0')
        ->assertUnprocessable();
});
