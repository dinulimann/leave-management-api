<?php

namespace Tests\Feature;

use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeaveFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $employee;

    private LeaveType $annual;

    private LeaveType $sick;

    private LeaveType $unpaid;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed "today" so date rules are deterministic: Monday 12 Oct 2026
        $this->travelTo(CarbonImmutable::parse('2026-10-12 09:00'));

        $this->annual = LeaveType::create(['code' => 'ANNUAL', 'name' => 'Annual leave', 'annual_quota' => 12]);
        $this->sick = LeaveType::create(['code' => 'SICK', 'name' => 'Sick leave', 'annual_quota' => 14, 'allows_past_dates' => true]);
        $this->unpaid = LeaveType::create(['code' => 'UNPAID', 'name' => 'Unpaid leave', 'annual_quota' => null]);

        $this->manager = User::factory()->create(['role' => 'manager']);
        $this->employee = User::factory()->create(['manager_id' => $this->manager->id]);
    }

    private function submit(User $as, array $data)
    {
        Sanctum::actingAs($as);

        return $this->postJson('/api/leave-requests', $data + ['leave_type_id' => $this->annual->id]);
    }

    private function approve(LeaveRequest|int $request, ?User $as = null)
    {
        Sanctum::actingAs($as ?? $this->manager);

        return $this->postJson('/api/leave-requests/'.(is_int($request) ? $request : $request->id).'/approve', ['note' => 'OK']);
    }

    public function test_login_returns_a_token_that_works(): void
    {
        $token = $this->postJson('/api/login', ['email' => $this->employee->email, 'password' => 'password'])
            ->assertOk()->json('token');

        $this->withToken($token)->getJson('/api/me')
            ->assertOk()->assertJsonPath('manager.id', $this->manager->id);
    }

    public function test_endpoints_require_authentication(): void
    {
        $this->getJson('/api/leave-requests')->assertUnauthorized();
    }

    public function test_submitting_counts_working_days_and_starts_pending(): void
    {
        // Mon 2 Nov .. Fri 6 Nov
        $this->submit($this->employee, ['start_date' => '2026-11-02', 'end_date' => '2026-11-06'])
            ->assertCreated()
            ->assertJsonPath('data.working_days', 5)
            ->assertJsonPath('data.status', 'pending');
    }

    public function test_overlapping_requests_are_rejected(): void
    {
        $this->submit($this->employee, ['start_date' => '2026-11-02', 'end_date' => '2026-11-06'])->assertCreated();

        $this->submit($this->employee, ['start_date' => '2026-11-06', 'end_date' => '2026-11-10'])
            ->assertStatus(422)->assertJsonPath('code', 'overlap');
    }

    public function test_pending_days_are_reserved_against_the_balance(): void
    {
        // 10 of 12 days pending...
        $this->submit($this->employee, ['start_date' => '2026-11-02', 'end_date' => '2026-11-13'])->assertCreated();

        // ...so a 3-day request does not fit
        $this->submit($this->employee, ['start_date' => '2026-11-16', 'end_date' => '2026-11-18'])
            ->assertStatus(422)->assertJsonPath('code', 'insufficient_balance');

        Sanctum::actingAs($this->employee);
        $this->getJson('/api/leave-balances?year=2026')
            ->assertJsonPath('data.0.pending', 10)
            ->assertJsonPath('data.0.available', 2);
    }

    public function test_annual_leave_cannot_start_in_the_past_but_sick_leave_can(): void
    {
        $this->submit($this->employee, ['start_date' => '2026-10-05', 'end_date' => '2026-10-06'])
            ->assertStatus(422)->assertJsonPath('code', 'past_dates');

        $this->submit($this->employee, ['leave_type_id' => $this->sick->id, 'start_date' => '2026-10-05', 'end_date' => '2026-10-06'])
            ->assertCreated();
    }

    public function test_requests_crossing_a_year_must_be_split(): void
    {
        $this->submit($this->employee, ['start_date' => '2026-12-30', 'end_date' => '2027-01-04'])
            ->assertStatus(422)->assertJsonPath('code', 'crosses_year');
    }

    public function test_weekend_only_requests_are_rejected(): void
    {
        $this->submit($this->employee, ['start_date' => '2026-11-07', 'end_date' => '2026-11-08'])
            ->assertStatus(422)->assertJsonPath('code', 'no_working_days');
    }

    public function test_invalid_input_returns_validation_errors(): void
    {
        $this->submit($this->employee, ['start_date' => '2026-11-06', 'end_date' => '2026-11-02'])
            ->assertStatus(422)->assertJsonValidationErrors('end_date');
    }

    public function test_manager_approval_uses_the_balance(): void
    {
        $id = $this->submit($this->employee, ['start_date' => '2026-11-02', 'end_date' => '2026-11-04'])->json('data.id');

        $this->approve($id)->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.decided_by.id', $this->manager->id);

        $this->assertSame(3, LeaveBalance::where('user_id', $this->employee->id)->value('used'));
    }

    public function test_only_the_employees_own_manager_can_decide(): void
    {
        $id = $this->submit($this->employee, ['start_date' => '2026-11-02', 'end_date' => '2026-11-04'])->json('data.id');
        $otherManager = User::factory()->create(['role' => 'manager']);

        $this->approve($id, $otherManager)->assertForbidden();
        $this->approve($id, $this->employee)->assertForbidden();
    }

    public function test_a_request_cannot_be_decided_twice(): void
    {
        $id = $this->submit($this->employee, ['start_date' => '2026-11-02', 'end_date' => '2026-11-04'])->json('data.id');
        $this->approve($id)->assertOk();

        $this->approve($id)->assertStatus(422)->assertJsonPath('code', 'not_pending');
        Sanctum::actingAs($this->manager);
        $this->postJson("/api/leave-requests/{$id}/reject")->assertStatus(422);
        $this->assertSame(3, LeaveBalance::where('user_id', $this->employee->id)->value('used'));
    }

    public function test_cancelling_approved_future_leave_refunds_the_days(): void
    {
        $id = $this->submit($this->employee, ['start_date' => '2026-11-02', 'end_date' => '2026-11-04'])->json('data.id');
        $this->approve($id)->assertOk();

        Sanctum::actingAs($this->employee);
        $this->postJson("/api/leave-requests/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(0, LeaveBalance::where('user_id', $this->employee->id)->value('used'));
    }

    public function test_approved_leave_cannot_be_cancelled_once_started(): void
    {
        $id = $this->submit($this->employee, ['start_date' => '2026-11-02', 'end_date' => '2026-11-04'])->json('data.id');
        $this->approve($id)->assertOk();

        $this->travelTo(CarbonImmutable::parse('2026-11-03'));
        Sanctum::actingAs($this->employee);
        $this->postJson("/api/leave-requests/{$id}/cancel")
            ->assertStatus(422)->assertJsonPath('code', 'already_started');
    }

    public function test_unpaid_leave_does_not_need_a_balance(): void
    {
        // 15 working days, more than any quota
        $id = $this->submit($this->employee, ['leave_type_id' => $this->unpaid->id, 'start_date' => '2026-11-02', 'end_date' => '2026-11-20'])
            ->assertCreated()->assertJsonPath('data.working_days', 15)->json('data.id');

        $this->approve($id)->assertOk();
        $this->assertSame(0, LeaveBalance::count());
    }

    public function test_employees_cannot_see_each_others_requests_but_their_manager_can(): void
    {
        $id = $this->submit($this->employee, ['start_date' => '2026-11-02', 'end_date' => '2026-11-04'])->json('data.id');
        $colleague = User::factory()->create(['manager_id' => $this->manager->id]);

        Sanctum::actingAs($colleague);
        $this->getJson("/api/leave-requests/{$id}")->assertForbidden();
        $this->postJson("/api/leave-requests/{$id}/cancel")->assertForbidden();

        Sanctum::actingAs($this->manager);
        $this->getJson("/api/leave-requests/{$id}")->assertOk();
        $this->getJson('/api/team/leave-requests?status=pending')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
    }
}
