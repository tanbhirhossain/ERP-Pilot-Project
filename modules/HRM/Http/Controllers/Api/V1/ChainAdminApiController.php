<?php

namespace Modules\HRM\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Modules\CORE\Support\Api\ApiEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\HRM\Database\Seeders\ApprovalChainSeeder;
use Modules\HRM\Interfaces\Approval\ApprovalChainRepositoryInterface;
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
    public function __construct(private readonly ApprovalChainRepositoryInterface $chains) {}

    #[ApiEndpoint(
        summary: 'Read all approval chains',
        description: 'The live leave (5 stages) and overtime (4 stages) chains with their steps. Needs hrm.approval-chains.view.',
        response: 'MobileEnvelope',
    )]
    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->can(ApprovalChainSeeder::PERM_VIEW)) {
            return ApiResponse::error('You may not view approval chains.', 403);
        }

        return ApiResponse::ok($this->chains->allWithSteps(), 'Approval chains.');
    }

    #[ApiEndpoint(
        summary: 'Read one approval chain',
        description: 'Needs hrm.approval-chains.view.',
        response: 'MobileEnvelope',
        path: [['name' => 'key', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'enum' => ['leave', 'overtime']]]],
    )]
    public function show(Request $request, string $key): JsonResponse
    {
        if (! $request->user()->can(ApprovalChainSeeder::PERM_VIEW)) {
            return ApiResponse::error('You may not view approval chains.', 403);
        }

        $chain = $this->chains->findByKey($key, true);

        if ($chain === null) {
            return ApiResponse::error('Approval chain not found.', 404);
        }

        return ApiResponse::ok($chain, 'Approval chain.');
    }

    #[ApiEndpoint(
        summary: 'Replace a chain\'s steps',
        description: 'Up to 10 ordered steps; a role step needs a role_name, a supervisor step takes none. Moves new requests only — in-flight ones keep their snapshot. Needs hrm.approval-chains.manage.',
        request: 'MobileChainUpdate',
        response: 'MobileEnvelope',
        status: '200',
        path: [['name' => 'key', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'enum' => ['leave', 'overtime']]]],
    )]
    public function update(Request $request, string $key): JsonResponse
    {
        if (! $request->user()->can(ApprovalChainSeeder::PERM_MANAGE)) {
            return ApiResponse::error('You may not edit approval chains.', 403);
        }

        $chain = $this->chains->findByKey($key);

        if ($chain === null) {
            return ApiResponse::error('Approval chain not found.', 404);
        }

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:150'],
            'is_active' => ['nullable', 'boolean'],
            'steps' => ['required', 'array', 'min:1', 'max:10'],
            'steps.*.stage_key' => ['required', Rule::in($this->chains->stageKeys())],
            'steps.*.stage_label' => ['required', 'string', 'max:100'],
            'steps.*.resolver' => ['required', Rule::in($this->chains->resolvers())],
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

        $chain = $this->chains->replaceSteps(
            $chain,
            $data['steps'],
            array_key_exists('name', $data) ? $data['name'] : null,
            array_key_exists('is_active', $data) ? $data['is_active'] : null,
        );

        return ApiResponse::ok(
            $chain,
            'Chain updated. New requests follow it; in-flight requests keep their snapshot.'
        );
    }
}
