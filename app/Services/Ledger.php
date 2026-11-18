<?php

namespace App\Services;

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The only code that changes a user's balance. Every change writes one ledger row and updates
 * users.balance_pico in the same database transaction, so the cached balance can't drift
 * from the ledger.
 *
 * Money flow of one generation:
 *   hold    -max estimate   when the user submits (reserves the worst case)
 *   release +max estimate   when it finishes, succeeded or failed (gives the reserve back)
 *   charge  -actual charge  only if it succeeded
 * so a failed generation nets to exactly 0.
 */
class Ledger
{
    public function purchase(User $user, int $pico, string $description): void
    {
        $this->post($user->id, CreditTransaction::PURCHASE, $pico, null, $description);
    }

    public function hold(Generation $generation, int $pico): void
    {
        $this->post($generation->user_id, CreditTransaction::HOLD, -$pico, $generation->id, 'Hold for generation');
    }

    public function release(Generation $generation, int $pico): void
    {
        $this->post($generation->user_id, CreditTransaction::RELEASE, $pico, $generation->id, 'Hold released');
    }

    public function charge(Generation $generation, int $pico): void
    {
        $this->post($generation->user_id, CreditTransaction::CHARGE, -$pico, $generation->id, 'Generation cost');
    }

    private function post(int $userId, string $type, int $amountPico, ?int $generationId, string $description): void
    {
        DB::transaction(function () use ($userId, $type, $amountPico, $generationId, $description) {
            CreditTransaction::create([
                'user_id' => $userId,
                'type' => $type,
                'amount_pico' => $amountPico,
                'generation_id' => $generationId,
                'description' => $description,
            ]);

            // One SQL UPDATE ... SET balance = balance + x, so it is atomic even without a read.
            User::whereKey($userId)->increment('balance_pico', $amountPico);
        });
    }
}
