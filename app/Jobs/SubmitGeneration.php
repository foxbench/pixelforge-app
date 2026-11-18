<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\ApplyGenerationState;
use App\Services\Pixelforge\PixelforgeClient;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one generation to Pixelforge.
 *
 * Safe to run more than once: the Idempotency-Key is the generation's uuid, so Pixelforge
 * returns the same generation for every retry instead of creating (and billing) a new one.
 *
 * Retry rules (Laravel counts every release() as an attempt, so we keep our own counters on the
 * generation row):
 *  - 503 / 5xx / rate_limit_exceeded: at most 6 failures, then the generation fails (nothing was created).
 *  - concurrency_limit_exceeded: not counted. Pixelforge simply has 3 generations running; we wait
 *    for one to finish, for at most 15 minutes after the first attempt.
 *  - network errors: at most 6, then we stop but do NOT fail the generation, because Pixelforge may
 *    have created it. It stays pending_submit (hold kept) and the poller sends it again later
 *    with the same Idempotency-Key.
 */
class SubmitGeneration implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    private const MAX_FAILURES = 6;

    private const CONCURRENCY_BUDGET_MINUTES = 15;

    /** Wait this long before retrying after an unexpected exception (a bug, not a Pixelforge answer). */
    public int $backoff = 10;

    public function __construct(public int $generationId) {}

    /**
     * Backstop only (the rules above end the job long before): with a time limit instead of
     * $tries, releasing for a concurrency limit is not cut off by the worker's attempt counter.
     * When it expires, failed() below runs.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    public function handle(PixelforgeClient $client, ApplyGenerationState $state): void
    {
        $generation = Generation::find($this->generationId);

        // Already sent (a duplicate job, or the webhook beat us), or gone: nothing to do.
        if ($generation === null || $generation->status !== GenerationStatus::PendingSubmit) {
            return;
        }

        // The poller reads last_submit_attempt_at to tell a job that is still retrying from a lost one.
        $generation->first_submit_attempt_at ??= now();
        $generation->last_submit_attempt_at = now();
        $generation->save();

        try {
            $response = $client->createGeneration($generation);
        } catch (ConnectionException) {
            // We don't know whether Pixelforge got it. Retrying with the same key is safe.
            $this->retryOrStop($generation, 2, giveUp: function () use ($generation) {
                // Never finalise locally: Pixelforge may have created it. The poller will send it again.
                Log::warning("Submitting generation {$generation->id}: network errors; leaving it pending for the poller.");
            });

            return;
        }

        if ($response->successful()) {
            $state->apply($generation, $response->json());

            return;
        }

        $code = (string) $response->json('error.code', 'http_'.$response->status());
        $message = (string) $response->json('error.message', 'Pixelforge returned HTTP '.$response->status().'.');
        $retryAfter = (int) $response->header('Retry-After');

        if ($code === 'concurrency_limit_exceeded') {
            $this->waitForConcurrency($generation, $state, $retryAfter);

            return;
        }

        // Other temporary problems: too many requests / overloaded / server error.
        if ($code === 'rate_limit_exceeded' || $response->status() >= 500) {
            $this->retryOrStop($generation, $retryAfter ?: 2, giveUp: fn () => $state->failSubmission(
                $generation,
                'submit_gave_up',
                'Pixelforge did not accept the request after '.self::MAX_FAILURES.' attempts. You were not charged.',
            ));

            return;
        }

        // Permanent: content_policy_violation, validation_failed, daily_limit_exceeded, ...
        // Nothing was created or billed on Pixelforge's side; give the held balance back.
        $state->failSubmission($generation, $code, $message);
    }

    /**
     * Called by the queue when the job throws on its last attempt (a bug, not a Pixelforge answer).
     */
    public function failed(Throwable $exception): void
    {
        $generation = Generation::find($this->generationId);
        if ($generation !== null) {
            app(ApplyGenerationState::class)->failSubmission(
                $generation,
                'internal_error',
                'We could not submit this generation. You were not charged.',
            );
        }
    }

    /**
     * Count this failure. Under the cap: release the job, waiting longer after each failure
     * (Retry-After x number of failures, capped at 2 minutes). At the cap: run $giveUp.
     */
    private function retryOrStop(Generation $generation, int $retryAfterSeconds, Closure $giveUp): void
    {
        $generation->submit_failures++;
        $generation->save();

        if ($generation->submit_failures >= self::MAX_FAILURES) {
            $giveUp();

            return;
        }

        $this->release(min(max($retryAfterSeconds, 1) * $generation->submit_failures, 120));
    }

    /**
     * Pixelforge answered 429 because 3 generations are already running. That is a definite
     * "nothing created", so it is safe to fail once the time budget is used up.
     */
    private function waitForConcurrency(Generation $generation, ApplyGenerationState $state, int $retryAfterSeconds): void
    {
        if ($generation->first_submit_attempt_at->lt(now()->subMinutes(self::CONCURRENCY_BUDGET_MINUTES))) {
            $state->failSubmission(
                $generation,
                'submit_gave_up',
                'Pixelforge was at its concurrency limit for '.self::CONCURRENCY_BUDGET_MINUTES.' minutes. You were not charged.',
            );

            return;
        }

        // Not counted as a failure; the wait is Retry-After (5 s), at least 5 s.
        $this->release(max($retryAfterSeconds, 5));
    }
}
