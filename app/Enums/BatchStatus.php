<?php

namespace App\Enums;

enum BatchStatus: string
{
    case PENDING = 'pending';
    case COMMITTED = 'committed';
    case REJECTED = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::COMMITTED => 'Committed',
            self::REJECTED => 'Rejected',
        };
    }
}