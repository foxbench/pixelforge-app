<?php

use App\Enums\GenerationStatus;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Support\Money;

// ---------------------------------------------------------------- signature

it('accepts a correctly signed event', function () {
    $generation = heldGeneration();

    postWebhook($this, webhookEvent('evt_1', 'generation.processing', providerGeneration($generation, 'processing')))
        ->assertNoContent();

    expect($generation->fresh()->status)->toBe(GenerationStatus::Processing);
});

it('rejects a wrong signature with 400 and changes nothing', function () {
    $generation = heldGeneration();

    postWebhook($this, webhookEvent('evt_1', 'generation.succeeded', providerGeneration($generation, 'succeeded')), secret: 'whsec_wrong')
        ->assertStatus(400);

    expect($generation->fresh()->status)->toBe(GenerationStatus::Queued)
        ->and(WebhookEvent::count())->toBe(0);
});

it('rejects a stale timestamp (replay) with 400', function () {
    $generation = heldGeneration();

    postWebhook($this, webhookEvent('evt_1', 'generation.succeeded', providerGeneration($generation, 'succeeded')), timestamp: time() - 301)
        ->assertStatus(400);

    expect($generation->fresh()->status)->toBe(GenerationStatus::Queued);
});

it('rejects a body that was changed after signing', function () {
    $generation = heldGeneration();
    config(['services.pixelforge.webhook_secret' => WEBHOOK_SECRET]);

    $body = json_encode(webhookEvent('evt_1', 'generation.failed', providerGeneration($generation, 'failed')));
    $signature = hash_hmac('sha256', time().'.'.$body, WEBHOOK_SECRET);

    $this->call('POST', '/webhooks/pixelforge', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => 't='.time().',v1='.$signature,
    ], str_replace('failed', 'succeeded', $body))->assertStatus(400);
});

it('rejects a missing signature header and refuses everything when no secret is configured', function () {
    $generation = heldGeneration();
    $event = webhookEvent('evt_1', 'generation.processing', providerGeneration($generation, 'processing'));

    config(['services.pixelforge.webhook_secret' => WEBHOOK_SECRET]);
    $this->postJson('/webhooks/pixelforge', $event)->assertStatus(400);

    // an empty secret would make a forged signature easy: it must fail closed, not open
    config(['services.pixelforge.webhook_secret' => '']);
    $forged = hash_hmac('sha256', time().'.'.json_encode($event), '');
    $this->call('POST', '/webhooks/pixelforge', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => 't='.time().',v1='.$forged,
    ], json_encode($event))->assertStatus(500);
});

it('answers a ping with 204 and stores nothing', function () {
    postWebhook($this, ['id' => 'evt_ping', 'object' => 'event', 'type' => 'ping', 'data' => ['object' => []]])
        ->assertNoContent();

    expect(WebhookEvent::count())->toBe(0);
});

it('needs no CSRF token and no login', function () {
    // postWebhook sends neither, and it works (the route is outside the web middleware group)
    $generation = heldGeneration();

    postWebhook($this, webhookEvent('evt_1', 'generation.processing', providerGeneration($generation, 'processing')))
        ->assertStatus(204);
});

// ---------------------------------------------------------------- settlement

it('settles a succeeded generation: stores usage, charges cost + 25%, releases the hold', function () {
    $generation = heldGeneration(balancePico: Money::parse('2'));
    $start = $generation->user->fresh()->balance_pico; // $2 - $0.10 hold

    postWebhook($this, webhookEvent('evt_ok', 'generation.succeeded', providerGeneration($generation, 'succeeded')))
        ->assertNoContent();

    $generation->refresh();
    // 12*5 + 765*10 + 6450*40 + 3184*12.5 = 305,510 per million = $0.305510 ; x1.25 = $0.3818875
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->provider_cost_pico)->toBe(Money::parse('0.30551'))
        ->and($generation->charge_pico)->toBe(Money::parse('0.3818875'))
        ->and($generation->usage['output_tokens'])->toBe(6450)
        ->and($generation->image_url)->not->toBeNull()
        ->and($generation->completed_at->toIso8601String())->toBe('2026-11-04T14:03:29+00:00');

    // balance = $2 - exact charge: the hold is gone, only the real charge remains
    expect($generation->user->fresh()->balance_pico)->toBe(Money::parse('2') - Money::parse('0.3818875'))
        ->and($start)->toBe(Money::parse('1.9'));
});

it('records a duplicate event once and charges once', function () {
    $generation = heldGeneration();
    $event = webhookEvent('evt_dup', 'generation.succeeded', providerGeneration($generation, 'succeeded'));

    postWebhook($this, $event)->assertNoContent();
    $balanceAfterFirst = $generation->user->fresh()->balance_pico;

    postWebhook($this, $event)->assertNoContent();
    postWebhook($this, $event)->assertNoContent();

    expect(WebhookEvent::where('event_id', 'evt_dup')->count())->toBe(1)
        ->and(CreditTransaction::where('type', 'charge')->count())->toBe(1)
        ->and(CreditTransaction::where('type', 'release')->count())->toBe(1)
        ->and($generation->user->fresh()->balance_pico)->toBe($balanceAfterFirst);
});

it('does not charge twice when two DIFFERENT events both say succeeded', function () {
    $generation = heldGeneration();

    postWebhook($this, webhookEvent('evt_a', 'generation.succeeded', providerGeneration($generation, 'succeeded')))->assertNoContent();
    postWebhook($this, webhookEvent('evt_b', 'generation.succeeded', providerGeneration($generation, 'succeeded')))->assertNoContent();

    expect(WebhookEvent::count())->toBe(2)
        ->and(CreditTransaction::where('type', 'charge')->count())->toBe(1);
});

it('does not move back when "processing" arrives after "succeeded"', function () {
    $generation = heldGeneration();

    postWebhook($this, webhookEvent('evt_2', 'generation.succeeded', providerGeneration($generation, 'succeeded')))->assertNoContent();
    postWebhook($this, webhookEvent('evt_1', 'generation.processing', providerGeneration($generation, 'processing')))->assertNoContent();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->charge_pico)->toBe(Money::parse('0.3818875'))
        ->and($generation->usage)->not->toBeNull()
        ->and(CreditTransaction::where('type', 'charge')->count())->toBe(1);
});

it('does not move a failed generation to succeeded, or back to processing', function () {
    $generation = heldGeneration();

    postWebhook($this, webhookEvent('evt_1', 'generation.failed', providerGeneration($generation, 'failed')))->assertNoContent();
    postWebhook($this, webhookEvent('evt_2', 'generation.succeeded', providerGeneration($generation, 'succeeded')))->assertNoContent();
    postWebhook($this, webhookEvent('evt_3', 'generation.processing', providerGeneration($generation, 'processing')))->assertNoContent();

    expect($generation->fresh()->status)->toBe(GenerationStatus::Failed)
        ->and($generation->fresh()->charge_pico)->toBe(0)
        ->and(CreditTransaction::where('type', 'charge')->count())->toBe(0);
});

it('makes a failed generation free and releases the hold', function () {
    $generation = heldGeneration(balancePico: Money::parse('2'));

    postWebhook($this, webhookEvent('evt_f', 'generation.failed', providerGeneration($generation, 'failed')))->assertNoContent();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('internal_error')
        ->and($generation->charge_pico)->toBe(0)
        ->and($generation->provider_cost_pico)->toBe(0)
        ->and($generation->user->fresh()->balance_pico)->toBe(Money::parse('2')) // nothing lost
        ->and(CreditTransaction::where('type', 'charge')->count())->toBe(0);
});

it('bills with the rates stored on the generation, not today\'s rates', function () {
    $generation = heldGeneration(attributes: [
        'rates' => [
            'input_text_tokens' => '5.00', 'input_image_tokens' => '10.00',
            'output_tokens' => '80.00', // doubled when this generation was created
            'reasoning_tokens' => '12.50',
        ],
    ]);

    // the webhook carries different rates; the stored ones win
    postWebhook($this, webhookEvent('evt_r', 'generation.succeeded', providerGeneration($generation, 'succeeded')))->assertNoContent();

    // 12*5 + 765*10 + 6450*80 + 3184*12.5 = 60+7650+516000+39800 = 563,510 -> $0.56351
    expect($generation->fresh()->provider_cost_pico)->toBe(Money::parse('0.56351'));
});

// ---------------------------------------------------------------- ordering

it('matches a webhook that arrives before our POST returned, by metadata.local_id', function () {
    // our job is still waiting for Pixelforge's answer: no provider_id yet, still pending_submit
    $generation = heldGeneration(attributes: ['status' => GenerationStatus::PendingSubmit, 'provider_id' => null, 'rates' => null]);

    postWebhook($this, webhookEvent('evt_early', 'generation.processing', providerGeneration($generation, 'processing', ['id' => 'gen_EARLY'])))
        ->assertNoContent();

    $generation->refresh();
    expect($generation->provider_id)->toBe('gen_EARLY')
        ->and($generation->status)->toBe(GenerationStatus::Processing)
        ->and($generation->rates)->not->toBeNull();

    // then the (late) 202 response is applied: the older "queued" must not move it back
    $generation = app(App\Services\ApplyGenerationState::class)->apply($generation, providerGeneration($generation, 'queued', ['id' => 'gen_EARLY']));
    expect($generation->status)->toBe(GenerationStatus::Processing);
});

it('settles correctly even if the first thing we hear is the final event', function () {
    $generation = heldGeneration(attributes: ['status' => GenerationStatus::PendingSubmit, 'provider_id' => null, 'rates' => null]);

    postWebhook($this, webhookEvent('evt_fast', 'generation.succeeded', providerGeneration($generation, 'succeeded', ['id' => 'gen_FAST'])))
        ->assertNoContent();

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Succeeded)
        ->provider_id->toBe('gen_FAST')
        ->charge_pico->toBe(Money::parse('0.3818875'));
});

it('stores events for unknown generations and answers 204', function () {
    $ghost = new Generation(['uuid' => 'not-ours', 'prompt' => 'x', 'size' => '1024x1024', 'quality' => 'low']);

    postWebhook($this, webhookEvent('evt_ghost', 'generation.processing', providerGeneration($ghost, 'processing', ['id' => 'gen_GHOST'])))
        ->assertNoContent();

    expect(WebhookEvent::where('event_id', 'evt_ghost')->count())->toBe(1);
});

it('only lets a fresher image url update a succeeded generation', function () {
    $generation = heldGeneration();
    postWebhook($this, webhookEvent('evt_1', 'generation.succeeded', providerGeneration($generation, 'succeeded')))->assertNoContent();

    $fresher = providerGeneration($generation, 'succeeded', [
        'image_url' => 'https://pixelforge.promptedit.dev/images/new.png',
        'image_url_expires_at' => '2026-11-06T10:00:00Z',
        'usage' => ['input_text_tokens' => 1, 'input_image_tokens' => 0, 'output_tokens' => 1, 'reasoning_tokens' => 1],
    ]);
    postWebhook($this, webhookEvent('evt_2', 'generation.succeeded', $fresher))->assertNoContent();

    $generation->refresh();
    expect($generation->image_url)->toBe('https://pixelforge.promptedit.dev/images/new.png')
        ->and($generation->charge_pico)->toBe(Money::parse('0.3818875')) // money untouched
        ->and($generation->usage['output_tokens'])->toBe(6450);
});

it('rolls the event back if applying it fails, so the retry is not mistaken for a duplicate', function () {
    $generation = heldGeneration();
    $broken = providerGeneration($generation, 'succeeded', ['usage' => null]);

    $this->withoutExceptionHandling();
    expect(fn () => postWebhook($this, webhookEvent('evt_broken', 'generation.succeeded', $broken)))
        ->toThrow(RuntimeException::class);

    expect(WebhookEvent::count())->toBe(0)
        ->and($generation->fresh()->status)->toBe(GenerationStatus::Queued);
});
