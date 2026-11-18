<?php

namespace App\Http\Controllers;

use App\Enums\GenerationStatus;
use App\Http\Requests\StoreGenerationRequest;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\CostEstimator;
use App\Services\Ledger;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('generations/Create', [
            'sizes' => StoreGenerationRequest::SIZES,
            'qualities' => StoreGenerationRequest::QUALITIES,
        ]);
    }

    /**
     * Live estimate for the form. Takes the prompt LENGTH only (that is all the pricing needs).
     */
    public function estimate(Request $request, CostEstimator $estimator): JsonResponse
    {
        $data = $request->validate([
            'chars' => ['required', 'integer', 'min:0', 'max:4000'],
            'size' => ['required', Rule::in(StoreGenerationRequest::SIZES)],
            'quality' => ['required', Rule::in(StoreGenerationRequest::QUALITIES)],
            'images' => ['required', 'integer', 'min:0', 'max:4'],
        ]);

        $estimate = $estimator->estimate((int) $data['chars'], $data['size'], $data['quality'], (int) $data['images']);

        return response()->json([
            'typical' => Money::view($estimate->typicalChargePico),
            'max' => Money::view($estimate->maxChargePico),
            'affordable' => $request->user()->balance_pico >= $estimate->maxChargePico,
            'pricing_source' => $estimate->pricingSource,
        ]);
    }

    public function store(StoreGenerationRequest $request, CostEstimator $estimator, Ledger $ledger): RedirectResponse
    {
        $data = $request->validated();

        $estimate = $estimator->estimate(
            mb_strlen($data['prompt']),
            $data['size'],
            $data['quality'],
            count($data['reference_images']),
        );

        $generation = DB::transaction(function () use ($request, $data, $estimate, $ledger) {
            // Two simultaneous submits must not both spend the same balance. On SQLite lockForUpdate() is a
            // no-op (transaction_mode IMMEDIATE in config/database.php serialises writers); on MySQL/Postgres it locks.
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();

            if ($user->balance_pico < $estimate->maxChargePico) {
                throw ValidationException::withMessages([
                    'balance' => 'Not enough credit: this could cost up to $'.Money::format($estimate->maxChargePico)
                        .' and your balance is $'.Money::format($user->balance_pico).'. Buy credits first.',
                ]);
            }

            $generation = $user->generations()->create([
                'uuid' => (string) Str::uuid(),
                'prompt' => $data['prompt'],
                'size' => $data['size'],
                'quality' => $data['quality'],
                'reference_images' => $data['reference_images'],
                'status' => GenerationStatus::PendingSubmit,
                'estimated_max_charge_pico' => $estimate->maxChargePico,
                'estimated_typical_charge_pico' => $estimate->typicalChargePico,
            ]);

            // Reserve the worst case now; ApplyGenerationState gives it back when the job finishes.
            $ledger->hold($generation, $estimate->maxChargePico);

            return $generation;
        });

        // After the commit, so the job can never run before the row exists. If this line is never
        // reached (crash), the poller re-dispatches generations stuck in pending_submit.
        SubmitGeneration::dispatch($generation->id);

        return to_route('generations.index');
    }

    public function index(Request $request): Response
    {
        return Inertia::render('generations/Index', [
            'generations' => fn () => $request->user()->generations()
                ->latest('id')
                ->limit(50)
                ->get()
                ->map(fn (Generation $g) => [
                    'id' => $g->id,
                    'prompt' => $g->prompt,
                    'size' => $g->size,
                    'quality' => $g->quality,
                    'status' => $g->status->value,
                    'is_final' => $g->status->isFinal(),
                    'error_code' => $g->error_code,
                    'error_message' => $g->error_message,
                    'estimated_typical' => Money::view($g->estimated_typical_charge_pico),
                    'estimated_max' => Money::view($g->estimated_max_charge_pico),
                    'charge' => $g->charge_pico === null ? null : Money::view($g->charge_pico),
                    'image_url' => $g->image_url,
                    'image_expired' => $g->image_url_expires_at?->isPast() ?? false,
                    'created_at' => $g->created_at->toIso8601String(),
                ]),
        ]);
    }
}
