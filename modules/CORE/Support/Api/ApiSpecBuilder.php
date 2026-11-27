<?php

namespace Modules\CORE\Support\Api;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use ReflectionMethod;
use RuntimeException;
use Spatie\Permission\Middleware\PermissionMiddleware;

/**
 * F13 — build the OpenAPI 3.1 document from the routes that are actually registered.
 *
 * The spec is generated, never hand-maintained, for one reason: a hand-written
 * `docs/openapi.yaml` is a snapshot of what someone remembered. Every description in
 * `openapi: [...]` on a route is the same object the docs page renders and the same one
 * the CI check diffs against `docs/openapi.json`, so adding an endpoint without
 * documenting it is a failed build, not a silent gap. `describe()` throws rather than
 * skipping an undocumented route — a skipped route is how a spec goes stale while still
 * looking green.
 */
final class ApiSpecBuilder
{
    public const SPEC_PATH = 'docs/openapi.json';

    public const VERSION = '1.0.0';

    /** Shared, referenced once — an error body that varies per endpoint documents nothing. */
    private const ERROR_RESPONSES = [
        '401' => ['description' => 'No token, a revoked one, or an expired one. A request without `Accept: application/json` still gets this JSON body, never a redirect to the login form.'],
        '403' => ['description' => 'The owning user lacks the permission, or this token lacks the ability. `X-request-id` is not returned; the message names which of the two it was.'],
        '404' => ['description' => 'No row matches, including for a row you may not see — reading 404 for someone else\'s document is itself a leak.'],
        '422' => ['description' => 'Validation failed. Field keys match the request body exactly, so a client can map them back.'],
        '429' => ['description' => 'Rate limit exceeded: 60 requests/minute per user across all their tokens, 5/minute for the token exchange.'],
    ];

    private const SCHEMAS = [
        'MoneyString' => [
            'type' => 'string',
            'pattern' => '^-?\d+\.\d{2}$',
            'description' => 'An exact amount as a two-decimal string, never a float. Every model here casts decimals `decimal:2`, and JSON floats are how an integration ends up one paisa short and blaming the ledger.',
        ],
        'PageMeta' => [
            'type' => 'object',
            'description' => 'The same pagination envelope the on-screen tables consume.',
            'properties' => [
                'current_page' => ['type' => 'integer'], 'last_page' => ['type' => 'integer'],
                'per_page' => ['type' => 'integer'], 'total' => ['type' => 'integer'],
                'from' => ['type' => ['integer', 'null']], 'to' => ['type' => ['integer', 'null']],
            ],
            'required' => ['current_page', 'last_page', 'per_page', 'total'],
        ],
        'Error' => [
            'type' => 'object',
            'properties' => ['message' => ['type' => 'string'], 'errors' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]]],
            'required' => ['message'],
        ],
        'InvoiceSummary' => [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'], 'invoice_no' => ['type' => 'string'],
                'customer' => ['type' => ['string', 'null']],
                'invoice_date' => ['type' => 'string', 'format' => 'date'], 'due_date' => ['type' => ['string', 'null'], 'format' => 'date'],
                'currency' => ['type' => 'string'],
                'subtotal' => ['$ref' => '#/components/schemas/MoneyString'],
                'discount_amount' => ['$ref' => '#/components/schemas/MoneyString'],
                'vat_amount' => ['$ref' => '#/components/schemas/MoneyString'],
                'total_amount' => ['$ref' => '#/components/schemas/MoneyString'],
                'paid_amount' => ['$ref' => '#/components/schemas/MoneyString'],
                'balance' => ['$ref' => '#/components/schemas/MoneyString'],
                'status' => ['type' => 'string'], 'payment_status' => ['type' => 'string'],
                'journal_id' => ['type' => ['integer', 'null'], 'description' => 'The posted journal, when there is one — the join back to the ledger.'],
                'items_count' => ['type' => ['integer', 'null']],
            ],
        ],
        'InvoiceLine' => [
            'type' => 'object',
            'properties' => [
                'product_name' => ['type' => 'string'], 'sku' => ['type' => ['string', 'null']],
                'qty' => ['$ref' => '#/components/schemas/MoneyString'],
                'unit_price' => ['$ref' => '#/components/schemas/MoneyString'],
                'discount_amount' => ['$ref' => '#/components/schemas/MoneyString'],
                'vat_rate' => ['$ref' => '#/components/schemas/MoneyString'],
                'vat_amount' => ['$ref' => '#/components/schemas/MoneyString'],
                'line_total' => ['$ref' => '#/components/schemas/MoneyString'],
            ],
        ],
        'InvoicePage' => [
            'type' => 'object',
            'properties' => [
                'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/InvoiceSummary']],
                'meta' => ['$ref' => '#/components/schemas/PageMeta'],
            ],
        ],
        'InvoiceDetail' => [
            'allOf' => [
                ['$ref' => '#/components/schemas/InvoiceSummary'],
                ['type' => 'object', 'properties' => [
                    'notes' => ['type' => ['string', 'null']],
                    'posted_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'warehouse' => ['type' => ['string', 'null']],
                    'customer' => ['type' => ['object', 'nullable' => true]],
                    'lines' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/InvoiceLine']],
                ]],
            ],
        ],
        'TrialBalanceRow' => [
            'type' => 'object',
            'properties' => [
                'account_id' => ['type' => 'integer'], 'code' => ['type' => 'string'], 'name' => ['type' => 'string'],
                'account_type' => ['type' => 'string'],
                'nature' => ['type' => 'string', 'enum' => ['debit', 'credit'], 'description' => 'Which side the account normally carries.'],
                'total_debit' => ['$ref' => '#/components/schemas/MoneyString'],
                'total_credit' => ['$ref' => '#/components/schemas/MoneyString'],
                'balance' => ['$ref' => '#/components/schemas/MoneyString'],
                'balance_side' => ['type' => 'string', 'description' => 'Read `balance` together with this: the sign is per-account, not globally credit-positive.'],
            ],
        ],
        'TrialBalance' => [
            'type' => 'object',
            'properties' => [
                'as_of' => ['type' => 'string', 'format' => 'date'],
                'rows' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/TrialBalanceRow']],
                'totals' => ['type' => 'object', 'properties' => [
                    'debit' => ['$ref' => '#/components/schemas/MoneyString'],
                    'credit' => ['$ref' => '#/components/schemas/MoneyString'],
                ]],
            ],
        ],
        'BudgetSummary' => [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'code' => ['type' => 'string'],
                'department' => ['type' => ['string', 'null']],
                'status' => ['type' => 'string', 'enum' => ['draft', 'approved', 'locked']],
                'line_count' => ['type' => 'integer'], 'fiscal_year' => ['type' => ['string', 'null']],
            ],
        ],
        'BudgetPage' => [
            'type' => 'object',
            'properties' => ['data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/BudgetSummary']]],
        ],
        'BudgetVarianceRow' => [
            'type' => 'object',
            'properties' => [
                'account_id' => ['type' => 'integer'], 'code' => ['type' => 'string'], 'name' => ['type' => 'string'],
                'nature' => ['type' => 'string', 'enum' => ['debit', 'credit']],
                'budget' => ['$ref' => '#/components/schemas/MoneyString'],
                'actual' => ['$ref' => '#/components/schemas/MoneyString'],
                'variance' => ['$ref' => '#/components/schemas/MoneyString'],
                'pct' => ['type' => 'number', 'description' => 'Utilisation, whole percent.'],
                'favourable' => ['type' => 'boolean', 'description' => 'Nature-dependent: a debit account is on-plan when actual ≤ budget, a credit account when actual ≥ budget. Do not infer this from the sign of `variance`.'],
            ],
        ],
        'BudgetVarianceReport' => [
            'type' => 'object',
            'properties' => [
                'budget' => ['type' => 'object'],
                'periods' => ['type' => 'array', 'items' => ['type' => 'string']],
                'rows' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/BudgetVarianceRow']],
                'unfavorable' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/BudgetVarianceRow'], 'description' => 'Worst first.'],
                'totals' => ['type' => 'object', 'properties' => [
                    'budget' => ['$ref' => '#/components/schemas/MoneyString'],
                    'actual' => ['$ref' => '#/components/schemas/MoneyString'],
                    'variance' => ['$ref' => '#/components/schemas/MoneyString'],
                    'pct' => ['type' => 'number'],
                    'overrun_count' => ['type' => 'integer'],
                ]],
            ],
        ],
        'JournalDraft' => [
            'type' => 'object',
            'required' => ['journal_date', 'type', 'lines'],
            'properties' => [
                'journal_date' => ['type' => 'string', 'format' => 'date'],
                'type' => ['type' => 'string', 'enum' => ['JV', 'PV', 'RV', 'CV', 'BV', 'SV']],
                'reference_no' => ['type' => 'string', 'maxLength' => 100],
                'narration' => ['type' => 'string', 'maxLength' => 2000],
                'lines' => [
                    'type' => 'array', 'minItems' => 2, 'maxItems' => 50,
                    'description' => 'Must balance to the paisa — the check runs through `JournalBalance`, the same object the screen and `post()` use, so a journal accepted here is never rejected later for a reason the API did not state.',
                    'items' => ['type' => 'object', 'required' => ['account_id'], 'properties' => [
                        'account_id' => ['type' => 'integer', 'description' => 'Must be a posting account: a group header is refused.'],
                        'debit' => ['$ref' => '#/components/schemas/MoneyString'],
                        'credit' => ['$ref' => '#/components/schemas/MoneyString'],
                        'description' => ['type' => 'string', 'maxLength' => 500],
                        'cost_center' => ['type' => 'string', 'maxLength' => 100],
                    ]],
                ],
            ],
        ],
        'JournalCreated' => [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'], 'reference_no' => ['type' => ['string', 'null']],
                'journal_date' => ['type' => 'string', 'format' => 'date'],
                'status' => ['type' => 'string', 'enum' => ['draft']],
                'line_count' => ['type' => 'integer'],
                'total' => ['$ref' => '#/components/schemas/MoneyString'],
                'next_step' => ['type' => 'string'],
            ],
        ],
        'JournalDetail' => [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'], 'reference_no' => ['type' => ['string', 'null']],
                'journal_date' => ['type' => 'string', 'format' => 'date'],
                'type' => ['type' => 'string'], 'status' => ['type' => 'string'],
                'narration' => ['type' => ['string', 'null']],
                'lines' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'account_id' => ['type' => 'integer'], 'account' => ['type' => 'string'],
                    'debit' => ['$ref' => '#/components/schemas/MoneyString'],
                    'credit' => ['$ref' => '#/components/schemas/MoneyString'],
                    'description' => ['type' => ['string', 'null']],
                ]]],
            ],
        ],
        'TokenIssued' => [
            'type' => 'object',
            'properties' => [
                'token' => ['type' => 'string', 'description' => 'Format `id|secret`, prefixed `erp_` so a leak is recognisable. Returned once.'],
                'token_id' => ['type' => 'integer'],
                'abilities' => ['type' => 'array', 'items' => ['type' => 'string']],
                'expires_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                'warning' => ['type' => 'string'],
            ],
        ],
        'CallerIdentity' => [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'email' => ['type' => 'string'],
                'roles' => ['type' => 'array', 'items' => ['type' => 'string']],
                'permissions' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'What the owning user may do. A token may do less than this, never more.'],
                'token' => ['type' => 'object', 'properties' => [
                    'id' => ['type' => ['integer', 'null']], 'name' => ['type' => 'string'],
                    'abilities' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'last_used_at' => ['type' => ['string', 'null']],
                ]],
            ],
        ],
        // ── Mobile surface (Phases 0–4): one envelope, per-form request bodies. ──
        'MobileEnvelope' => [
            'type' => 'object',
            'description' => 'Every mobile endpoint answers this shape. `data` is the payload (object, list or null per endpoint); `meta` appears on paged endpoints and a few counted ones; `errors` appears on 422 with field keys matching the request body.',
            'properties' => [
                'success' => ['type' => 'boolean'],
                'message' => ['type' => 'string'],
                'data' => ['description' => 'Payload, shape per endpoint.'],
                'meta' => ['type' => 'object', 'description' => 'Page cursors (current_page, per_page, total, last_page) or small counters.'],
                'errors' => ['type' => 'object', 'description' => 'Validation failures; occasionally carries a machine `code` (NOT_YOUR_STAGE, EMPLOYEE_NOT_LINKED, …).'],
            ],
            'required' => ['success', 'message'],
        ],
        'MobilePage' => [
            'type' => 'object',
            'description' => 'A paged mobile list: the envelope with `data` always a list and `meta` always the page cursors.',
            'properties' => [
                'success' => ['type' => 'boolean'],
                'message' => ['type' => 'string'],
                'data' => ['type' => 'array', 'items' => ['type' => 'object']],
                'meta' => ['type' => 'object', 'properties' => [
                    'current_page' => ['type' => 'integer'], 'per_page' => ['type' => 'integer'],
                    'total' => ['type' => 'integer'], 'last_page' => ['type' => 'integer'],
                ], 'required' => ['current_page', 'per_page', 'total', 'last_page']],
            ],
            'required' => ['success', 'message', 'data', 'meta'],
        ],
        'MobileLogin' => [
            'type' => 'object',
            'required' => ['email', 'password'],
            'properties' => [
                'email' => ['type' => 'string', 'description' => 'Email address or employee code (e.g. EMP-0042).'],
                'password' => ['type' => 'string'],
                'device_name' => ['type' => 'string', 'maxLength' => 100, 'description' => 'Labels the token; defaults to `mobile`.'],
            ],
        ],
        'MobilePasswordChange' => [
            'type' => 'object',
            'required' => ['current_password', 'password', 'password_confirmation'],
            'properties' => [
                'current_password' => ['type' => 'string'],
                'password' => ['type' => 'string', 'minLength' => 8],
                'password_confirmation' => ['type' => 'string'],
            ],
        ],
        'MobileLeaveApply' => [
            'type' => 'object',
            'required' => ['leave_type_id', 'from_date', 'to_date'],
            'properties' => [
                'leave_type_id' => ['type' => 'integer'],
                'from_date' => ['type' => 'string', 'format' => 'date'],
                'to_date' => ['type' => 'string', 'format' => 'date'],
                'is_half_day' => ['type' => 'boolean'],
                'reason' => ['type' => 'string', 'maxLength' => 1000],
            ],
        ],
        'MobileOvertimeClaim' => [
            'type' => 'object',
            'required' => ['overtime_date', 'overtime_type'],
            'properties' => [
                'overtime_date' => ['type' => 'string', 'format' => 'date', 'description' => 'Today or earlier.'],
                'overtime_type' => ['type' => 'string', 'enum' => ['Overtime', 'Extra Duty', 'Holiday Duty']],
                'shift_id' => ['type' => 'integer'],
                'start_time' => ['type' => 'string', 'description' => 'HH:MM, 24-hour. Give this + end_time, or hours.'],
                'end_time' => ['type' => 'string', 'description' => 'HH:MM, 24-hour.'],
                'hours' => ['type' => 'number', 'minimum' => 0.25, 'maximum' => 24],
                'reason' => ['type' => 'string', 'maxLength' => 1000],
            ],
        ],
        'MobileRegularizationApply' => [
            'type' => 'object',
            'required' => ['duty_date', 'regularization_type', 'reason'],
            'properties' => [
                'duty_date' => ['type' => 'string', 'format' => 'date', 'description' => 'Today or earlier.'],
                'regularization_type' => ['type' => 'string', 'enum' => ['Missing In', 'Missing Out', 'Wrong Shift', 'Late Correction', 'Early Out Correction', 'On Duty', 'Work From Home', 'Remote Punch']],
                'assigned_shift_id' => ['type' => 'integer'],
                'requested_in_time' => ['type' => 'string', 'description' => 'HH:MM, 24-hour.'],
                'requested_out_time' => ['type' => 'string', 'description' => 'HH:MM, 24-hour.'],
                'reason' => ['type' => 'string', 'maxLength' => 2000],
            ],
        ],
        'MobileInformedApply' => [
            'type' => 'object',
            'required' => ['informed_date', 'informed_type'],
            'properties' => [
                'informed_date' => ['type' => 'string', 'format' => 'date'],
                'informed_type' => ['type' => 'string', 'enum' => ['Late Coming', 'Early Out', 'Absent Informed', 'Field Work', 'On Duty', 'Other']],
                'expected_time' => ['type' => 'string', 'description' => 'HH:MM, 24-hour.'],
                'reason' => ['type' => 'string', 'maxLength' => 2000],
            ],
        ],
        'MobileResignationApply' => [
            'type' => 'object',
            'required' => ['last_working_date', 'reason'],
            'properties' => [
                'last_working_date' => ['type' => 'string', 'format' => 'date', 'description' => 'Must be after today.'],
                'reason' => ['type' => 'string', 'maxLength' => 2000],
            ],
        ],
        'MobileCertificateApply' => [
            'type' => 'object',
            'required' => ['certificate_type', 'purpose'],
            'properties' => [
                'certificate_type' => ['type' => 'string', 'enum' => ['Salary Certificate', 'NOC Certificate', 'Experience Certificate']],
                'purpose' => ['type' => 'string', 'minLength' => 5, 'maxLength' => 1000, 'description' => 'Printed on the certificate.'],
            ],
        ],
        'MobileLoanApply' => [
            'type' => 'object',
            'required' => ['principal_amount', 'installment_count', 'first_due_date', 'purpose'],
            'properties' => [
                'principal_amount' => ['type' => 'number', 'exclusiveMinimum' => 0],
                'installment_count' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 120],
                'first_due_date' => ['type' => 'string', 'format' => 'date'],
                'purpose' => ['type' => 'string', 'minLength' => 5, 'maxLength' => 1000],
            ],
        ],
        'MobileDeviceRegister' => [
            'type' => 'object',
            'required' => ['token'],
            'properties' => [
                'token' => ['type' => 'string', 'minLength' => 20, 'maxLength' => 2048, 'description' => 'The FCM registration token. Re-registering transfers ownership.'],
                'platform' => ['type' => 'string', 'enum' => ['android', 'ios', 'web'], 'description' => 'Defaults to android.'],
                'device_name' => ['type' => 'string', 'maxLength' => 120],
            ],
        ],
        'MobileApprove' => [
            'type' => 'object',
            'properties' => [
                'comments' => ['type' => 'string', 'maxLength' => 2000],
            ],
        ],
        'MobileLeaveReject' => [
            'type' => 'object',
            'required' => ['comments'],
            'properties' => [
                'comments' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 2000, 'description' => 'Travels to the applicant as the reason.'],
            ],
        ],
        'MobileOvertimeApprove' => [
            'type' => 'object',
            'properties' => [
                'adjusted_hours' => ['type' => 'number', 'minimum' => 0, 'maximum' => 24],
                'comments' => ['type' => 'string', 'maxLength' => 2000],
            ],
        ],
        'MobileOvertimeReject' => [
            'type' => 'object',
            'required' => ['reason'],
            'properties' => [
                'reason' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 2000, 'description' => 'Travels to the claimant.'],
            ],
        ],
        'MobileChainUpdate' => [
            'type' => 'object',
            'required' => ['steps'],
            'properties' => [
                'name' => ['type' => 'string', 'maxLength' => 150],
                'is_active' => ['type' => 'boolean'],
                'steps' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 10, 'description' => 'Ordered stages; position is the array order.', 'items' => ['type' => 'object', 'required' => ['stage_key', 'stage_label', 'resolver'], 'properties' => [
                    'stage_key' => ['type' => 'string', 'enum' => ['supervisor', 'hr', 'dgm', 'gm', 'ceo']],
                    'stage_label' => ['type' => 'string', 'maxLength' => 100, 'description' => 'The desk name the app shows.'],
                    'resolver' => ['type' => 'string', 'enum' => ['supervisor', 'role'], 'description' => '`supervisor` resolves from the applicant\'s mapping; `role` from role_name.'],
                    'role_name' => ['type' => 'string', 'description' => 'Required for role steps (must exist); forbidden on supervisor steps.'],
                ]]],
            ],
        ],
        'MobileTeamLeaveApply' => [
            'type' => 'object',
            'required' => ['employee_id', 'leave_type_id', 'from_date', 'to_date'],
            'properties' => [
                'employee_id' => ['type' => 'string', 'description' => 'Report code, inside the caller\'s scope.'],
                'leave_type_id' => ['type' => 'integer'],
                'from_date' => ['type' => 'string', 'format' => 'date'],
                'to_date' => ['type' => 'string', 'format' => 'date'],
                'is_half_day' => ['type' => 'boolean'],
                'reason' => ['type' => 'string', 'maxLength' => 1000],
            ],
        ],
        'MobileTeamOvertimeClaim' => [
            'type' => 'object',
            'required' => ['employee_id', 'overtime_date', 'overtime_type'],
            'properties' => [
                'employee_id' => ['type' => 'string', 'description' => 'Report code, inside the caller\'s scope.'],
                'overtime_date' => ['type' => 'string', 'format' => 'date', 'description' => 'Today or earlier.'],
                'overtime_type' => ['type' => 'string', 'enum' => ['Overtime', 'Extra Duty', 'Holiday Duty']],
                'shift_id' => ['type' => 'integer'],
                'start_time' => ['type' => 'string', 'description' => 'HH:MM, 24-hour. Give this + end_time, or hours.'],
                'end_time' => ['type' => 'string', 'description' => 'HH:MM, 24-hour.'],
                'hours' => ['type' => 'number', 'minimum' => 0.25, 'maximum' => 24],
                'reason' => ['type' => 'string', 'maxLength' => 1000],
            ],
        ],
        'MobileRosterBulk' => [
            'type' => 'object',
            'required' => ['employee_ids', 'shift_id', 'from_date', 'to_date'],
            'properties' => [
                'employee_ids' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 500, 'items' => ['type' => 'string'], 'description' => 'Employee codes, each scope-checked.'],
                'shift_id' => ['type' => 'integer'],
                'from_date' => ['type' => 'string', 'format' => 'date'],
                'to_date' => ['type' => 'string', 'format' => 'date', 'description' => 'Range may not exceed 60 days.'],
            ],
        ],
        'MobileRosterDuty' => [
            'type' => 'object',
            'required' => ['employee_id', 'roster_date'],
            'properties' => [
                'employee_id' => ['type' => 'string'],
                'roster_date' => ['type' => 'string', 'format' => 'date'],
                'shift_id' => ['type' => 'integer'],
            ],
        ],
        'MobileTaskAssign' => [
            'type' => 'object',
            'required' => ['employee_id', 'title'],
            'properties' => [
                'employee_id' => ['type' => 'string', 'description' => 'Assignee code, inside the caller\'s scope.'],
                'title' => ['type' => 'string', 'maxLength' => 200],
                'description' => ['type' => 'string', 'maxLength' => 5000],
                'priority' => ['type' => 'string', 'enum' => ['Low', 'Medium', 'High', 'Urgent'], 'description' => 'Defaults to Medium.'],
                'due_date' => ['type' => 'string', 'format' => 'date'],
            ],
        ],
        'MobileTaskProgress' => [
            'type' => 'object',
            'required' => ['status'],
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['Open', 'In Progress', 'Done'], 'description' => 'Assignee lanes only; Verified/Closed are the assigner\'s.'],
                'remarks' => ['type' => 'string', 'maxLength' => 2000],
            ],
        ],
        'MobileNoticePost' => [
            'type' => 'object',
            'required' => ['title', 'body', 'audience_type'],
            'properties' => [
                'title' => ['type' => 'string', 'maxLength' => 200],
                'body' => ['type' => 'string', 'maxLength' => 10000],
                'audience_type' => ['type' => 'string', 'enum' => ['all', 'department', 'employees']],
                'audience_ids' => ['type' => 'array', 'maxItems' => 1000, 'description' => 'Department ids or employee codes; required unless audience is all.'],
                'expire_at' => ['type' => 'string', 'format' => 'date-time', 'description' => 'Must be in the future.'],
            ],
        ],
        'MobileNoticeUpdate' => [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'maxLength' => 200],
                'body' => ['type' => 'string', 'maxLength' => 10000],
                'expire_at' => ['type' => 'string', 'format' => 'date-time'],
                'is_active' => ['type' => 'boolean'],
            ],
        ],
    ];

    public function __construct(private readonly Router $router) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $paths = [];

        foreach ($this->router->getRoutes() as $route) {
            if (! $this->isVersioned($route)) {
                continue;
            }

            $path = '/'.ltrim($route->uri(), '/');
            $documented = $this->describe($route, $path);

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $paths[$path][strtolower($method)] = $documented;
            }
        }

        ksort($paths);

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'ERP Pilot — v1 API',
                'version' => self::VERSION,
                'summary' => 'Ledger reads and draft journals for integrators, plus the full mobile app surface',
                'description' => <<<'TXT'
Authentication is a Sanctum bearer token (`Authorization: Bearer erp_…`), obtained from
`POST /api/v1/auth/token` with credentials or from **Settings → API Tokens** in the app.

Four rules explain almost every design decision below — three for the integrator surface, one for the mobile surface.

**Abilities narrow, roles authorise.** A token can only ever reach what its owning user
could reach, and only the subset its abilities name. A stolen accountant's token mints no
invoices; a Super Admin's token with `sales:read` posts no journals.

**Money is a string.** Amounts arrive as two-decimal strings — `decimal:2` is the cast on
every model column in this application. Parse them with a decimal type, not a float.

**Reads are free, writes are drafts — on the integrator surface.** Its only mutating
endpoint creates an unposted draft journal, validated by the same form request the
screen uses. Posting a journal, voiding one, and touching the ledger require a person.

**The mobile surface is a second contract on the same v1.** Its endpoints answer one
envelope — `{ success, message, data, meta? }` on success, `{ success: false, message,
data: null, errors? }` on failure — and always resolve the employee from the bearer
token, never from request input: there is no `employee_id` parameter on self-service
endpoints, and a colleague's record answers 404. A login with no linked employee
answers 409 `EMPLOYEE_NOT_LINKED`. Supervisors see their direct reports only (403
outside scope); HR, DGM, GM, CEO and Admins see everyone. Push (FCM) mirrors the
in-app bell: every notification carries `ref_type` + `ref_id` so the app deep-links
the tap, whether it arrived as push or poll.

Pagination, search and filtering use the same query vocabulary as the tables in the
application itself — `search`, `sort`, `dir`, `page`, `per_page`, `filters[...]` — so one
behaviour cannot drift between what an integrator sees and what an accountant sees.
TXT,
            ],
            'servers' => [['url' => rtrim((string) config('app.url'), '/'), 'description' => 'This deployment']],
            'tags' => [
                ['name' => 'Authentication', 'description' => 'Tokens and identity (integrator exchange and mobile login)'],
                ['name' => 'Sales', 'description' => 'Invoices'],
                ['name' => 'Accounts', 'description' => 'Ledger, budgets, journals'],
                ['name' => 'Dashboard', 'description' => 'Employee home'],
                ['name' => 'Leave', 'description' => 'My leave, mobile'],
                ['name' => 'Overtime', 'description' => 'My overtime / extra / holiday duty, mobile'],
                ['name' => 'Attendance', 'description' => 'My punches, pairs, verdicts, mobile'],
                ['name' => 'Regularization', 'description' => 'My attendance corrections, mobile'],
                ['name' => 'Informed', 'description' => 'My advance notices, mobile'],
                ['name' => 'Resignation', 'description' => 'My resignation, mobile'],
                ['name' => 'Certificates', 'description' => 'My certificate requests, mobile'],
                ['name' => 'Payslips', 'description' => 'My payslips, mobile'],
                ['name' => 'Loans', 'description' => 'My payroll loans, mobile'],
                ['name' => 'Provident Fund', 'description' => 'My PF account, mobile'],
                ['name' => 'Notifications', 'description' => 'The in-app bell, mobile'],
                ['name' => 'Devices', 'description' => 'Push device registry, mobile'],
                ['name' => 'Approvals', 'description' => 'Approval desks, mobile'],
                ['name' => 'Approval Chains', 'description' => 'Chain administration, mobile'],
                ['name' => 'Team', 'description' => 'Reports and on-behalf work, mobile'],
                ['name' => 'Roster', 'description' => 'Roster writes, mobile'],
                ['name' => 'Tasks', 'description' => 'Work assignment, mobile'],
                ['name' => 'Notices', 'description' => 'Notice board, mobile'],
                ['name' => 'Organisation', 'description' => 'Directory, mobile'],
                ['name' => 'CEO', 'description' => 'CEO cockpit, mobile'],
            ],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'opaque',
                        'description' => 'A Sanctum personal access token. Opaque by design: it is a random secret and a lookup, not a self-describing credential, so revoking it is immediate.'],
                ],
                'schemas' => self::SCHEMAS + ['Error' => self::SCHEMAS['Error']],
                'responses' => array_map(
                    fn (array $r) => ['description' => $r['description'], 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
                    self::ERROR_RESPONSES
                ),
            ],
        ];
    }

    public function toJson(int $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE): string
    {
        return json_encode($this->build(), $flags | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * The documented half comes from the handler's `#[ApiEndpoint]`; the authorised half
     * is read back out of the route's own middleware.
     *
     * That split is deliberate. A spec that carried its own copy of "requires
     * accounts.journals.create" would still print confidently the day someone changes the
     * route, and an integrator following the docs would get a 403 they cannot explain.
     * Deriving them means the document can only describe what the router will enforce.
     *
     * @return array<string, mixed>
     */
    private function describe(Route $route, string $path): array
    {
        $endpoint = $this->endpointFor($route, $path);

        $middleware = $route->gatherMiddleware();
        $permission = $this->middlewareArgument($middleware, PermissionMiddleware::class);
        $ability = $this->middlewareArgument($middleware, 'ability');

        // 201 for the writes, 200 for the reads. Derived from the method rather than
        // declared, so a documented endpoint cannot claim 200 while returning 201 —
        // except where #[ApiEndpoint(status: ...)] says otherwise: action POSTs that
        // answer 200 with no resource created (approvals, cancels, progress moves).
        // The override is fenced to 200/201 so it can correct the derivation, never
        // invent a status.
        $success = $endpoint->status
            ?? (array_intersect($route->methods(), ['POST', 'PUT', 'PATCH']) !== [] ? '201' : '200');

        if (! in_array($success, ['200', '201'], true)) {
            throw new RuntimeException(
                "Route [$path] declares #[ApiEndpoint(status: ...)] '{$endpoint->status}' — only 200 and 201 are allowed."
            );
        }

        $operation = [
            'operationId' => $route->getName(),
            'summary' => $endpoint->summary,
            'tags' => [$this->tagFor($path)],
            'security' => $endpoint->public ? [] : [['bearerAuth' => []]],
            'x-required-permission' => $permission,
            'x-required-ability' => $ability,
            'x-middleware' => $this->middlewareNames($route),
            'parameters' => $this->parameters($path, $endpoint),
            'responses' => array_filter([
                $success => ['description' => 'OK', 'content' => ['application/json' => ['schema' => $endpoint->response !== null ? ['$ref' => '#/components/schemas/'.$endpoint->response] : ['type' => 'object']]]],
                '401' => ['$ref' => '#/components/responses/Unauthorized'],
                '403' => $endpoint->public ? null : ['$ref' => '#/components/responses/Forbidden'],
                '404' => str_contains($path, '{') ? ['$ref' => '#/components/responses/NotFound'] : null,
                '422' => ($endpoint->query !== [] || $endpoint->request !== null) ? ['$ref' => '#/components/responses/UnprocessableEntity'] : null,
                '429' => ['$ref' => '#/components/responses/TooManyRequests'],
            ]),
        ];

        if ($endpoint->description !== null) {
            $operation['description'] = $endpoint->description;
        }

        if ($endpoint->request !== null) {
            $operation['requestBody'] = [
                'required' => true,
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/'.$endpoint->request]]],
            ];
        }

        return $operation;
    }

    private function endpointFor(Route $route, string $path): ApiEndpoint
    {
        $uses = $route->getAction('uses');

        if (is_array($uses) && count($uses) === 2) {
            [$class, $method] = $uses;
        } elseif (is_string($uses)) {
            [$class, $method] = Str::parseCallback($uses, '__invoke');
        } else {
            throw new RuntimeException("Route [$path] is not handled by a controller method, so it cannot document itself.");
        }

        if (! class_exists($class) || ! method_exists($class, $method)) {
            throw new RuntimeException("Route [$path] points at $class::$method, which does not exist.");
        }

        $attributes = (new ReflectionMethod($class, $method))->getAttributes(ApiEndpoint::class);

        if ($attributes === []) {
            throw new RuntimeException(
                "Route [$path] ($class::$method) carries no #[ApiEndpoint]. Every v1 endpoint documents itself on its handler, or the spec does not build."
            );
        }

        return $attributes[0]->newInstance();
    }

    /** @param  list<string>  $middleware */
    private function middlewareArgument(array $middleware, string $needle): ?string
    {
        foreach ($middleware as $entry) {
            if (! is_string($entry) || ! str_starts_with($entry, $needle.':')) {
                continue;
            }

            return substr($entry, strlen($needle) + 1) ?: null;
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function parameters(string $path, ApiEndpoint $endpoint): array
    {
        $declared = collect($endpoint->path)->keyBy('name');
        $params = [];

        // Every `{placeholder}` must be declared, or the docs would be guessing its type.
        preg_match_all('/\{([a-zA-Z_]+)\??\}/', $path, $found);
        foreach ($found[1] as $name) {
            if (! $declared->has($name)) {
                throw new RuntimeException("Route [$path] has a {{$name}} parameter that its #[ApiEndpoint(path: ...)] does not describe.");
            }

            $params[] = $declared->get($name) + ['in' => 'path', 'required' => true];
        }

        foreach ($endpoint->query as $q) {
            $params[] = $q + ['required' => false];
        }

        return array_values($params);
    }

    private function tagFor(string $path): string
    {
        // Order matters: /team/leaves contains /leaves, so the team prefix wins first.
        return match (true) {
            str_contains($path, '/sales/') => 'Sales',
            str_contains($path, '/accounts/') => 'Accounts',
            str_contains($path, '/team/') => 'Team',
            str_contains($path, '/approvals/') => 'Approvals',
            str_contains($path, '/admin/chains') => 'Approval Chains',
            str_contains($path, '/auth/') => 'Authentication',
            str_contains($path, '/dashboard') => 'Dashboard',
            str_contains($path, '/leaves') => 'Leave',
            str_contains($path, '/overtimes') => 'Overtime',
            str_contains($path, '/attendance') => 'Attendance',
            str_contains($path, '/regularizations') => 'Regularization',
            str_contains($path, '/informed') => 'Informed',
            str_contains($path, '/resignations') => 'Resignation',
            str_contains($path, '/certificates') => 'Certificates',
            str_contains($path, '/payslips') => 'Payslips',
            str_contains($path, '/loans') => 'Loans',
            str_contains($path, '/pf/') => 'Provident Fund',
            str_contains($path, '/notifications') => 'Notifications',
            str_contains($path, '/devices') => 'Devices',
            str_contains($path, '/roster') => 'Roster',
            str_contains($path, '/tasks') => 'Tasks',
            str_contains($path, '/notices') => 'Notices',
            str_contains($path, '/org/') => 'Organisation',
            str_contains($path, '/ceo/') => 'CEO',
            default => 'Authentication',
        };
    }

    /** @return list<string> */
    /** The middleware stack, shortened to what a reader can act on. */
    private function middlewareNames(Route $route): array
    {
        return collect($route->gatherMiddleware())
            ->map(function ($m) {
                if (! is_string($m)) {
                    return null;
                }

                [$name, $args] = array_pad(explode(':', $m, 2), 2, null);

                return class_basename($name).($args === null ? '' : ':'.$args);
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function isVersioned(Route $route): bool
    {
        return str_starts_with($route->uri(), 'api/v1');
    }
}
