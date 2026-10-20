<?php

namespace App\Enums;

enum LeaveStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /** Statuses that block the dates and count against the balance. */
    public static function active(): array
    {
        return [self::Pending, self::Approved];
    }
}
