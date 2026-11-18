<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function formData(array $overrides = []): array
{
    return array_merge([
        'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
        'size' => '1024x1024',
        'quality' => 'medium',
        'reference_images' => ['', '', '', ''],
    ], $overrides);
}

/** Run the job once as if it were its Nth attempt, with queue interactions faked. */
function runSubmitJob(Generation $generation, int $attempt = 1): SubmitGeneration
{
    $job = (new SubmitGeneration($generation->id))->withFakeQueueInteractions();
    $job->job->attempts = $attempt;
    app()->call([$job, 'handle']);

    return $job;
}

function pendingGeneration(int $balancePico = 2_000_000_000_000): Generation
{
    return heldGeneration($balancePico, ['status' => GenerationStatus::PendingSubmit, 'provider_id' => null, 'rates' => null]);
}

// ---------------------------------------------------------------- submit (controller)

describe('submitting', function () {
    beforeEach(function () {
        Cache::flush();
        fakePricing();
        Queue::fake();
    });

    it('holds the maximum charge, creates the generation and queues the job', function () {
        $user = User::factory()->create(['balance_pico' => Money::parse('2')]);

        $this->actingAs($user)->post('/generations', formData())->assertRedirect('/generations');

        $generation = Generation::firstOrFail();
        // 45 characters -> 12 text tokens, +15% = 14; medium 1024x1024: 1100 output, 2000 max reasoning
        // 14*5 + 1100*40 + 2000*12.5 = 70 + 44000 + 25000 = 69,070 per million = 0.06907 ; x1.25 = 0.0863375
        expect($generation->status)->toBe(GenerationStatus::PendingSubmit)
            ->and($generation->estimated_max_charge_pico)->toBe(Money::parse('0.0863375'))
            ->and($generation->reference_images)->toBe([])
            ->and($user->fresh()->balance_pico)->toBe(Money::parse('2') - Money::parse('0.0863375'));

        $hold = CreditTransaction::where('type', 'hold')->sole();
        expect($hold->amount_pico)->toBe(-Money::parse('0.0863375'))
            ->and($hold->generation_id)->toBe($generation->id);

        Queue::assertPushed(SubmitGeneration::class, fn ($job) => $job->generationId === $generation->id);
    });

    it('blocks the submit when the balance cannot cover the maximum cost', function () {
        $user = User::factory()->create(['balance_pico' => Money::parse('0.05')]);

        $this->actingAs($user)->post('/generations', formData())
            ->assertSessionHasErrors('balance');

        expect(Generation::count())->toBe(0)
            ->and(CreditTransaction::count())->toBe(0)
            ->and($user->fresh()->balance_pico)->toBe(Money::parse('0.05'));
        Queue::assertNothingPushed();
    });

    it('allows a balance of exactly the maximum cost', function () {
        $user = User::factory()->create(['balance_pico' => Money::parse('0.0863375')]);

        $this->actingAs($user)->post('/generations', formData())->assertRedirect('/generations');

        expect($user->fresh()->balance_pico)->toBe(0);
    });

    it('validates the form', function (array $bad, string $field) {
        $user = User::factory()->create(['balance_pico' => Money::parse('2')]);

        $this->actingAs($user)->post('/generations', formData($bad))->assertSessionHasErrors($field);

        expect(Generation::count())->toBe(0);
    })->with([
        'prompt missing' => [['prompt' => ''], 'prompt'],
        'prompt too long' => [['prompt' => str_repeat('a', 4001)], 'prompt'],
        'bad size' => [['size' => '10x10'], 'size'],
        'bad quality' => [['quality' => 'ultra'], 'quality'],
        'five references' => [['reference_images' => array_fill(0, 5, 'https://example.com/a.png')], 'reference_images'],
        'not a url' => [['reference_images' => ['not a url']], 'reference_images.0'],
        'ftp url' => [['reference_images' => ['ftp://example.com/a.png']], 'reference_images.0'],
    ]);

    it('accepts up to four https references and bills 765 tokens for each', function () {
        $user = User::factory()->create(['balance_pico' => Money::parse('2')]);
        $urls = ['https://a.test/1.png', 'http://a.test/2.png', 'https://a.test/3.png', 'https://a.test/4.png'];

        $this->actingAs($user)->post('/generations', formData(['reference_images' => $urls]))->assertRedirect('/generations');

        expect(Generation::sole()->reference_images)->toBe($urls);
    });

    it('requires login', function () {
        $this->post('/generations', formData())->assertRedirect('/login');
    });
});

// ---------------------------------------------------------------- the job

describe('SubmitGeneration job', function () {
    it('sends the generation with its uuid as Idempotency-Key and local_id', function () {
        $generation = pendingGeneration();
        config(['services.pixelforge.api_key' => 'pf_test_key']);
        Http::fake([API.'/generations' => Http::response(providerGeneration($generation, 'queued', ['id' => 'gen_NEW']), 202)]);

        runSubmitJob($generation);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://'.API.'/generations'
            && $request->header('Idempotency-Key') === [$generation->uuid]
            && $request->header('Authorization') === ['Bearer pf_test_key']
            && $request['metadata']['local_id'] === $generation->uuid
            && $request['prompt'] === $generation->prompt);

        $generation->refresh();
        expect($generation->status)->toBe(GenerationStatus::Queued)
            ->and($generation->provider_id)->toBe('gen_NEW')
            ->and($generation->rates)->toBe(RATES);
    });

    it('retries a 503 later with the same Idempotency-Key, and keeps the hold meanwhile', function () {
        $generation = pendingGeneration();
        $balance = $generation->user->fresh()->balance_pico;
        Http::fake([API.'/generations' => Http::response(['error' => ['code' => 'overloaded', 'message' => 'busy']], 503, ['Retry-After' => '2'])]);

        $first = runSubmitJob($generation, attempt: 1);
        $first->assertReleased(delay: 2);

        $second = runSubmitJob($generation, attempt: 2);
        $second->assertReleased(delay: 4); // waits longer each time

        expect($generation->fresh()->status)->toBe(GenerationStatus::PendingSubmit)
            ->and($generation->user->fresh()->balance_pico)->toBe($balance);

        $keys = collect(Http::recorded())->map(fn ($pair) => $pair[0]->header('Idempotency-Key')[0])->unique();
        expect($keys->all())->toBe([$generation->uuid]);
    });

    it('gives up after 6 attempts: fails the generation and releases the hold', function () {
        $generation = pendingGeneration(balancePico: Money::parse('2'));
        Http::fake([API.'/generations' => Http::response(['error' => ['code' => 'overloaded', 'message' => 'busy']], 503, ['Retry-After' => '2'])]);

        $last = runSubmitJob($generation, attempt: 6);

        $generation->refresh();
        expect($generation->status)->toBe(GenerationStatus::Failed)
            ->and($generation->error_code)->toBe('submit_gave_up')
            ->and($generation->charge_pico)->toBe(0)
            ->and($generation->user->fresh()->balance_pico)->toBe(Money::parse('2')) // fully refunded
            ->and(CreditTransaction::where('type', 'release')->count())->toBe(1);
        $last->assertNotReleased();
    });

    it('waits for Retry-After on a 429 rate limit', function (string $code) {
        $generation = pendingGeneration();
        Http::fake([API.'/generations' => Http::response(['error' => ['code' => $code, 'message' => 'slow down']], 429, ['Retry-After' => '30'])]);

        runSubmitJob($generation)->assertReleased(delay: 30);

        expect($generation->fresh()->status)->toBe(GenerationStatus::PendingSubmit);
    })->with(['rate_limit_exceeded', 'concurrency_limit_exceeded']);

    it('retries after a network error', function () {
        $generation = pendingGeneration();
        Http::fake(fn () => throw new ConnectionException('timed out'));

        runSubmitJob($generation)->assertReleased(delay: 2);

        expect($generation->fresh()->status)->toBe(GenerationStatus::PendingSubmit);
    });

    it('fails permanently and refunds on a rejection', function (int $status, string $code) {
        $generation = pendingGeneration(balancePico: Money::parse('2'));
        Http::fake([API.'/generations' => Http::response(['error' => ['code' => $code, 'message' => "rejected: {$code}"]], $status)]);

        $job = runSubmitJob($generation);

        $generation->refresh();
        expect($generation->status)->toBe(GenerationStatus::Failed)
            ->and($generation->error_code)->toBe($code)
            ->and($generation->error_message)->toBe("rejected: {$code}")
            ->and($generation->user->fresh()->balance_pico)->toBe(Money::parse('2'));
        $job->assertNotReleased();
    })->with([
        'content policy' => [400, 'content_policy_violation'],
        'validation' => [422, 'validation_failed'],
        'daily limit' => [429, 'daily_limit_exceeded'],
    ]);

    it('applies an idempotent replay that already succeeded (settles it)', function () {
        $generation = pendingGeneration(balancePico: Money::parse('2'));
        Http::fake([API.'/generations' => Http::response(providerGeneration($generation, 'succeeded', ['id' => 'gen_REPLAY']), 202)]);

        runSubmitJob($generation);

        $generation->refresh();
        expect($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->charge_pico)->toBe(Money::parse('0.3818875'));
    });

    it('does nothing for a generation that is no longer pending (duplicate job, or webhook first)', function () {
        $generation = heldGeneration(); // already queued
        Http::fake();

        runSubmitJob($generation);

        Http::assertNothingSent();
    });

    it('fails and refunds if the job dies with an unexpected error, but never touches an accepted generation', function () {
        $pending = pendingGeneration(balancePico: Money::parse('2'));
        (new SubmitGeneration($pending->id))->failed(new RuntimeException('bug'));
        expect($pending->fresh()->status)->toBe(GenerationStatus::Failed)
            ->and($pending->user->fresh()->balance_pico)->toBe(Money::parse('2'));

        $accepted = heldGeneration();
        (new SubmitGeneration($accepted->id))->failed(new RuntimeException('bug'));
        expect($accepted->fresh()->status)->toBe(GenerationStatus::Queued);
    });
});

// ---------------------------------------------------------------- poller

describe('polling fallback', function () {
    it('fetches generations that have been quiet for a minute and settles them', function () {
        $generation = heldGeneration(balancePico: Money::parse('2'));
        Generation::whereKey($generation->id)->update(['updated_at' => now()->subMinutes(2)]);
        Http::fake([API.'/generations/'.$generation->provider_id => Http::response(providerGeneration($generation, 'succeeded'))]);

        $this->artisan('app:poll-generations')->assertSuccessful();

        expect($generation->fresh()->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->user->fresh()->balance_pico)->toBe(Money::parse('2') - Money::parse('0.3818875'));
    });

    it('leaves recently updated and final generations alone', function () {
        heldGeneration(); // updated just now
        heldGeneration(attributes: ['status' => GenerationStatus::Succeeded]);
        Generation::query()->update(['updated_at' => now()->subSeconds(10)]);
        Http::fake();

        $this->artisan('app:poll-generations')->assertSuccessful();

        Http::assertNothingSent();
    });

    it('keeps going when one generation cannot be fetched', function () {
        $bad = heldGeneration(attributes: ['provider_id' => 'gen_BAD']);
        $good = heldGeneration(attributes: ['provider_id' => 'gen_GOOD']);
        Generation::query()->update(['updated_at' => now()->subMinutes(2)]);
        Http::fake([
            API.'/generations/gen_BAD' => Http::response(['error' => ['code' => 'not_found']], 404),
            API.'/generations/gen_GOOD' => Http::response(providerGeneration($good, 'failed')),
        ]);

        $this->artisan('app:poll-generations')->assertSuccessful();

        expect($bad->fresh()->status)->toBe(GenerationStatus::Queued)
            ->and($good->fresh()->status)->toBe(GenerationStatus::Failed);
    });

    it('resubmits generations stuck in pending_submit for more than 5 minutes', function () {
        Queue::fake();
        $stuck = pendingGeneration();
        $fresh = pendingGeneration();
        Generation::whereKey($stuck->id)->update(['updated_at' => now()->subMinutes(6)]);

        $this->artisan('app:poll-generations')->assertSuccessful();

        Queue::assertPushed(SubmitGeneration::class, 1);
        Queue::assertPushed(SubmitGeneration::class, fn ($job) => $job->generationId === $stuck->id);
        expect($fresh->fresh()->status)->toBe(GenerationStatus::PendingSubmit);
    });
});
