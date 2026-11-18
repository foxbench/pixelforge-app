<?php

namespace App\Services;

use App\Services\Pixelforge\PricingService;
use App\Support\Money;

/**
 * Estimates a generation's cost before it is submitted. The same code gives the numbers shown
 * in the form and the amount that is held on the user's balance.
 *
 * Only the prompt LENGTH matters (about 4 characters per token), so the browser sends a
 * character count, not the prompt text.
 */
class CostEstimator
{
    public function __construct(private PricingService $pricing) {}

    public function estimate(int $promptChars, string $size, string $quality, int $referenceImages): Estimate
    {
        $pricing = $this->pricing->get();
        $tokens = $pricing['tokens'];

        // text: ceil(chars / 4). The real count is within the variance (15%) of that, so the maximum
        // adds the variance, in basis points (1/100 of a percent) and rounded UP: never short.
        $perToken = (int) $tokens['characters_per_text_token'];
        $typicalText = intdiv($promptChars + $perToken - 1, $perToken);
        $varianceBp = $this->basisPoints($tokens['text_token_variance']);
        $maxText = intdiv($typicalText * (10000 + $varianceBp) + 9999, 10000);

        $image = $referenceImages * (int) $tokens['per_reference_image'];
        $output = (int) $tokens['output'][$quality][$size];

        // reasoning is only known afterwards but always inside a range set by quality
        $reasoning = $tokens['reasoning'][$quality];
        $typicalReasoning = intdiv($reasoning['min'] + $reasoning['max'], 2);
        $maxReasoning = (int) $reasoning['max'];

        $typicalCost = Money::providerCost([
            'input_text_tokens' => $typicalText,
            'input_image_tokens' => $image,
            'output_tokens' => $output,
            'reasoning_tokens' => $typicalReasoning,
        ], $pricing['rates']);

        $maxCost = Money::providerCost([
            'input_text_tokens' => $maxText,
            'input_image_tokens' => $image,
            'output_tokens' => $output,
            'reasoning_tokens' => $maxReasoning,
        ], $pricing['rates']);

        return new Estimate(
            typicalCostPico: $typicalCost,
            maxCostPico: $maxCost,
            typicalChargePico: Money::withMarkup($typicalCost),
            maxChargePico: Money::withMarkup($maxCost),
            pricingSource: $pricing['source'],
        );
    }

    /**
     * A fraction such as 0.15 as whole basis points (1500), rounded up, using the decimal digits
     * as text so no float multiplication can land just below the real value.
     */
    private function basisPoints(int|float|string $fraction): int
    {
        [$whole, $decimals] = explode('.', number_format((float) $fraction, 8, '.', ''));

        $basisPoints = (int) $whole * 10000 + (int) substr($decimals, 0, 4);

        // anything left after the 4th decimal (0.15001 -> 1500.1) rounds up
        return (int) substr($decimals, 4) > 0 ? $basisPoints + 1 : $basisPoints;
    }
}
