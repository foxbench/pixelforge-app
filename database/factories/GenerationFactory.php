<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Generation>
 */
class GenerationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'uuid' => (string) Str::uuid(),
            'prompt' => 'A lighthouse on a cliff at dawn',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'status' => GenerationStatus::PendingSubmit,
            'estimated_max_charge_pico' => 100_000_000_000, // $0.10
            'estimated_typical_charge_pico' => 60_000_000_000,
        ];
    }
}
