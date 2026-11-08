<?php

namespace Modules\INVENTORY\Services;

use Illuminate\Support\Facades\DB;
use Modules\INVENTORY\Models\Product;

class ProductCodeGeneratorService
{
    public function generate(): string
    {
        $prefix = config('inventory.sku_prefix', 'SKU-');
        $pad = (int) config('inventory.sku_pad', 5);

        return DB::transaction(function () use ($prefix, $pad): string {
            $last = Product::where('sku', 'like', $prefix.'%')->lockForUpdate()->orderBy('id', 'desc')->first();
            $num = 0;
            if ($last) {
                $num = (int) preg_replace('/\D/', '', substr($last->sku, strlen($prefix)));
            }
            $next = $prefix.str_pad((string) ($num + 1), $pad, '0', STR_PAD_LEFT);
            while (Product::where('sku', $next)->exists()) {
                $num++;
                $next = $prefix.str_pad((string) ($num + 1), $pad, '0', STR_PAD_LEFT);
            }

            return $next;
        });
    }
}
