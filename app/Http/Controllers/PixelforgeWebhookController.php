<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\ApplyGenerationState;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Receives Pixelforge webhooks. Registered outside the "web" middleware group (see
 * bootstrap/app.php): no session, no CSRF token, no login. The signature is the authentication.
 *
 * The work is done inline, not in a queued job: it only touches our own database (no slow
 * network calls), takes milliseconds, and Pixelforge allows 10 seconds. Inline also means we
 * answer 2xx only after the state is really saved; otherwise Pixelforge retries.
 */
class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, ApplyGenerationState $state): Response
    {
        // Verify against the raw body, before any JSON decoding.
        $payload = $request->getContent();
        $this->verifySignature($payload, (string) $request->header('Pixelforge-Signature'));

        $event = json_decode($payload, true);
        if (! is_array($event) || empty($event['id']) || empty($event['type'])) {
            abort(400, 'Malformed event.');
        }

        // "Send test event" in the dashboard: nothing to store or apply.
        if ($event['type'] === 'ping') {
            return response()->noContent();
        }

        $snapshot = $event['data']['object'] ?? null;
        $providerId = is_array($snapshot) ? ($snapshot['id'] ?? null) : null;

        try {
            // One transaction for "remember this event" and "apply it": if applying fails, the
            // event row is rolled back too, so Pixelforge's retry is not mistaken for a duplicate.
            DB::transaction(function () use ($event, $snapshot, $providerId, $state) {
                // event_id is UNIQUE: the second delivery of the same event throws right here.
                WebhookEvent::create([
                    'event_id' => $event['id'],
                    'type' => $event['type'],
                    'generation_provider_id' => $providerId,
                    'received_at' => now(),
                    'payload' => $event,
                ]);

                if (! is_array($snapshot)) {
                    return;
                }

                $generation = $this->findGeneration($snapshot);
                if ($generation !== null) {
                    $state->apply($generation, $snapshot);
                }
                // Unknown generation: the event stays stored; the poller or a later event settles it.
            });
        } catch (UniqueConstraintViolationException) {
            // Duplicate delivery: already handled. Answer 2xx so Pixelforge stops retrying.
            return response()->noContent();
        }

        return response()->noContent();
    }

    /**
     * Header format: "t=<unix timestamp>,v1=<hex hmac-sha256 of '<t>.<raw body>'>".
     */
    private function verifySignature(string $payload, string $header): void
    {
        $secret = (string) config('services.pixelforge.webhook_secret');
        if ($secret === '') {
            // An empty key would let anyone forge a valid signature; refuse everything instead.
            abort(500, 'Webhook secret is not configured.');
        }

        $parts = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $parts[trim($key)] = trim($value);
        }

        $timestamp = (int) ($parts['t'] ?? 0);
        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        // hash_equals compares in constant time. The timestamp check stops replays of old requests.
        if (! hash_equals($expected, $parts['v1'] ?? '') || abs(time() - $timestamp) > 300) {
            abort(400, 'Invalid Pixelforge signature.');
        }
    }

    /**
     * By Pixelforge's id; if we don't have it yet (the webhook can arrive before our own
     * POST /v1/generations has returned), by the local_id we sent as metadata.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function findGeneration(array $snapshot): ?Generation
    {
        if (! empty($snapshot['id'])) {
            $generation = Generation::where('provider_id', $snapshot['id'])->first();
            if ($generation !== null) {
                return $generation;
            }
        }

        $localId = $snapshot['metadata']['local_id'] ?? null;

        return $localId ? Generation::where('uuid', $localId)->first() : null;
    }
}
