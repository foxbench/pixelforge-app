<?php

namespace App\Http\Controllers;

use App\Models\CreditTransaction;
use App\Services\Ledger;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CreditController extends Controller
{
    /** Whole dollars the user may "buy" (there is no real payment). */
    public const PACKS = [1, 5, 20];

    public function index(Request $request): Response
    {
        return Inertia::render('credits/Index', [
            'packs' => self::PACKS,
            'transactions' => $request->user()->creditTransactions()
                ->latest('id')
                ->limit(100)
                ->get()
                ->map(fn (CreditTransaction $t) => [
                    'id' => $t->id,
                    'type' => $t->type,
                    'description' => $t->description,
                    'amount' => Money::view($t->amount_pico),
                    'created_at' => $t->created_at->toIso8601String(),
                ]),
        ]);
    }

    /**
     * Fake purchase: no payment provider, it just adds credit.
     */
    public function store(Request $request, Ledger $ledger): RedirectResponse
    {
        $data = $request->validate([
            'dollars' => ['required', 'integer', Rule::in(self::PACKS)],
        ]);

        $ledger->purchase($request->user(), Money::parse((string) $data['dollars']), 'Purchased $'.$data['dollars'].' of credit');

        return to_route('credits.index');
    }
}
