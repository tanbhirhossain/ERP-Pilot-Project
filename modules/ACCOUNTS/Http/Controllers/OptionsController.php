<?php

namespace Modules\ACCOUNTS\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\ACCOUNTS\Services\AccountService;
use Modules\ACCOUNTS\Services\BankService;
use Modules\CORE\Support\OptionAccess;

/**
 * Typeahead source for the ACCOUNTS pickers.
 *
 * This endpoint had no authorisation beyond `['web', 'auth']`. `banks` is the serious one:
 * `BankRepository::options()` selects `account_no` and puts it in the option *label*, so any
 * authenticated user could read every company bank account number with a single request. `accounts`
 * exposes the whole posting chart, which is less sensitive but is still the structure of the books.
 *
 * `accounts` is served to `PURCHASE/LandedCosts/Create.vue` as well as five ACCOUNTS pages, so the
 * purchase landed-cost grants are in the list too.
 */
class OptionsController extends Controller
{
    /** @var array<string, list<string>> type => permissions, any of which is sufficient */
    private const PERMISSIONS = [
        'accounts' => [
            'accounts.coa.view', 'accounts.coa.manage',
            'accounts.journals.view', 'accounts.journals.create', 'accounts.journals.post', 'accounts.journals.void',
            'accounts.vouchers.view', 'accounts.vouchers.manage',
            'accounts.reports.view', 'accounts.payables.view', 'accounts.receivables.view',
            'accounts.banks.view', 'accounts.banks.manage', 'accounts.fiscal.manage',
            'purchase.landed.view', 'purchase.landed.manage', 'purchase.bills.view', 'purchase.bills.manage',
        ],
        'posting-accounts' => [
            'accounts.coa.view', 'accounts.coa.manage',
            'accounts.journals.view', 'accounts.journals.create', 'accounts.journals.post', 'accounts.journals.void',
            'accounts.vouchers.view', 'accounts.vouchers.manage',
            'accounts.reports.view', 'accounts.payables.view', 'accounts.receivables.view',
            'accounts.banks.view', 'accounts.banks.manage', 'accounts.fiscal.manage',
            'purchase.landed.view', 'purchase.landed.manage', 'purchase.bills.view', 'purchase.bills.manage',
        ],
        'banks' => [
            'accounts.banks.view', 'accounts.banks.manage',
            'accounts.journals.create', 'accounts.journals.view',
            'purchase.payments.manage', 'purchase.bills.manage',
            'pos.terminal.sell', 'pos.sessions.manage',
            'payroll.bank-advices.view', 'payroll.bank-advices.manage',
            'payroll.finance.view', 'payroll.finance.post',
        ],
    ];

    public function __invoke(Request $r, string $type, AccountService $accounts, BankService $banks): JsonResponse
    {
        if ($denied = OptionAccess::gate($r, $type, self::PERMISSIONS)) {
            return $denied;
        }

        $search = $r->query('search');
        $limit = min((int) $r->query('limit', 50), 100);

        // No `default` arm — see OptionAccess: a mapped type with no handler must fail loudly.
        $data = match ($type) {
            'accounts' => $accounts->options($search, $limit),
            'posting-accounts' => $accounts->options($search, $limit),
            'banks' => $banks->options($search, $limit),
        };

        return response()->json(['data' => $data]);
    }
}
