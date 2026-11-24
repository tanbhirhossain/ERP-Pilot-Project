<?php

namespace Modules\HRM\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\HRM\Database\Seeders\ApprovalChainSeeder;
use Modules\HRM\Models\Approval\ApprovalChain;
use Modules\HRM\Models\Approval\ApprovalChainStep;
use Modules\HRM\Support\Api\ApiResponse;

/**
 * Chain admin API (Phase 2): read + reorder the dynamic approval chains.
 *
 * The future web flow-builder will sit on these endpoints; until then HR
 * drives them directly.
 *
 * GET /api/v1/admin/chains            both chains with steps
 * GET /api/v1/admin/chains/{key}      one chain (key: leave|overtime)
 * PUT /api/v1/admin/chains/{key}      replace steps (1–10, ordered)
 *
 * Guarded by hrm.approval-chains.view (GET) / .manage (PUT), checked against
 * the token user in-controller — Spatie's route middleware binds the web
 * guard and would 403 every API caller.
 *
 * Editing a chain only affects NEW requests: every request snapshots the
 * labels at creation and in-flight ones keep walking their snapshot.
 */
class ChainAdminApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->can(ApprovalChainSeeder::PERM_VIEW)) {
            return ApiResponse::error('You may not view approval chains.', 403);
        }

        return ApiResponse::ok(
            ApprovalChain::with('steps')->orderBy('key')->get(),
            'Approval chains.'
        );
    }

    public function show(Request $request, string $key): JsonResponse
    {
        if (! $request->user()->can(ApprovalChainSeeder::PERM_VIEW)) {
            return ApiResponse::error('You may not view approval chains.', 403);
        }

        $chain = ApprovalChain::where('key', $key)->with('steps')->first();

        if ($chain === null) {
            return ApiResponse::error('Approval chain not found.', 404);
        }

        return ApiResponse::ok($chain, 'Approval chain.');
    }

    public function update(Request $request, string $key): JsonResponse
    {
        if (! $request->user()->can(ApprovalChainSeeder::PERM_MANAGE)) {
            return ApiResponse::error('You may not edit approval chains.', 403);
        }

        $chain = ApprovalChain::where('key', $key)->first();

        if ($chain === null) {
            return ApiResponse::error('Approval chain not found.', 404);
        }

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:150'],
            'is_active' => ['nullable', 'boolean'],
            'steps' => ['required', 'array', 'min:1', 'max:10'],
            'steps.*.stage_key' => ['required', Rule::in(ApprovalChainStep::STAGE_KEYS)],
            'steps.*.stage_label' => ['required', 'string', 'max:100'],
            'steps.*.resolver' => ['required', Rule::in(ApprovalChainStep::RESOLVERS)],
            'steps.*.role_name' => ['nullable', 'string', 'max:100', 'exists:roles,name'],
        ]);

        foreach ($data['steps'] as $i => $step) {
            if ($step['resolver'] === 'role' && empty($step['role_name'])) {
                return ApiResponse::error("Step ".($i + 1).": a role resolver needs role_name.", 422);
            }

            if ($step['resolver'] === 'supervisor' && ! empty($step['role_name'])) {
                return ApiResponse::error("Step ".($i + 1).": a supervisor resolver takes no role_name.", 422);
            }
        }

        DB::transaction(function () use ($chain, $data): void {
            if (array_key_exists('name', $data) && $data['name'] !== null) {
                $chain->name = $data['name'];
            }

            if (array_key_exists('is_active', $data) && $data['is_active'] !== null) {
                $chain->is_active = (bool) $data['is_active'];
            }

            $chain->save();

            ApprovalChainStep::where('approval_chain_id', $chain->id)->delete();

            foreach (array_values($data['steps']) as $position => $step) {
                ApprovalChainStep::create([
                    'approval_chain_id' => $chain->id,
                    'position' => $position,
                    'stage_key' => $step['stage_key'],
                    'stage_label' => $step['stage_label'],
                    'resolver' => $step['resolver'],
                    'role_name' => $step['resolver'] === 'role' ? $step['role_name'] : null,
                ]);
            }
        });

        return ApiResponse::ok(
            $chain->fresh()->load('steps'),
            'Chain updated. New requests follow it; in-flight requests keep their snapshot.'
        );
    }
}
