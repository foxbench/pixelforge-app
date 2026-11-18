<?php

use App\Support\Money;

it('parses decimal strings into picodollars exactly', function () {
    expect(Money::parse('1'))->toBe(1_000_000_000_000)
        ->and(Money::parse('0.373875'))->toBe(373_875_000_000)
        ->and(Money::parse('0.000000000001'))->toBe(1)
        ->and(Money::parse('0.046125000'))->toBe(46_125_000_000)
        ->and(Money::parse('0'))->toBe(0);
});

it('rejects anything that is not a plain decimal or has too many decimals', function (string $bad) {
    Money::parse($bad);
})->throws(InvalidArgumentException::class)->with(['', '-1', '1e3', '1,5', '$5', '0.0000000000001', '99999999999']);

it('converts a per-million-token rate to picodollars per token', function () {
    // $12.50 per 1M tokens = $0.0000125 per token = 12,500,000 picodollars
    expect(Money::picoPerToken('12.50'))->toBe(12_500_000)
        ->and(Money::picoPerToken('5.00'))->toBe(5_000_000)
        ->and(Money::picoPerToken('40'))->toBe(40_000_000);
});

it('refuses rates that cannot be converted exactly', function () {
    Money::picoPerToken('5.0000001'); // 7 decimals
})->throws(InvalidArgumentException::class);

it('formats picodollars for display and exactly', function () {
    $user = Money::parse('0.46734375');

    expect(Money::format($user, 6))->toBe('0.467344') // display rounds half up
        ->and(Money::format($user, 9))->toBe('0.467343750')
        ->and(Money::exact($user))->toBe('0.46734375')
        ->and(Money::exact(Money::parse('5')))->toBe('5.00')
        ->and(Money::exact(Money::parse('5.5')))->toBe('5.50')
        ->and(Money::exact(0))->toBe('0.00')
        ->and(Money::format(-1_500_000, 6))->toBe('-0.000002')
        ->and(Money::format(Money::parse('2'), 0))->toBe('2');
});

it('parses then formats back to the same string', function (string $usd) {
    expect(Money::exact(Money::parse($usd)))->toBe($usd);
})->with(['0.373875', '0.46734375', '12.07', '0.000000000001']);

it('computes the documented worked example: max cost 0.373875 -> user pays 0.46734375', function () {
    $rates = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];
    // 400 characters / 4 = 100, +15% = 115; 2 images x 765; high 1536x1024 output; high reasoning max
    $cost = Money::providerCost([
        'input_text_tokens' => 115,
        'input_image_tokens' => 1530,
        'output_tokens' => 6450,
        'reasoning_tokens' => 8000,
    ], $rates);

    expect($cost)->toBe(Money::parse('0.373875'))
        ->and(Money::format($cost, 9))->toBe('0.373875000')
        ->and(Money::withMarkup($cost))->toBe(Money::parse('0.46734375'))
        ->and(Money::exact(Money::withMarkup($cost)))->toBe('0.46734375');
});

it('adds 25 percent without rounding for any nanodollar amount', function () {
    // every provider cost is a whole number of nanodollars (1000 picodollars)
    foreach ([1, 3, 7, 999, 123_456_789] as $nano) {
        $cost = $nano * 1000;
        expect(Money::withMarkup($cost) * 4)->toBe($cost * 5);
    }
});

it('rounds the markup up, never down, if a rate ever had unusual decimals', function () {
    expect(Money::withMarkup(1))->toBe(2); // 1.25 -> 2
});
