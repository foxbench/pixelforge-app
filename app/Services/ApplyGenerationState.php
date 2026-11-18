<?php

namespace App\Services;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The ONE place where a generation changes state and money moves.
 *
 * Webhooks, the poller and the submit job all call apply() with a Pixelforge generation object
 * ("snapshot"). Because they share this code, the rules below hold no matter which path
 * delivers the news first, or how often:
 *
 *  - A generation only moves forward (pending_submit < queued < processing < succeeded/failed).
 *    A late "processing" after "succeeded" is ignored.
 *  - succeeded and failed are final. The status is re-read inside a write transaction (serialised
 *    by SQLite's IMMEDIATE mode, or by the row lock on MySQL/Postgres), so two concurrent callers
 *    cannot both settle it: the second one sees the final state and does nothing.
 *    (credit_transactions also has a unique index on generation_id + type, so even a bug here
 *    could not charge twice.)
 *  - Settlement (stored usage, cost, charge, release of the hold, balance) happens in the same
 *    transaction as the status change. All or nothing.
 */
class ApplyGenerationState
{
    public function __construct(private Ledger $ledger) {}

    /**
     * @param  array<string, mixed>  $snapshot  a Pixelforge generation object
     */
    public function apply(Generation $generation, array $snapshot): Generation
    {
        return DB::transaction(function () use ($generation, $snapshot) {
            // Read the row fresh: the copy we were handed may be stale. On SQLite lockForUpdate() is a no-op
            // (transaction_mode IMMEDIATE in config/database.php serialises writers); on MySQL/Postgres it locks.
            $generation = Generation::whereKey($generation->id)->lockForUpdate()->firstOrFail();

            // Facts that are the same in every snapshot: remember them.
            if ($generation->provider_id === null && isset($snapshot['id'])) {
                $generation->provider_id = $snapshot['id'];
            }
            if ($generation->rates === null && ! empty($snapshot['rates'])) {
                $generation->rates = $snapshot['rates'];
            }
            if ($generation->submitted_at === null && isset($snapshot['created_at'])) {
                $generation->submitted_at = Carbon::parse($snapshot['created_at']);
            }

            $incoming = GenerationStatus::tryFrom((string) ($snapshot['status'] ?? ''));

            if ($incoming === null) {
                // not a status we know: keep only the harmless facts above
            } elseif ($generation->status->isFinal()) {
                $this->refreshImageUrl($generation, $incoming, $snapshot);
            } elseif ($incoming->rank() > $generation->status->rank()) {
                match ($incoming) {
                    GenerationStatus::Succeeded => $this->settleSuccess($generation, $snapshot),
                    GenerationStatus::Failed => $this->settleFailure(
                        $generation,
                        (string) ($snapshot['error']['code'] ?? 'generation_failed'),
                        (string) ($snapshot['error']['message'] ?? 'Generation failed.'),
                        isset($snapshot['completed_at']) ? Carbon::parse($snapshot['completed_at']) : now(),
                    ),
                    default => $generation->status = $incoming, // queued or processing
                };
            }
            // else: same or earlier state (duplicate / out-of-order event): nothing to do.

            $generation->save();

            return $generation;
        });
    }

    /**
     * We never got the request accepted (rejected, or gave up retrying): fail it locally and
     * give the held balance back. Does nothing once Pixelforge has accepted the generation:
     * from then on only Pixelforge's status may decide the outcome.
     */
    public function failSubmission(Generation $generation, string $code, string $message): Generation
    {
        return DB::transaction(function () use ($generation, $code, $message) {
            $generation = Generation::whereKey($generation->id)->lockForUpdate()->firstOrFail();

            if ($generation->status === GenerationStatus::PendingSubmit) {
                $this->settleFailure($generation, $code, $message, now());
                $generation->save();
            }

            return $generation;
        });
    }

    /**
     * Pixelforge keeps saying it has no such generation (the poller got 404 several times in a
     * row): fail it locally and give the held balance back. Does nothing if it is already final.
     */
    public function failNotFound(Generation $generation): Generation
    {
        return DB::transaction(function () use ($generation) {
            $generation = Generation::whereKey($generation->id)->lockForUpdate()->firstOrFail();

            if (! $generation->status->isFinal()) {
                $this->settleFailure(
                    $generation,
                    'provider_not_found',
                    'Pixelforge no longer knows this generation. You were not charged.',
                    now(),
                );
                $generation->save();
            }

            return $generation;
        });
    }

    private function settleSuccess(Generation $generation, array $snapshot): void
    {
        $usage = $snapshot['usage'] ?? null;
        $rates = $generation->rates ?? $snapshot['rates'] ?? null;
        if (! is_array($usage) || ! is_array($rates)) {
            // Should not happen for a succeeded generation. Throwing rolls everything back, so the
            // webhook is answered with a 5xx and retried, and the poller tries again.
            throw new RuntimeException("Succeeded generation {$generation->id} has no usage or rates.");
        }

        // Cost uses the rates this generation was created with, never today's pricing.
        $cost = Money::providerCost($usage, $rates);
        $charge = Money::withMarkup($cost);

        $generation->status = GenerationStatus::Succeeded;
        $generation->usage = $usage;
        $generation->rates = $rates;
        $generation->provider_cost_pico = $cost;
        $generation->charge_pico = $charge;
        $generation->image_url = $snapshot['image_url'] ?? null;
        $generation->image_url_expires_at = isset($snapshot['image_url_expires_at'])
            ? Carbon::parse($snapshot['image_url_expires_at'])
            : null;
        $generation->completed_at = isset($snapshot['completed_at']) ? Carbon::parse($snapshot['completed_at']) : now();

        $this->ledger->release($generation, $generation->estimated_max_charge_pico);
        $this->ledger->charge($generation, $charge);
    }

    private function settleFailure(Generation $generation, string $code, string $message, Carbon $completedAt): void
    {
        $generation->status = GenerationStatus::Failed;
        $generation->error_code = $code;
        $generation->error_message = $message;
        $generation->provider_cost_pico = 0;
        $generation->charge_pico = 0;
        $generation->completed_at = $completedAt;

        // Failed generations are free: the whole hold goes back.
        $this->ledger->release($generation, $generation->estimated_max_charge_pico);
    }

    /**
     * Only a fresher signed image URL may change a succeeded generation.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function refreshImageUrl(Generation $generation, GenerationStatus $incoming, array $snapshot): void
    {
        if ($generation->status !== GenerationStatus::Succeeded || $incoming !== GenerationStatus::Succeeded) {
            return;
        }
        if (empty($snapshot['image_url']) || empty($snapshot['image_url_expires_at'])) {
            return;
        }

        $expires = Carbon::parse($snapshot['image_url_expires_at']);
        if ($generation->image_url_expires_at === null || $expires->greaterThan($generation->image_url_expires_at)) {
            $generation->image_url = $snapshot['image_url'];
            $generation->image_url_expires_at = $expires;
        }
    }
}
