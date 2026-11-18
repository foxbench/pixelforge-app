<?php

use App\Console\Commands\MakeAdmin;
use App\Models\CreditTransaction;
use App\Models\User;
use App\Support\Money;
use Inertia\Testing\AssertableInertia as Assert;

it('gives every new user a $2.00 welcome credit as a ledger row', function () {
    $this->post('/register', [
        'name' => 'Rae Viewer',
        'email' => 'rae@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect();

    $user = User::where('email', 'rae@example.com')->sole();
    $row = CreditTransaction::sole();

    expect($user->balance_pico)->toBe(Money::parse('2'))
        ->and($row->type)->toBe('purchase')
        ->and($row->description)->toBe('Welcome credit')
        ->and($row->amount_pico)->toBe(Money::parse('2'));
});

it('buys credit packs: balance and ledger move together', function (int $dollars) {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/credits', ['dollars' => $dollars])->assertRedirect('/credits');

    expect($user->fresh()->balance_pico)->toBe(Money::parse((string) $dollars))
        ->and($user->creditTransactions()->sole()->amount_pico)->toBe(Money::parse((string) $dollars));
})->with([1, 5, 20]);

it('refuses amounts that are not an offered pack', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/credits', ['dollars' => 1000])->assertSessionHasErrors('dollars');

    expect($user->fresh()->balance_pico)->toBe(0);
});

it('shows the balance and ledger on the credits page', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->post('/credits', ['dollars' => 5]);

    $this->actingAs($user->fresh())->get('/credits')
        ->assertInertia(fn (Assert $page) => $page
            ->component('credits/Index')
            ->where('auth.balance.exact', '5.00')
            ->has('transactions', 1)
            ->where('transactions.0.amount.exact', '5.00'));
});

it('keeps balance_pico equal to the sum of the ledger', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->post('/credits', ['dollars' => 20]);
    $this->actingAs($user)->post('/credits', ['dollars' => 1]);

    expect($user->fresh()->balance_pico)->toBe($user->creditTransactions()->sum('amount_pico'));
});

it('makes a user an admin from the command line', function () {
    $user = User::factory()->create(['email' => 'boss@example.com']);

    $this->artisan('app:make-admin', ['email' => 'boss@example.com'])->assertSuccessful();
    expect($user->fresh()->is_admin)->toBeTrue();

    $this->artisan('app:make-admin', ['email' => 'nobody@example.com'])->assertFailed();
});

it('does not let a user make themselves admin or change their balance through mass assignment', function () {
    $user = User::factory()->create();

    $user->update(['is_admin' => true, 'balance_pico' => 999]);

    expect($user->fresh()->is_admin)->toBeFalse()
        ->and($user->fresh()->balance_pico)->toBe(0);
});
