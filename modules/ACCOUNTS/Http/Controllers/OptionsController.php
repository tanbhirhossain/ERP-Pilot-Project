<?php

namespace Modules\ACCOUNTS\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\ACCOUNTS\Services\AccountService;
use Modules\ACCOUNTS\Services\BankService;

class OptionsController extends Controller
{
    public function __invoke(Request $r, string $type, AccountService $accounts, BankService $banks): JsonResponse
    {
        $search = $r->query('search');
        $limit = min((int) $r->query('limit', 50), 100);

        $data = match ($type) {
            'accounts' => $accounts->options($search, $limit),
            'posting-accounts' => $accounts->options($search, $limit),
            'banks' => $banks->options($search, $limit),
            default => collect(),
        };

        return response()->json(['data' => $data]);
    }
}
