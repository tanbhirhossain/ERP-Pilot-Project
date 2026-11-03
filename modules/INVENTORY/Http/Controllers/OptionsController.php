<?php

namespace Modules\INVENTORY\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\INVENTORY\Services\BrandService;
use Modules\INVENTORY\Services\CategoryService;
use Modules\INVENTORY\Services\ProductService;
use Modules\INVENTORY\Services\UomService;
use Modules\INVENTORY\Services\WarehouseService;

class OptionsController extends Controller
{
    public function __invoke(Request $request, string $type): JsonResponse
    {
        $search = $request->query('search');
        $limit = min(100, (int) $request->query('limit', 25));

        $data = match ($type) {
            'products' => app(ProductService::class)->options($search, $limit),
            'categories' => app(CategoryService::class)->options($search, $limit),
            'brands' => app(BrandService::class)->options($search, $limit),
            'uoms', 'units' => app(UomService::class)->options($search, $limit),
            'warehouses' => app(WarehouseService::class)->options($search, $limit),
            default => collect([]),
        };

        return response()->json(['data' => $data]);
    }
}
