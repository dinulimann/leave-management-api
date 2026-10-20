<?php

namespace App\Services;

use App\Enums\LeaveStatus;
use App\Exceptions\LeaveRuleViolation;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class LeaveService
{
    public function __construct(private WorkingDayCalculator $calendar) {}

    /** Balance row for the year, created from the leave type's quota on first use. */
    public function balanceFor(User $user, LeaveType $type, int $year, bool $lock = false): LeaveBalance
    {
        LeaveBalance::firstOrCreate(
            ['user_id' => $user->id, 'leave_type_id' => $type->id, 'year' => $year],
            ['entitled' => $type->annual_quota ?? 0, 'used' => 0],
        );

        $query = LeaveBalance::where(['user_id' => $user->id, 'leave_type_id' => $type->id, 'year' => $year]);

        return $lock ? $query->lockForUpdate()->firstOrFail() : $query->firstOrFail();
    }

    /** Days already requested but not decided yet; they are reserved against the balance. */
    public function pendingDays(User $user, LeaveType $type, int $year, ?int $exceptId = null): int
    {
        return (int) LeaveRequest::where('user_id', $user->id)
            ->where('leave_type_id', $type->id)
            ->where('status', LeaveStatus::Pending)
            ->whereYear('start_date', $year)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->sum('working_days');
    }

    public function submit(User $user, LeaveType $type, string $start, string $end, ?string $reason): LeaveRequest
    {
        $startDate = CarbonImmutable::parse($start)->startOfDay();
        $endDate = CarbonImmutable::parse($end)->startOfDay();

        if (! $type->allows_past_dates && $startDate->lt(CarbonImmutable::today())) {
            throw new LeaveRuleViolation('past_dates', "{$type->name} cannot start in the past.");
        }
        if ($startDate->year !== $endDate->year) {
            throw new LeaveRuleViolation('crosses_year', 'Split leave that crosses a year into two requests, one per year.');
        }

        $days = $this->calendar->count($startDate, $endDate);
        if ($days === 0) {
            throw new LeaveRuleViolation('no_working_days', 'The selected dates contain no working days.');
        }

        return DB::transaction(function () use ($user, $type, $startDate, $endDate, $days, $reason) {
            // Serialises concurrent submissions by the same employee
            User::whereKey($user->id)->lockForUpdate()->first();

            $overlap = LeaveRequest::where('user_id', $user->id)->active()
                ->overlapping($startDate->toDateString(), $endDate->toDateString())
                ->exists();
            if ($overlap) {
                throw new LeaveRuleViolation('overlap', 'You already have leave requested or approved on some of these dates.');
            }

            if ($type->tracksBalance()) {
                $balance = $this->balanceFor($user, $type, $startDate->year);
                $available = $balance->entitled - $balance->used - $this->pendingDays($user, $type, $startDate->year);
                if ($days > $available) {
                    throw new LeaveRuleViolation('insufficient_balance',
                        "This request needs {$days} working day(s) but only {$available} are available.");
                }
            }

            return LeaveRequest::create([
                'user_id' => $user->id,
                'leave_type_id' => $type->id,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'working_days' => $days,
                'reason' => $reason,
                'status' => LeaveStatus::Pending,
            ]);
        });
    }

    public function approve(LeaveRequest $request, User $manager, ?string $note): LeaveRequest
    {
        return DB::transaction(function () use ($request, $manager, $note) {
            $request = LeaveRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            $this->ensurePending($request);

            $type = $request->leaveType;
            if ($type->tracksBalance()) {
                $balance = $this->balanceFor($request->user, $type, $request->start_date->year, lock: true);
                if ($balance->used + $request->working_days > $balance->entitled) {
                    throw new LeaveRuleViolation('insufficient_balance', 'The employee no longer has enough balance for this request.');
                }
                $balance->increment('used', $request->working_days);
            }

            $request->update([
                'status' => LeaveStatus::Approved,
                'decided_by' => $manager->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);

            return $request;
        });
    }

    public function reject(LeaveRequest $request, User $manager, ?string $note): LeaveRequest
    {
        return DB::transaction(function () use ($request, $manager, $note) {
            $request = LeaveRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            $this->ensurePending($request);

            $request->update([
                'status' => LeaveStatus::Rejected,
                'decided_by' => $manager->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);

            return $request;
        });
    }

    /** Pending requests can always be cancelled; approved ones only before they start (days are refunded). */
    public function cancel(LeaveRequest $request): LeaveRequest
    {
        return DB::transaction(function () use ($request) {
            $request = LeaveRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($request->status === LeaveStatus::Approved) {
                if (! $request->start_date->isAfter(CarbonImmutable::today())) {
                    throw new LeaveRuleViolation('already_started', 'Approved leave can only be cancelled before it starts.');
                }
                $type = $request->leaveType;
                if ($type->tracksBalance()) {
                    $this->balanceFor($request->user, $type, $request->start_date->year, lock: true)
                        ->decrement('used', $request->working_days);
                }
            } elseif ($request->status !== LeaveStatus::Pending) {
                throw new LeaveRuleViolation('not_cancellable', "A {$request->status->value} request cannot be cancelled.");
            }

            $request->update(['status' => LeaveStatus::Cancelled]);

            return $request;
        });
    }

    private function ensurePending(LeaveRequest $request): void
    {
        if ($request->status !== LeaveStatus::Pending) {
            throw new LeaveRuleViolation('not_pending', "This request is already {$request->status->value}.");
        }
    }
}
