<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

function completedGeneration(User $user, string $completedAt, string $costUsd, array $attributes = []): Generation
{
    $cost = Money::parse($costUsd);

    return Generation::factory()->for($user)->create($attributes + [
        'status' => GenerationStatus::Succeeded,
        'provider_id' => 'gen_'.fake()->unique()->bothify('????????'),
        'provider_cost_pico' => $cost,
        'charge_pico' => Money::withMarkup($cost),
        'completed_at' => $completedAt,
    ]);
}

function fakeUsage(array $costsByDate): void
{
    $data = collect($costsByDate)->map(fn ($cost, $date) => [
        'date' => $date, 'generations' => 1, 'input_text_tokens' => 0, 'input_image_tokens' => 0,
        'output_tokens' => 0, 'reasoning_tokens' => 0, 'cost_usd' => $cost,
    ])->values()->all();

    Http::fake([API.'/usage*' => Http::response(['object' => 'usage', 'data' => $data, 'total' => []])]);
}

beforeEach(function () {
    Carbon::setTestNow('2026-11-18 12:00:00');
});

it('is forbidden for non-admins and redirects guests to login', function () {
    $this->get('/admin/report')->assertRedirect('/login');
    $this->actingAs(User::factory()->create())->get('/admin/report')->assertForbidden();
});

it('shows per day per user charged, provider cost and profit; totals equal the sum of the charges', function () {
    $admin = User::factory()->admin()->create();
    $alice = User::factory()->create(['name' => 'Alice']);
    $bob = User::factory()->create(['name' => 'Bob']);

    // 2026-11-17: Alice two generations, Bob one. 2026-11-16: Bob one.
    completedGeneration($alice, '2026-11-17 01:00:00', '0.30551');
    completedGeneration($alice, '2026-11-17 23:59:59', '0.069575');
    completedGeneration($bob, '2026-11-17 12:00:00', '0.373875');
    completedGeneration($bob, '2026-11-16 08:00:00', '0.058250');
    // not counted: failed, and completed outside the range
    completedGeneration($bob, '2026-11-17 10:00:00', '0', ['status' => GenerationStatus::Failed, 'charge_pico' => 0, 'provider_cost_pico' => 0]);
    completedGeneration($bob, '2026-10-01 10:00:00', '9.99');
    fakeUsage(['2026-11-17' => '0.748960000', '2026-11-16' => '0.058250000']);

    $day17Cost = Money::parse('0.30551') + Money::parse('0.069575') + Money::parse('0.373875');
    $day17Charged = Money::withMarkup(Money::parse('0.30551')) + Money::withMarkup(Money::parse('0.069575')) + Money::withMarkup(Money::parse('0.373875'));

    $this->actingAs($admin)->get('/admin/report')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Report')
            ->where('start', '2026-11-05')
            ->where('end', '2026-11-18')
            ->has('days', 14)
            ->where('days.0.date', '2026-11-18')
            ->where('days.0.totals.generations', 0)
            ->where('days.1.date', '2026-11-17')
            ->where('days.1.totals.generations', 3)
            ->where('days.1.totals.provider_cost.display', Money::format($day17Cost, 9))
            ->where('days.1.totals.charged.display', Money::format($day17Charged, 9))
            ->where('days.1.totals.profit.display', Money::format($day17Charged - $day17Cost, 9))
            ->has('days.1.users', 2)
            ->where('days.1.users.0.name', 'Alice')
            ->where('days.1.users.0.generations', 2)
            ->where('days.1.users.1.name', 'Bob')
            ->where('days.2.date', '2026-11-16')
            ->where('days.2.users.0.name', 'Bob')
            ->where('grand_total.generations', 4)
            ->where('grand_total.provider_cost.exact', Money::exact($day17Cost + Money::parse('0.05825')))
            ->where('usage_error', null));
});

it('compares our daily provider cost with /v1/usage digit for digit', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    completedGeneration($user, '2026-11-17 10:00:00', '0.30551');
    completedGeneration($user, '2026-11-16 10:00:00', '0.058250');
    // 17th matches exactly; the 16th is off by one nano; days without usage match at 0.000000000
    fakeUsage(['2026-11-18' => '0.000000000', '2026-11-17' => '0.305510000', '2026-11-16' => '0.058250001']);

    $this->actingAs($admin)->get('/admin/report')
        ->assertInertia(fn (Assert $page) => $page
            ->where('days.0.matches_usage', true)
            ->where('days.1.usage_cost', '0.305510000')
            ->where('days.1.matches_usage', true)
            ->where('days.2.usage_cost', '0.058250001')
            ->where('days.2.matches_usage', false));
});

it('still shows our numbers when /v1/usage is unavailable', function () {
    $admin = User::factory()->admin()->create();
    completedGeneration(User::factory()->create(), '2026-11-17 10:00:00', '0.30551');
    Http::fake([API.'/usage*' => Http::response('boom', 500)]);

    $this->actingAs($admin)->get('/admin/report')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('days.1.totals.generations', 1)
            ->where('days.1.matches_usage', null)
            ->whereNot('usage_error', null));
});

it('accepts a custom date range and rejects ranges longer than 92 days', function () {
    $admin = User::factory()->admin()->create();
    fakeUsage([]);

    $this->actingAs($admin)->get('/admin/report?start=2026-11-10&end=2026-11-12')
        ->assertInertia(fn (Assert $page) => $page->has('days', 3)->where('days.2.date', '2026-11-10'));

    $this->actingAs($admin)->get('/admin/report?start=2026-01-01&end=2026-11-12')->assertSessionHasErrors('start');
    $this->actingAs($admin)->get('/admin/report?start=2026-11-12&end=2026-11-10')->assertSessionHasErrors('start');
});
