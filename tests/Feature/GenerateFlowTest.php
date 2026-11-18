<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Services\Ledger;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

it('goes from the form to a settled generation on the history page', function () {
    Cache::flush();
    fakePricing();
    // tests run with QUEUE_CONNECTION=sync, so the submit job runs right inside the POST
    Http::fake([
        API.'/generations' => function ($request) {
            return Http::response([
                'id' => 'gen_FLOW1', 'object' => 'generation', 'status' => 'queued',
                'metadata' => $request['metadata'], 'rates' => RATES, 'created_at' => '2026-11-04T14:03:11Z',
            ], 202);
        },
    ]);
    $user = User::factory()->create(['balance_pico' => Money::parse('2')]);

    $this->actingAs($user)->post('/generations', [
        'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
        'size' => '1024x1024',
        'quality' => 'medium',
        'reference_images' => ['', '', '', ''],
    ])->assertRedirect('/generations');

    $generation = Generation::sole();
    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->provider_id)->toBe('gen_FLOW1');

    // shown as unfinished while queued
    $this->actingAs($user->fresh())->get('/generations')
        ->assertInertia(fn (Assert $page) => $page
            ->component('generations/Index')
            ->where('generations.0.status', 'queued')
            ->where('generations.0.is_final', false)
            ->where('generations.0.charge', null)
            ->where('generations.0.estimated_max.exact', '0.0863375'));

    // the webhook settles it
    postWebhook($this, webhookEvent('evt_flow', 'generation.succeeded', providerGeneration($generation, 'succeeded', ['id' => 'gen_FLOW1'])))
        ->assertNoContent();

    $this->actingAs($user->fresh())->get('/generations')
        ->assertInertia(fn (Assert $page) => $page
            ->where('generations.0.status', 'succeeded')
            ->where('generations.0.is_final', true)
            ->where('generations.0.charge.exact', '0.3818875')
            ->where('auth.balance.exact', Money::exact(Money::parse('2') - Money::parse('0.3818875'))));
});

it('shows a provider rejection clearly and refunds the hold', function () {
    Cache::flush();
    fakePricing();
    Http::fake([API.'/generations' => Http::response(['error' => ['code' => 'content_policy_violation', 'message' => 'The prompt was rejected.']], 400)]);
    $user = User::factory()->create(['balance_pico' => Money::parse('2')]);

    $this->actingAs($user)->post('/generations', [
        'prompt' => 'something [blocked]', 'size' => '1024x1024', 'quality' => 'low', 'reference_images' => [],
    ])->assertRedirect('/generations');

    $this->actingAs($user->fresh())->get('/generations')
        ->assertInertia(fn (Assert $page) => $page
            ->where('generations.0.status', 'failed')
            ->where('generations.0.error_code', 'content_policy_violation')
            ->where('generations.0.error_message', 'The prompt was rejected.')
            ->where('generations.0.charge.exact', '0.00')
            ->where('auth.balance.exact', '2.00'));
});

it('only shows a user their own generations', function () {
    $mine = heldGeneration();
    heldGeneration();

    $this->actingAs($mine->user)->get('/generations')
        ->assertInertia(fn (Assert $page) => $page->has('generations', 1)->where('generations.0.id', $mine->id));
});

it('makes a double charge impossible even if application code tried', function () {
    $generation = heldGeneration();
    $ledger = app(Ledger::class);

    $ledger->charge($generation, 1000);
    // the unique index on (generation_id, type) refuses the second charge...
    expect(fn () => $ledger->charge($generation, 1000))->toThrow(UniqueConstraintViolationException::class);

    // ...and because ledger row and balance change in one transaction, the balance did not move either
    $user = $generation->user->fresh();
    expect($user->balance_pico)->toBe($user->creditTransactions()->sum('amount_pico'));
});
