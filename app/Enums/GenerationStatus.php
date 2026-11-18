<?php

namespace App\Enums;

enum GenerationStatus: string
{
    case PendingSubmit = 'pending_submit';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /**
     * Position in the lifecycle. A generation may only move to a higher rank.
     */
    public function rank(): int
    {
        return match ($this) {
            self::PendingSubmit => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }

    public function isFinal(): bool
    {
        return $this->rank() === 3;
    }
}
