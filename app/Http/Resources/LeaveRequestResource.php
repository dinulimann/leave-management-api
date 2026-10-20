<?php

namespace App\Http\Resources;

use App\Models\LeaveRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LeaveRequest */
class LeaveRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee' => ['id' => $this->user->id, 'name' => $this->user->name],
            'leave_type' => ['id' => $this->leaveType->id, 'code' => $this->leaveType->code, 'name' => $this->leaveType->name],
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'working_days' => $this->working_days,
            'reason' => $this->reason,
            'status' => $this->status->value,
            'decided_by' => $this->whenLoaded('decider', fn () => $this->decider ? ['id' => $this->decider->id, 'name' => $this->decider->name] : null),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_note' => $this->decision_note,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
