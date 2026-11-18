<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Per day, per user: what we charged, what Pixelforge billed us, and the profit. A generation
 * belongs to the UTC day it COMPLETED on (same rule as Pixelforge's /v1/usage), and only
 * succeeded generations cost anything. Our per-day provider cost is compared, digit for digit,
 * with /v1/usage's cost_usd.
 */
class ReportController extends Controller
{
    private const MAX_DAYS = 92; // Pixelforge's limit for /v1/usage

    public function index(Request $request, PixelforgeClient $client): Response
    {
        $input = $request->validate([
            'start' => ['nullable', 'date_format:Y-m-d'],
            'end' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $end = Carbon::parse($input['end'] ?? 'today', 'UTC')->startOfDay();
        $start = isset($input['start']) ? Carbon::parse($input['start'], 'UTC')->startOfDay() : $end->copy()->subDays(13);

        if ($start->greaterThan($end) || $start->diffInDays($end) + 1 > self::MAX_DAYS) {
            throw ValidationException::withMessages([
                'start' => 'Choose a range of 1 to '.self::MAX_DAYS.' days (start on or before end).',
            ]);
        }

        // [day => [user rows]]. Sums are done by the database on exact integer columns.
        $rows = Generation::query()
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->where('generations.status', 'succeeded')
            ->where('generations.completed_at', '>=', $start)
            ->where('generations.completed_at', '<', $end->copy()->addDay())
            ->selectRaw('date(generations.completed_at) as day, generations.user_id, users.name, users.email')
            ->selectRaw('count(*) as generations, sum(generations.charge_pico) as charged, sum(generations.provider_cost_pico) as provider_cost')
            ->groupBy('day', 'generations.user_id', 'users.name', 'users.email')
            ->orderBy('users.name')
            ->get()
            ->groupBy('day');

        [$usageByDay, $usageError] = $this->fetchUsage($client, $start, $end);

        $days = [];
        $grand = ['generations' => 0, 'charged' => 0, 'provider_cost' => 0];

        for ($day = $end->copy(); $day->greaterThanOrEqualTo($start); $day->subDay()) {
            $date = $day->toDateString();
            $users = [];
            $total = ['generations' => 0, 'charged' => 0, 'provider_cost' => 0];

            foreach ($rows->get($date, collect()) as $row) {
                $charged = (int) $row->charged;
                $cost = (int) $row->provider_cost;
                $users[] = [
                    'name' => $row->name,
                    'email' => $row->email,
                    'generations' => (int) $row->generations,
                    'charged' => Money::view($charged, 9),
                    'provider_cost' => Money::view($cost, 9),
                    'profit' => Money::view($charged - $cost, 9),
                ];
                $total['generations'] += (int) $row->generations;
                $total['charged'] += $charged;
                $total['provider_cost'] += $cost;
            }

            $usageCost = $usageByDay[$date] ?? null;
            $days[] = [
                'date' => $date,
                'users' => $users,
                'totals' => [
                    'generations' => $total['generations'],
                    'charged' => Money::view($total['charged'], 9),
                    'provider_cost' => Money::view($total['provider_cost'], 9),
                    'profit' => Money::view($total['charged'] - $total['provider_cost'], 9),
                ],
                'usage_cost' => $usageCost,
                // exact string comparison of 9-decimal amounts; null when /v1/usage was unavailable
                'matches_usage' => $usageCost === null ? null : $usageCost === Money::format($total['provider_cost'], 9),
            ];

            $grand['generations'] += $total['generations'];
            $grand['charged'] += $total['charged'];
            $grand['provider_cost'] += $total['provider_cost'];
        }

        return Inertia::render('admin/Report', [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'days' => $days,
            'grand_total' => [
                'generations' => $grand['generations'],
                'charged' => Money::view($grand['charged'], 9),
                'provider_cost' => Money::view($grand['provider_cost'], 9),
                'profit' => Money::view($grand['charged'] - $grand['provider_cost'], 9),
            ],
            'usage_error' => $usageError,
        ]);
    }

    /**
     * @return array{0: array<string, string>, 1: string|null} [date => cost_usd, error message]
     */
    private function fetchUsage(PixelforgeClient $client, Carbon $start, Carbon $end): array
    {
        try {
            $usage = $client->usage($start->toDateString(), $end->toDateString());

            return [collect($usage['data'])->pluck('cost_usd', 'date')->all(), null];
        } catch (Throwable $e) {
            Log::warning('Pixelforge /v1/usage failed: '.$e->getMessage());

            return [[], 'Could not load /v1/usage from Pixelforge, so nothing could be compared.'];
        }
    }
}
