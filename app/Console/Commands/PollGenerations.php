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

        foreach ($stale as $generation) {
            try {
                $state->apply($generation, $client->getGeneration($generation->provider_id));
                // Bump updated_at even when nothing changed, so we ask again in a minute, not now.
                $generation->touch();
            } catch (Throwable $e) {
                // One bad generation must not stop the rest.
                Log::warning("Polling {$generation->provider_id} failed: ".$e->getMessage());
            }
        }

        // 2. Never confirmed by Pixelforge (job lost or crashed): submit again. The Idempotency-Key
        //    (the generation's uuid) means this can never create a second generation.
        $stuck = Generation::query()
            ->where('status', GenerationStatus::PendingSubmit->value)
            ->where('updated_at', '<', now()->subMinutes(5))
            ->limit(self::MAX_PER_RUN)
            ->get();

        foreach ($stuck as $generation) {
            $generation->touch(); // so we only re-dispatch once per 5 minutes
            SubmitGeneration::dispatch($generation->id);
        }

        $this->info("Polled {$stale->count()}, re-submitted {$stuck->count()}.");

        return self::SUCCESS;
    }
}
