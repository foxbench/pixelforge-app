<?php

namespace App\Console\Commands;

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Services\ApplyGenerationState;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Safety net for webhooks (delivery is not guaranteed). Runs every minute from the scheduler.
 */
class PollGenerations extends Command
{
    protected $signature = 'app:poll-generations';

    protected $description = 'Fetch the status of unfinished generations whose webhooks have not arrived';

    /** Pixelforge allows 60 requests per minute; stay well below that. */
    private const MAX_PER_RUN = 20;

    /** Stop polling when this few requests are left in the current minute. */
    private const STOP_AT_REMAINING = 5;

    /** This many 404s in a row and we treat the generation as gone. */
    private const NOT_FOUND_LIMIT = 3;

    public function handle(PixelforgeClient $client, ApplyGenerationState $state): int
    {
        // 1. Accepted by Pixelforge, still not final, and quiet for a minute: ask Pixelforge directly.
        $stale = Generation::query()
            ->whereNotNull('provider_id')
            ->whereIn('status', [GenerationStatus::Queued->value, GenerationStatus::Processing->value])
            ->where('updated_at', '<', now()->subSeconds(60))
            ->orderBy('updated_at')
            ->limit(self::MAX_PER_RUN)
            ->get();

        $polled = 0;
        foreach ($stale as $generation) {
            try {
                $response = $client->getGeneration($generation->provider_id);
                $polled++;

                // Too many requests this minute: stop, the rest waits for the next run.
                if ($response->status() === 429) {
                    break;
                }

                if ($response->status() === 404) {
                    $this->countNotFound($generation, $state);
                } elseif ($response->successful()) {
                    if ($generation->poll_not_found_count > 0) {
                        $generation->poll_not_found_count = 0;
                        $generation->save();
                    }
                    $state->apply($generation, $response->json());
                } else {
                    Log::warning("Polling {$generation->provider_id} failed: HTTP ".$response->status());
                }
                // Bump updated_at even when nothing changed, so we ask again in a minute, not now.
                $generation->touch();

                // Nearly out of requests for this minute: stop here as well.
                $remaining = $response->header('X-RateLimit-Remaining');
                if ($remaining !== '' && (int) $remaining <= self::STOP_AT_REMAINING) {
                    break;
                }
            } catch (Throwable $e) {
                // One bad generation must not stop the rest.
                Log::warning("Polling {$generation->provider_id} failed: ".$e->getMessage());
            }
        }

        // 2. Never confirmed by Pixelforge (job lost or crashed, or it kept hitting network errors):
        //    submit again. The Idempotency-Key (the generation's uuid) means this can never create a
        //    second generation. A job that is still retrying updates last_submit_attempt_at on every
        //    attempt, so it is left alone; a generation never attempted is judged by created_at.
        $cutoff = now()->subMinutes(10);
        $stuck = Generation::query()
            ->where('status', GenerationStatus::PendingSubmit->value)
            ->where(function ($query) use ($cutoff) {
                $query->where('last_submit_attempt_at', '<', $cutoff)
                    ->orWhere(fn ($never) => $never->whereNull('last_submit_attempt_at')->where('created_at', '<', $cutoff));
            })
            ->limit(self::MAX_PER_RUN)
            ->get();

        foreach ($stuck as $generation) {
            // Fresh start for the new job (its own failure count and time budget), and only one
            // re-dispatch per 10 minutes.
            $generation->update([
                'last_submit_attempt_at' => now(),
                'first_submit_attempt_at' => null,
                'submit_failures' => 0,
            ]);
            SubmitGeneration::dispatch($generation->id);
        }

        $this->info("Polled {$polled}, re-submitted {$stuck->count()}.");

        return self::SUCCESS;
    }

    /**
     * A 404 can be a blip, so only the third one in a row fails the generation (and gives the hold back).
     */
    private function countNotFound(Generation $generation, ApplyGenerationState $state): void
    {
        $generation->poll_not_found_count++;
        $generation->save();

        if ($generation->poll_not_found_count >= self::NOT_FOUND_LIMIT) {
            Log::warning("Generation {$generation->id} ({$generation->provider_id}) was not found at Pixelforge {$generation->poll_not_found_count} times; failing it.");
            $state->failNotFound($generation);
        }
    }
}
