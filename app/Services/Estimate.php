<?php

namespace App\Services;

/**
 * All amounts are picodollars. "charge" is what the user pays (provider cost + 25%).
 */
final readonly class Estimate
{
    public function __construct(
        public int $typicalCostPico,
        public int $maxCostPico,
        public int $typicalChargePico,
        public int $maxChargePico,
        public string $pricingSource,
    ) {}
}
