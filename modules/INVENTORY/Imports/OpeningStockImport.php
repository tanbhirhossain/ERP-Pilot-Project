<?php

namespace Modules\INVENTORY\Imports;

use Modules\ACCOUNTS\Services\OpeningBalanceService;
use Modules\CORE\Imports\ImportContract;
use Modules\INVENTORY\Models\Product;
use Modules\INVENTORY\Models\Warehouse;

/**
 * Opening-stock take-on by spreadsheet (Phase 1 — F5 + F4).
 *
 * Resolves SKU + warehouse code to ids, then delegates to the same
 * OpeningBalanceService the wizard uses — one code path for stock take-on,
 * whether keyed in or uploaded. The batch reference is generated so each upload
 * is its own auditable, idempotent batch.
 */
class OpeningStockImport implements ImportContract
{
    public function key(): string
    {
        return 'opening_stock';
    }

    public function label(): string
    {
        return 'Opening Stock';
    }

    public function permission(): string
    {
        return 'inventory.adjustments.manage';
    }

    public function headers(): array
    {
        return ['sku', 'warehouse_code', 'qty', 'unit_cost'];
    }

    public function exampleRow(): array
    {
        return ['SKU-0001', 'WH-MAIN', 100, 120.00];
    }

    public function validateRows(array $rows): array
    {
        $errors = [];

        // Cache lookups — a 5,000-row file must not issue 10,000 queries.
        $products = Product::pluck('id', 'sku');
        $warehouses = Warehouse::pluck('id', 'code');

        foreach ($rows as $i => $row) {
            $rowErrors = [];
            $sku = trim((string) ($row['sku'] ?? ''));
            $wh = trim((string) ($row['warehouse_code'] ?? ''));

            if ($sku === '' || ! isset($products[$sku])) {
                $rowErrors[] = "sku \"{$sku}\" not found — import products first";
            }

            if ($wh === '' || ! isset($warehouses[$wh])) {
                $rowErrors[] = "warehouse_code \"{$wh}\" not found";
            }

            $qty = $row['qty'] ?? null;
            if (! is_numeric($qty) || (float) $qty <= 0) {
                $rowErrors[] = 'qty must be a positive number';
            }

            $cost = $row['unit_cost'] ?? null;
            if ($cost !== null && $cost !== '' && ! is_numeric($cost)) {
                $rowErrors[] = 'unit_cost must be a number';
            }

            if ($rowErrors !== []) {
                $errors[$i] = $rowErrors;
            }
        }

        return $errors;
    }

    public function commit(array $rows): int
    {
        $products = Product::pluck('id', 'sku');
        $warehouses = Warehouse::pluck('id', 'code');

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'product_id' => (int) $products[trim((string) $row['sku'])],
                'warehouse_id' => (int) $warehouses[trim((string) $row['warehouse_code'])],
                'qty' => (float) $row['qty'],
                'unit_cost' => (float) ($row['unit_cost'] ?? 0),
            ];
        }

        $reference = 'OB-STOCK-'.now()->format('Ymd-His');
        app(OpeningBalanceService::class)->postStock($reference, now()->toDateString(), $items);

        return count($items);
    }
}
