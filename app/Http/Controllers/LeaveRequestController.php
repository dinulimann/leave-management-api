<?php

namespace App\Http\Controllers;

use App\Enums\LeaveStatus;
use App\Http\Requests\DecisionRequest;
use App\Http\Requests\StoreLeaveRequest;
use App\Http\Resources\LeaveRequestResource;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\LeaveService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class LeaveRequestController extends Controller
{
    public function __construct(private LeaveService $leave) {}

    /** The signed-in employee's own requests. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['status' => ['nullable', Rule::enum(LeaveStatus::class)]]);

        $requests = $request->user()->leaveRequests()
            ->with(['user', 'leaveType', 'decider'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->latest('start_date')
            ->paginate(20);

        return LeaveRequestResource::collection($requests);
    }

    /** Requests from the manager's direct reports, pending first. */
    public function team(Request $request): AnonymousResourceCollection
    {
        $request->validate(['status' => ['nullable', Rule::enum(LeaveStatus::class)]]);

        $requests = LeaveRequest::query()
            ->whereIn('user_id', $request->user()->reports()->select('id'))
            ->with(['user', 'leaveType', 'decider'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderBy('start_date')
            ->paginate(20);

        return LeaveRequestResource::collection($requests);
    }

    public function store(StoreLeaveRequest $request): LeaveRequestResource
    {
        $leave = $this->leave->submit(
            $request->user(),
            LeaveType::findOrFail($request->integer('leave_type_id')),
            $request->string('start_date')->toString(),
            $request->string('end_date')->toString(),
            $request->input('reason'),
        );

        return new LeaveRequestResource($leave->load(['user', 'leaveType']));
    }

    public function show(LeaveRequest $leaveRequest): LeaveRequestResource
    {
        Gate::authorize('view', $leaveRequest);

        return new LeaveRequestResource($leaveRequest->load(['user', 'leaveType', 'decider']));
    }

    public function cancel(LeaveRequest $leaveRequest): LeaveRequestResource
    {
        Gate::authorize('cancel', $leaveRequest);

        return new LeaveRequestResource($this->leave->cancel($leaveRequest)->load(['user', 'leaveType', 'decider']));
    }

    public function approve(DecisionRequest $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        Gate::authorize('decide', $leaveRequest);

        $leave = $this->leave->approve($leaveRequest, $request->user(), $request->input('note'));

        return new LeaveRequestResource($leave->load(['user', 'leaveType', 'decider']));
    }

    public function reject(DecisionRequest $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        Gate::authorize('decide', $leaveRequest);

        $leave = $this->leave->reject($leaveRequest, $request->user(), $request->input('note'));

        return new LeaveRequestResource($leave->load(['user', 'leaveType', 'decider']));
    }
}
