<?php

namespace App\Http\Controllers;

use App\Models\Holiday;
use App\Models\LeaveType;
use App\Services\LeaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveBalanceController extends Controller
{
    public function __construct(private LeaveService $leave) {}

    public function types(): JsonResponse
    {
        return response()->json(['data' => LeaveType::orderBy('id')->get(['id', 'code', 'name', 'annual_quota', 'allows_past_dates'])]);
    }

    /** Entitled / used / pending / available per leave type for one year. */
    public function balances(Request $request): JsonResponse
    {
        $year = (int) ($request->validate(['year' => ['nullable', 'integer', 'between:2000,2100']])['year'] ?? now()->year);
        $user = $request->user();

        $data = LeaveType::whereNotNull('annual_quota')->orderBy('id')->get()->map(function (LeaveType $type) use ($user, $year) {
            $balance = $this->leave->balanceFor($user, $type, $year);
            $pending = $this->leave->pendingDays($user, $type, $year);

            return [
                'leave_type' => ['id' => $type->id, 'code' => $type->code, 'name' => $type->name],
                'year' => $year,
                'entitled' => $balance->entitled,
                'used' => $balance->used,
                'pending' => $pending,
                'available' => $balance->entitled - $balance->used - $pending,
            ];
        });

        return response()->json(['data' => $data]);
    }

    public function holidays(Request $request): JsonResponse
    {
        $year = (int) ($request->integer('year') ?: now()->year);

        return response()->json(['data' => Holiday::whereYear('date', $year)->orderBy('date')->get(['date', 'name'])
            ->map(fn ($h) => ['date' => $h->date->toDateString(), 'name' => $h->name])]);
    }
}
