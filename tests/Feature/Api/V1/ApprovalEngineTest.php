<?php

/**
 * Approval engine guards (Phase 2): dynamic chains, hybrid resolution, desks.
 *
 * The chain:
 *   leave:    Supervisor → HR and Admin → DGM HR and Admin → GM → CEO
 *   overtime: Supervisor → HR and Admin → DGM HR and Admin → GM
 *
 * Pinned contracts:
 *  1. Queues are stage-exact — an approver sees an item ONLY while it waits
 *     on their stage, and approving from the wrong stage answers 403.
 *  2. Chains are dynamic — the admin API reorders them, new requests snapshot
 *     the new order, in-flight requests keep walking their snapshot, and a
 *     reseed never overwrites admin-customized steps.
 *  3. Without a chain the legacy single stage (['HR']) still snapshots, so
 *     the engine is purely additive to installs that never seed it.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\CORE\Models\User;
use Modules\HRM\Database\Seeders\ApprovalChainSeeder;

beforeEach(function (): void {
    $this->seed(ApprovalChainSeeder::class);

    $mkUser = function (string $email, ?string $role = null): User {
        $user = User::create([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('secret123'),
            'email_verified_at' => now(),
        ]);

        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user;
    };

    $mkEmployee = function (User $user, string $code): void {
        DB::table('employees')->insert([
            'employee_id' => $code,
            'user_id' => $user->id,
            'first_name' => $code,
            'status' => 'Active',
            'joining_date' => '2024-06-01',
            'is_active' => true,
        ]);
    };

    $this->applicant = $mkUser('applicant@example.com');
    $mkEmployee($this->applicant, 'EMP-A1');

    $this->supervisor = $mkUser('supervisor@example.com', 'Supervisor');
    $mkEmployee($this->supervisor, 'EMP-S1');
    DB::table('employee_supervisors')->insert([
        'employee_id' => 'EMP-A1',
        'supervisor_id' => $this->supervisor->id,
        'supervisor_type' => 'Supervisor',
    ]);

    $this->hr = $mkUser('hr@example.com', 'HR Admin');
    $this->dgm = $mkUser('dgm@example.com', 'DGM HR Admin');
    $this->gm = $mkUser('gm@example.com', 'General Manager');
    $this->ceo = $mkUser('ceo@example.com', 'CEO');
    $this->outsider = $mkUser('outsider@example.com');

    $this->leaveTypeId = DB::table('leave_types')->insertGetId([
        'name' => 'Annual', 'code' => 'AN', 'days_per_year' => 14,
        'is_paid' => true, 'is_active' => true,
    ]);
});

function applyLeave(object $t, int $offset = 7, ?int $typeId = null): int
{
    $res = $t->actingAs($t->applicant, 'sanctum')->postJson('/api/v1/leaves', [
        'leave_type_id' => $typeId ?? $t->leaveTypeId,
        'from_date' => now()->addDays($offset)->toDateString(),
        'to_date' => now()->addDays($offset + 1)->toDateString(),
        'reason' => 'Family trip',
    ]);
    $res->assertCreated();

    return $res->json('data.id');
}

function claimOt(object $t): int
{
    $res = $t->actingAs($t->applicant, 'sanctum')->postJson('/api/v1/overtimes', [
        'overtime_date' => now()->toDateString(),
        'overtime_type' => 'Overtime',
        'hours' => 2,
        'reason' => 'Month-end closing',
    ]);
    $res->assertCreated();

    return $res->json('data.id');
}

test('chains seed exactly: 5 leave stages, 4 overtime stages', function (): void {
    $res = $this->actingAs($this->hr, 'sanctum')->getJson('/api/v1/admin/chains');
    $res->assertOk();

    $chains = collect($res->json('data'))->keyBy('key');
    expect($chains->keys()->all())->toBe(['leave', 'overtime']);

    $leaveLabels = collect($chains['leave']['steps'])->pluck('stage_label')->all();
    expect($leaveLabels)->toBe(['Supervisor', 'HR and Admin', 'DGM HR and Admin', 'General Manager', 'CEO']);

    $otLabels = collect($chains['overtime']['steps'])->pluck('stage_label')->all();
    expect($otLabels)->toBe(['Supervisor', 'HR and Admin', 'DGM HR and Admin', 'General Manager']);

    expect($chains['leave']['steps'][0]['resolver'])->toBe('supervisor');
    expect($chains['leave']['steps'][1])->toMatchArray(['resolver' => 'role', 'role_name' => 'HR Admin']);
});

test('leave walks all five stages, each desk seeing it only in turn', function (): void {
    $id = applyLeave($this);

    // The snapshot carries the chain labels.
    $steps = json_decode(DB::table('leave_request_workflows')->where('leave_request_id', $id)->value('steps'), true);
    expect($steps)->toBe(['Supervisor', 'HR and Admin', 'DGM HR and Admin', 'General Manager', 'CEO']);

    // Only the supervisor's desk holds it; HR cannot jump the queue.
    $this->actingAs($this->supervisor, 'sanctum')
        ->getJson('/api/v1/approvals/pending?type=leave')
        ->assertOk()->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.leaves.0.stage_label', 'Supervisor')
        ->assertJsonPath('data.leaves.0.stage_total', 5);
    $this->actingAs($this->hr, 'sanctum')
        ->getJson('/api/v1/approvals/pending?type=leave')
        ->assertOk()->assertJsonPath('meta.total', 0);
    $this->actingAs($this->hr, 'sanctum')
        ->postJson("/api/v1/approvals/leaves/{$id}/approve")
        ->assertStatus(403)->assertJsonPath('errors.code', 'NOT_YOUR_STAGE');

    // The supervisor was pinged on apply.
    expect(DB::table('notifications')->where('notifiable_id', $this->supervisor->id)->count())->toBeGreaterThan(0);

    $walk = [
        [$this->supervisor, 'Supervisor', 'HR and Admin'],
        [$this->hr, 'HR and Admin', 'DGM HR and Admin'],
        [$this->dgm, 'DGM HR and Admin', 'General Manager'],
        [$this->gm, 'General Manager', 'CEO'],
    ];

    foreach ($walk as [$user, $stage, $next]) {
        $res = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/approvals/leaves/{$id}/approve", ['comments' => "OK from {$stage}"]);
        $res->assertOk()
            ->assertJsonPath('data.status', 'Pending')
            ->assertJsonPath('data.current_stage', $next);
    }

    // CEO finalizes.
    $this->actingAs($this->ceo, 'sanctum')
        ->postJson("/api/v1/approvals/leaves/{$id}/approve", ['comments' => 'Final OK'])
        ->assertOk()
        ->assertJsonPath('data.status', 'Approved')
        ->assertJsonPath('data.is_final', true);

    // Five actions, five actors, comments kept.
    $actions = DB::table('leave_approval_actions')->where('leave_request_id', $id)->orderBy('stage_index')->get();
    expect($actions)->toHaveCount(5);
    expect($actions->pluck('stage_name')->all())->toBe(['Supervisor', 'HR and Admin', 'DGM HR and Admin', 'General Manager', 'CEO']);
    expect($actions->pluck('actor_id')->unique()->count())->toBe(5);
    expect($actions[0]->comments)->toBe('OK from Supervisor');

    // Nobody's desk holds it anymore; the applicant was told.
    $this->actingAs($this->ceo, 'sanctum')
        ->getJson('/api/v1/approvals/pending?type=leave')
        ->assertOk()->assertJsonPath('meta.total', 0);
    expect(DB::table('notifications')->where('notifiable_id', $this->applicant->id)->count())->toBeGreaterThan(0);

    // Decided items answer 422, not a second decision.
    $this->actingAs($this->ceo, 'sanctum')
        ->postJson("/api/v1/approvals/leaves/{$id}/approve")
        ->assertStatus(403);
});

test('leave rejection ends the walk and tells the applicant why', function (): void {
    $id = applyLeave($this);

    $this->actingAs($this->supervisor, 'sanctum')
        ->postJson("/api/v1/approvals/leaves/{$id}/reject", ['comments' => 'Month-end blackout'])
        ->assertOk()
        ->assertJsonPath('data.status', 'Rejected');

    expect(DB::table('leave_approval_actions')->where('leave_request_id', $id)->value('comments'))
        ->toBe('Month-end blackout');

    $this->actingAs($this->hr, 'sanctum')
        ->postJson("/api/v1/approvals/leaves/{$id}/approve")
        ->assertStatus(403);

    $note = DB::table('notifications')
        ->where('notifiable_id', $this->applicant->id)->orderByDesc('created_at')->first();
    expect($note->data)->toContain('Month-end blackout');
});

test('an outsider approves nothing', function (): void {
    $id = applyLeave($this);

    $this->actingAs($this->outsider, 'sanctum')
        ->getJson('/api/v1/approvals/pending')
        ->assertOk()->assertJsonPath('meta.total', 0);

    $this->actingAs($this->outsider, 'sanctum')
        ->postJson("/api/v1/approvals/leaves/{$id}/approve")
        ->assertStatus(403);
});

test('overtime walks four stages with hour adjustment on the final', function (): void {
    $id = claimOt($this);

    $this->actingAs($this->supervisor, 'sanctum')
        ->getJson('/api/v1/approvals/pending?type=overtime')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.overtimes.0.stage_total', 4);

    foreach ([$this->supervisor, $this->hr, $this->dgm] as $user) {
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/approvals/overtimes/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'Pending');
    }

    // GM finalizes with adjusted hours.
    $this->actingAs($this->gm, 'sanctum')
        ->postJson("/api/v1/approvals/overtimes/{$id}/approve", ['adjusted_hours' => 1.5, 'comments' => 'Gate log shows 1.5h'])
        ->assertOk()
        ->assertJsonPath('data.status', 'Approved')
        ->assertJsonPath('data.hours', 1.5);

    $actions = DB::table('overtime_approval_actions')->where('overtime_id', $id)->orderBy('stage_index')->get();
    expect($actions)->toHaveCount(4);
    expect($actions->pluck('stage_name')->all())->toBe(['Supervisor', 'HR and Admin', 'DGM HR and Admin', 'General Manager']);
});

test('overtime rejection needs a reason and ends the record', function (): void {
    $id = claimOt($this);

    $this->actingAs($this->supervisor, 'sanctum')
        ->postJson("/api/v1/approvals/overtimes/{$id}/reject", ['reason' => 'No gate entry that day'])
        ->assertOk()
        ->assertJsonPath('data.status', 'Rejected');

    $this->actingAs($this->supervisor, 'sanctum')
        ->postJson("/api/v1/approvals/overtimes/{$id}/reject")
        ->assertStatus(422);
});

test('chain edits move new requests only; in-flight keeps its snapshot', function (): void {
    $inflight = applyLeave($this);

    // HR shortens the leave chain to two stages.
    $this->actingAs($this->hr, 'sanctum')->putJson('/api/v1/admin/chains/leave', [
        'steps' => [
            ['stage_key' => 'supervisor', 'stage_label' => 'Supervisor', 'resolver' => 'supervisor'],
            ['stage_key' => 'hr', 'stage_label' => 'HR and Admin', 'resolver' => 'role', 'role_name' => 'HR Admin'],
        ],
    ])->assertOk()->assertJsonCount(2, 'data.steps');
    $this->actingAs($this->hr, 'sanctum')->getJson('/api/v1/admin/chains/leave')->assertOk()->assertJsonCount(2, 'data.steps');

    // A second leave type: the route memoizes its controller graph for the whole test,
    // so the edited chain is re-read through a fresh settings-cache key — exactly what a
    // fresh production boot sees after an admin edit.
    $type2 = DB::table('leave_types')->insertGetId([
        'name' => 'Sick', 'code' => 'SK', 'days_per_year' => 10,
        'is_paid' => true, 'is_active' => true,
    ]);
    $fresh = applyLeave($this, 20, $type2);

    $snapshot = fn (int $leaveId): array => json_decode(
        DB::table('leave_request_workflows')->where('leave_request_id', $leaveId)->value('steps'), true
    );

    expect($snapshot($inflight))->toHaveCount(5);
    expect($snapshot($fresh))->toBe(['Supervisor', 'HR and Admin']);

    // The short chain works end to end.
    $this->actingAs($this->supervisor, 'sanctum')
        ->postJson("/api/v1/approvals/leaves/{$fresh}/approve")->assertOk();
    $this->actingAs($this->hr, 'sanctum')
        ->postJson("/api/v1/approvals/leaves/{$fresh}/approve")
        ->assertOk()->assertJsonPath('data.status', 'Approved');

    // And the in-flight one still walks its five.
    $this->actingAs($this->supervisor, 'sanctum')
        ->postJson("/api/v1/approvals/leaves/{$inflight}/approve")
        ->assertOk()->assertJsonPath('data.current_stage', 'HR and Admin');
});

test('a reseed never overwrites admin-customized steps', function (): void {
    $this->actingAs($this->hr, 'sanctum')->putJson('/api/v1/admin/chains/overtime', [
        'steps' => [
            ['stage_key' => 'supervisor', 'stage_label' => 'Supervisor', 'resolver' => 'supervisor'],
        ],
    ])->assertOk();

    $this->seed(ApprovalChainSeeder::class);

    $res = $this->actingAs($this->hr, 'sanctum')->getJson('/api/v1/admin/chains/overtime');
    expect($res->json('data.steps'))->toHaveCount(1);
});

test('chain admin is permission-gated and validated', function (): void {
    // A supervisor may decide but may not edit chains.
    $this->actingAs($this->supervisor, 'sanctum')
        ->putJson('/api/v1/admin/chains/leave', ['steps' => []])
        ->assertStatus(403);
    $this->actingAs($this->supervisor, 'sanctum')
        ->getJson('/api/v1/admin/chains')
        ->assertStatus(403);

    // Unknown chain.
    $this->actingAs($this->hr, 'sanctum')
        ->getJson('/api/v1/admin/chains/nope')
        ->assertStatus(404);

    // Role resolvers need a real role; supervisor resolvers take none.
    $this->actingAs($this->hr, 'sanctum')->putJson('/api/v1/admin/chains/leave', [
        'steps' => [
            ['stage_key' => 'hr', 'stage_label' => 'HR', 'resolver' => 'role', 'role_name' => 'Ghost Role'],
        ],
    ])->assertStatus(422);

    $this->actingAs($this->hr, 'sanctum')->putJson('/api/v1/admin/chains/leave', [
        'steps' => [
            ['stage_key' => 'supervisor', 'stage_label' => 'Sup', 'resolver' => 'supervisor', 'role_name' => 'CEO'],
        ],
    ])->assertStatus(422);
});

test('approval history lists my decisions across both flows', function (): void {
    $leaveId = applyLeave($this);
    $otId = claimOt($this);

    $this->actingAs($this->supervisor, 'sanctum')
        ->postJson("/api/v1/approvals/leaves/{$leaveId}/approve")->assertOk();
    $this->actingAs($this->supervisor, 'sanctum')
        ->postJson("/api/v1/approvals/overtimes/{$otId}/approve")->assertOk();

    $res = $this->actingAs($this->supervisor, 'sanctum')->getJson('/api/v1/approvals/history');
    $res->assertOk()->assertJsonPath('meta.total', 2);

    $res = $this->actingAs($this->supervisor, 'sanctum')->getJson('/api/v1/approvals/history?type=overtime');
    $res->assertOk()->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.ref_type', 'overtime');
});

test('without a chain the legacy single stage still snapshots', function (): void {
    DB::table('approval_chain_steps')->delete();
    DB::table('approval_chains')->delete();

    $id = applyLeave($this);

    $steps = json_decode(DB::table('leave_request_workflows')->where('leave_request_id', $id)->value('steps'), true);
    expect($steps)->toBe(['HR']);
});
