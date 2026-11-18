<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Money is stored as integer picodollars: 1 USD = 10^12.
 *
 * Pixelforge costs have at most 9 decimals (nanodollars) and our price is cost x 1.25,
 * which is cost x 1250 picodollars-per-nanodollar / 1000 -- always a whole number of
 * picodollars, so there is no rounding anywhere and no floats.
 */
class Money
{
    public const PICO_PER_USD = 1_000_000_000_000;

    /**
     * Parse an exact decimal USD string ("0.373875", "5") into picodollars.
     */
    public static function parse(string $usd): int
    {
        return self::parseDecimal($usd, 12);
    }

    /**
     * Pixelforge quotes rates in USD per 1M tokens ("12.50").
     * USD per token = rate / 10^6, so picodollars per token = rate x 10^12 / 10^6 = rate x 10^6.
     * That is a whole number as long as the rate has at most 6 decimals; otherwise we throw
     * rather than silently round.
     */
    public static function picoPerToken(string $ratePerMillionTokens): int
    {
        return self::parseDecimal($ratePerMillionTokens, 6);
    }

    /**
     * Provider cost of a generation: sum of tokens x rate, using that generation's own rates.
     *
     * @param  array<string, int>  $usage  token counts keyed like the rates
     * @param  array<string, string>  $rates  USD per 1M tokens
     */
    public static function providerCost(array $usage, array $rates): int
    {
        $total = 0;
        foreach (['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'] as $kind) {
            $total += (int) ($usage[$kind] ?? 0) * self::picoPerToken($rates[$kind]);
        }

        return $total;
    }

    /**
     * What the user pays: provider cost + 25% = cost x 5 / 4.
     * With rates of up to 2 decimals the cost is a multiple of 10^4 picodollars, so this divides
     * exactly. If a future rate had more decimals we round UP (never undercharge) -- never
     * happens with today's rates, and the ceiling keeps the result an integer either way.
     */
    public static function withMarkup(int $providerCostPico): int
    {
        return intdiv($providerCostPico * 5 + 3, 4);
    }

    /**
     * Fixed-decimals string, e.g. format(467343750000, 6) = "0.467344".
     * Only used for display: it rounds half away from zero when $decimals < 12.
     * Use exact() when the full value matters.
     */
    public static function format(int $pico, int $decimals = 6): string
    {
        if ($decimals < 0 || $decimals > 12) {
            throw new InvalidArgumentException('decimals must be between 0 and 12');
        }

        $sign = $pico < 0 ? '-' : '';
        $abs = abs($pico);

        $drop = 12 - $decimals;
        if ($drop > 0) {
            $unit = 10 ** $drop;
            $abs = intdiv($abs + intdiv($unit, 2), $unit) * $unit;
        }

        $whole = intdiv($abs, self::PICO_PER_USD);
        $fraction = str_pad((string) ($abs % self::PICO_PER_USD), 12, '0', STR_PAD_LEFT);
        $fraction = substr($fraction, 0, $decimals);

        return $sign.$whole.($decimals > 0 ? '.'.$fraction : '');
    }

    /**
     * The exact value, without trailing zeros (but at least 2 decimals): "0.46734375".
     */
    public static function exact(int $pico): string
    {
        $text = self::format($pico, 12);
        $text = rtrim($text, '0');

        // keep at least two decimals ("5." -> "5.00", "5.5" -> "5.50")
        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '');

        return $whole.'.'.str_pad($fraction, 2, '0');
    }

    /**
     * What the front end receives: a short display string plus the exact value for a tooltip.
     *
     * @return array{display: string, exact: string}
     */
    public static function view(int $pico, int $decimals = 6): array
    {
        return ['display' => self::format($pico, $decimals), 'exact' => self::exact($pico)];
    }

    /**
     * Strict decimal-string parser: digits with an optional fraction of at most $decimals digits.
     * Returns the value multiplied by 10^$decimals as an int (12 -> picodollars, 6 -> picodollars
     * per token for a per-1M-token rate). Never rounds: too many decimals is an error.
     */
    private static function parseDecimal(string $text, int $decimals): int
    {
        if (! preg_match('/^(\d+)(?:\.(\d+))?$/', $text, $m)) {
            throw new InvalidArgumentException("Not a decimal amount: '{$text}'");
        }

        $fraction = $m[2] ?? '';
        if (strlen($fraction) > $decimals) {
            throw new InvalidArgumentException("More than {$decimals} decimals: '{$text}'");
        }

        $scaled = $m[1].str_pad($fraction, $decimals, '0');

        // Reject values that would overflow a signed 64-bit integer instead of turning into a float.
        if (strlen(ltrim($scaled, '0')) > 18) {
            throw new InvalidArgumentException("Amount too large: '{$text}'");
        }

        return (int) $scaled;
    }
}
