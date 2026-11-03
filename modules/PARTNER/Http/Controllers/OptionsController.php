<?php

namespace Modules\PARTNER\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\PARTNER\Services\PartnerGroupService;
use Modules\PARTNER\Services\PartnerService;
use Modules\PARTNER\Services\PriceListService;

class OptionsController extends Controller
{
    public function __invoke(Request $request, string $type): JsonResponse
    {
        $search = $request->query('search');
        $limit = min(100, (int) $request->query('limit', 25));

        $data = match ($type) {
            'vendors' => app(PartnerService::class)->options($search, $limit, 'vendor'),
            'customers' => app(PartnerService::class)->options($search, $limit, 'customer'),
            'all-partners', 'partners' => app(PartnerService::class)->options($search, $limit, null),
            'partner-groups', 'groups' => app(PartnerGroupService::class)->options($search, $limit),
            'vendor-groups' => app(PartnerGroupService::class)->options($search, $limit, 'vendor'),
            'customer-groups' => app(PartnerGroupService::class)->options($search, $limit, 'customer'),
            'price-lists', 'pricelists' => app(PriceListService::class)->options($search, $limit),
            default => collect([]),
        };

        return response()->json(['data' => $data]);
    }
}
