<?php

namespace Modules\CORE\Services;

use Illuminate\Support\Facades\DB;
use Modules\CORE\Models\ApprovalPolicy;
use Modules\CORE\Models\ApprovalRequest;
use Modules\CORE\Models\User;

/**
 * The generic approval matrix + segregation-of-duties gate.
 *
 * Design: the gate is checked at the moment of the money action (confirm / post /
 * pay), not by mutating the document. `assertAllowed()` either passes silently
 * (no policy, or an approved request exists) or throws a DomainException naming
 * the approval that is missing — and, on first refusal, opens a pending request
 * and notifies the approvers so the workflow starts itself.
 *
 * SoD: a user can never approve a request they raised. The approver must hold the
 * policy's permission. Both are enforced here, server-side, not in the UI.
 */
class ApprovalService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * The active policy that governs this document, or null if none applies.
     * Highest priority wins; ties break on the higher amount threshold.
     */
    public function policyFor(string $documentType, float $amount): ?ApprovalPolicy
    {
        return ApprovalPolicy::query()
            ->where('document_type', $documentType)
            ->where('is_active', true)
            ->where('min_amount', '<=', $amount)
            ->orderByDesc('priority')
            ->orderByDesc('min_amount')
            ->first();
    }

    /**
     * Gate a money action. Call inside the document's DB::transaction, before any
     * stock/ledger write.
     *
     * @throws \DomainException when approval is required and not yet granted.
     */
    public function assertAllowed(string $documentType, int $documentId, float $amount, string $documentNo = ''): void
    {
        $policy = $this->policyFor($documentType, $amount);

        if ($policy === null) {
            return; // no policy governs this document — free to proceed
        }

        $approved = ApprovalRequest::query()
            ->where('document_type', $documentType)
            ->where('document_id', $documentId)
            ->where('status', 'approved')
            ->where('amount', $amount)
            ->exists();

        if ($approved) {
            return;
        }

        // Reuse an existing pending request rather than spamming a new one per click.
        $pending = ApprovalRequest::query()
            ->where('document_type', $documentType)
            ->where('document_id', $documentId)
            ->where('status', 'pending')
            ->where('amount', $amount)
            ->first();

        $userId = (int) (auth()->id() ?? 0);

        if ($pending === null) {
            $pending = DB::transaction(function () use ($policy, $documentType, $documentId, $documentNo, $amount, $userId): ApprovalRequest {
                $request = ApprovalRequest::create([
                    'policy_id' => $policy->id,
                    'document_type' => $documentType,
                    'document_id' => $documentId,
                    'document_no' => $documentNo,
                    'amount' => $amount,
                    'requested_by' => $userId ?: null,
                    'status' => 'pending',
                ]);

                $this->notifications->notifyPermission(
                    $policy->approver_permission,
                    "Approval needed: {$documentNo}",
                    sprintf('%s for %s BDT requires your approval (%s).', $documentType, number_format($amount, 2), $policy->name),
                    '/core/approvals',
                    'warning',
                    ['request_id' => $request->id],
                );

                return $request;
            });
        }

        throw new \DomainException(sprintf(
            '%s for %s BDT requires approval under "%s". A request has been sent to users holding "%s". '
            .'Once approved, repeat this action to complete it.',
            $documentNo ?: $documentType,
            number_format($amount, 2),
            $policy->name,
            $policy->approver_permission,
        ));
    }

    /**
     * Approve a pending request. Enforces SoD + the approver permission.
     */
    public function approve(ApprovalRequest $request, User $approver, ?string $note = null): ApprovalRequest
    {
        if (! $request->isPending()) {
            throw new \DomainException('Only a pending request can be approved.');
        }

        $policy = $request->policy;

        if ($policy->require_segregation && (int) $request->requested_by === (int) $approver->id) {
            throw new \DomainException('Segregation of duties: you cannot approve a request you raised.');
        }

        if (! $approver->can($policy->approver_permission)) {
            throw new \DomainException("Approving this request requires the \"{$policy->approver_permission}\" permission.");
        }

        $request->update([
            'status' => 'approved',
            'decided_by' => $approver->id,
            'decided_at' => now(),
            'decision_note' => $note,
        ]);

        $this->notifications->notifyUsers(
            [(int) $request->requested_by],
            "Approved: {$request->document_no}",
            'Your request was approved. You can now complete the action.',
            '/core/approvals',
            'success',
            ['request_id' => $request->id],
        );

        return $request->fresh();
    }

    /**
     * Reject a pending request. The document is untouched.
     */
    public function reject(ApprovalRequest $request, User $approver, string $note): ApprovalRequest
    {
        if (! $request->isPending()) {
            throw new \DomainException('Only a pending request can be rejected.');
        }

        $policy = $request->policy;

        if (! $approver->can($policy->approver_permission)) {
            throw new \DomainException("Rejecting this request requires the \"{$policy->approver_permission}\" permission.");
        }

        if (trim($note) === '') {
            throw new \DomainException('A rejection must carry a reason.');
        }

        $request->update([
            'status' => 'rejected',
            'decided_by' => $approver->id,
            'decided_at' => now(),
            'decision_note' => $note,
        ]);

        $this->notifications->notifyUsers(
            [(int) $request->requested_by],
            "Rejected: {$request->document_no}",
            $note,
            '/core/approvals',
            'danger',
            ['request_id' => $request->id],
        );

        return $request->fresh();
    }
}
