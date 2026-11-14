<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\HRM\Models\Attendance\AttendanceDaily;
use Modules\HRM\Models\AttendanceRegularization;
use Modules\HRM\Services\RegularizationService;

/**
 * Attendance regularization — a dispute about a day, filed, reviewed, and the daily record patched.
 *
 * Three things used to make this screen look dead rather than merely strict, and they are worth naming
 * because none of them is a bug in the *logic*:
 *
 *  - the validator carried its own list of types (four) which disagreed with both the picker (eight) and
 *    the `enum` in the schema, so most selections failed validation, `Short Hours` passed validation and
 *    then died in the database;
 *  - the page never rendered validation errors, so a failed submit produced no message at all — the
 *    classic "nothing happens";
 *  - the service throws `DomainException` for a duplicate or a re-review, and the controller let it out,
 *    which is a 500 page for what is really "you already filed this".
 *
 * So the list now comes from `AttendanceRegularization::TYPES`, the page shows its errors, and every
 * domain refusal becomes a readable flash.
 */
class RegularizationController extends Controller
{
    public function __construct(private readonly RegularizationService $svc) {}

    public function index(Request $r): Response
    {
        $q = AttendanceRegularization::with(['employee:id,employee_id,full_name', 'reviewer:id,name'])->latest();

        if ($r->input('status')) {
            $q->where('status', $r->input('status'));
        }

        if ($r->input('employee_id')) {
            $q->where('employee_id', $r->input('employee_id'));
        }

        return Inertia::render('HRM::Regularizations/Index', [
            'regularizations' => $q->paginate(20)->withQueryString(),
            'filters' => $r->only(['status', 'employee_id']),
            // The picker is built from the same list the server validates against, so the two cannot
            // disagree again. `reviewable` is what decides whether Approve/Reject render at all — a button
            // that is only there to 403 is worse than no button.
            'types' => AttendanceRegularization::TYPES,
            'canManage' => $r->user()->can('hrm.regularizations.manage'),
            'canApprove' => $r->user()->can('hrm.regularizations.approve'),
        ]);
    }

    public function store(Request $r): RedirectResponse
    {
        $data = $r->validate([
            'employee_id' => ['required', 'exists:employees,employee_id'],
            'duty_date' => ['required', 'date', 'before_or_equal:today'],
            'regularization_type' => ['required', Rule::in(AttendanceRegularization::TYPES)],
            'assigned_shift_id' => ['nullable', 'exists:roster_shifts,id'],
            'requested_in_time' => ['nullable', 'date_format:H:i'],
            'requested_out_time' => ['nullable', 'date_format:H:i'],
            // 10 characters, because an empty "oops" in an audit trail that a payroll cut depends on is not
            // a reason. The page tells the user that rather than swallowing it.
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        try {
            $reg = $this->svc->request($data);
        } catch (\DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', "Regularization {$reg->regularization_no} submitted — pending review.");
    }

    public function review(Request $r, AttendanceRegularization $regularization): RedirectResponse
    {
        $data = $r->validate([
            'decision' => ['required', Rule::in(AttendanceRegularization::DECISIONS)],
            'review_remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $reg = $this->svc->review($regularization, $data['decision'], $data['review_remarks'] ?? null);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $this->outcomeFor($reg, $data['decision']));
    }

    public function cancel(AttendanceRegularization $regularization): RedirectResponse
    {
        try {
            $this->svc->cancel($regularization);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Regularization cancelled.');
    }

    /**
     * What actually happened to the day, read back rather than asserted.
     *
     * The flash used to claim "Attendance daily patched and recalculated to Present" for every approval.
     * Two of the three cases were false — a `Missing In` with no out punch is still not a Present day, and a
     * rejected request touches nothing — and a message that lies about a payroll-relevant record is how a
     * reviewer ends up trusting the wrong thing.
     */
    private function outcomeFor(AttendanceRegularization $reg, string $decision): string
    {
        $label = "Regularization {$reg->regularization_no} {$decision}.";

        if ($decision !== 'Approved') {
            return $label.' The attendance record was left as it was.';
        }

        $daily = AttendanceDaily::query()
            ->where('employee_id', $reg->employee_id)
            ->whereDate('duty_date', $reg->duty_date)
            ->first();

        if ($daily === null) {
            // The service creates the daily when none existed, so this only happens if the write did not
            // land on the row this query looks for — say it plainly instead of claiming success.
            return $label.' No daily record for '.($reg->duty_date?->toDateString() ?? 'that date').' was found afterwards.';
        }

        $hours = $daily->primary_worked_hours === null
            ? ''
            : ', '.number_format((float) $daily->primary_worked_hours, 2).'h worked';

        return $label.' '.$reg->duty_date?->toDateString().' now reads '.$daily->status.$hours.' (flagged as regularized).';
    }
}
