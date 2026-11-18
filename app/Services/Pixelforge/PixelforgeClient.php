<?php

namespace App\Services\Pixelforge;

use App\Models\Generation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the Pixelforge HTTP API. It does no business logic.
 */
class PixelforgeClient
{
    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim(config('services.pixelforge.base_url'), '/').'/')
            ->withToken((string) config('services.pixelforge.api_key'))
            ->acceptJson()
            ->timeout(15);
    }

    /**
     * Returns the raw response (the caller decides what 202 / 429 / 503 / 4xx mean).
     *
     * @throws ConnectionException on network failure
     */
    public function createGeneration(Generation $generation): Response
    {
        return $this->http()
            // Same key on every retry: Pixelforge returns the same generation instead of making a new one.
            ->withHeaders(['Idempotency-Key' => $generation->uuid])
            ->post('generations', [
                'prompt' => $generation->prompt,
                'size' => $generation->size,
                'quality' => $generation->quality,
                'reference_images' => $generation->reference_images,
                // metadata values must be strings
                'metadata' => [
                    'local_id' => $generation->uuid,
                    'user_id' => (string) $generation->user_id,
                ],
            ]);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException
     * @throws ConnectionException
     */
    public function getGeneration(string $providerId): array
    {
        return $this->http()->get('generations/'.$providerId)->throw()->json();
    }

    /**
     * Pixelforge's own record of what it billed, per UTC day.
     *
     * @return array<string, mixed>
     *
     * @throws RequestException
     * @throws ConnectionException
     */
    public function usage(string $startDate, string $endDate): array
    {
        return $this->http()
            ->get('usage', ['start_date' => $startDate, 'end_date' => $endDate])
            ->throw()
            ->json();
    }

    /**
     * No authentication needed for this endpoint.
     *
     * @return array<string, mixed>
     *
     * @throws RequestException
     * @throws ConnectionException
     */
    public function pricing(): array
    {
        return Http::baseUrl(rtrim(config('services.pixelforge.base_url'), '/').'/')
            ->acceptJson()
            ->timeout(10)
            ->get('pricing')
            ->throw()
            ->json();
    }
}
