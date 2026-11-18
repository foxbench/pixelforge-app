<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\ApplyGenerationState;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Sends one generation to Pixelforge.
 *
 * Safe to run more than once: the Idempotency-Key is the generation's uuid, so Pixelforge
 * returns the same generation for every retry instead of creating (and billing) a new one.
 */
class SubmitGeneration implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    /** A $tries on the job overrides the worker's --tries=1, so release() below can retry. */
    public int $tries = 6;

    public function __construct(public int $generationId) {}

    public function handle(PixelforgeClient $client, ApplyGenerationState $state): void
    {
        $generation = Generation::find($this->generationId);

        // Already sent (a duplicate job, or the webhook beat us), or gone: nothing to do.
        if ($generation === null || $generation->status !== GenerationStatus::PendingSubmit) {
            return;
        }

        try {
            $response = $client->createGeneration($generation);
        } catch (ConnectionException) {
            // We don't know whether Pixelforge got it. Retrying with the same key is safe.
            $this->retryLater($generation, $state, 2);

            return;
        }

        if ($response->successful()) {
            $state->apply($generation, $response->json());

            return;
        }

        $code = (string) $response->json('error.code', 'http_'.$response->status());
        $message = (string) $response->json('error.message', 'Pixelforge returned HTTP '.$response->status().'.');

        // Temporary problems: too many requests / too many running / overloaded / server error.
        $temporary = in_array($code, ['rate_limit_exceeded', 'concurrency_limit_exceeded'], true)
            || $response->status() >= 500;

        if ($temporary) {
            $this->retryLater($generation, $state, (int) $response->header('Retry-After') ?: 2);

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

    private function retryLater(Generation $generation, ApplyGenerationState $state, int $retryAfterSeconds): void
    {
        if ($this->attempts() >= $this->tries) {
            $state->failSubmission(
                $generation,
                'submit_gave_up',
                'Pixelforge did not accept the request after '.$this->tries.' attempts. You were not charged.',
            );

            return;
        }

        // Respect Retry-After, and wait longer after each failed attempt (capped at 2 minutes).
        $this->release(min(max($retryAfterSeconds, 1) * $this->attempts(), 120));
    }
}
