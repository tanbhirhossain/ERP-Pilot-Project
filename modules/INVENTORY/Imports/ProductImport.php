<?php

namespace Modules\INVENTORY\Imports;

use Modules\CORE\Imports\ImportContract;
use Modules\INVENTORY\Models\Product;

/**
 * Bulk product take-on (Phase 1 — F5). Upserts by SKU: an existing SKU is
 * updated, a new one created. Prices/stock are NOT touched here — opening stock
 * is its own import so costing stays a deliberate act.
 */
class ProductImport implements ImportContract
{
    public function key(): string
    {
        return 'products';
    }

    public function label(): string
    {
        return 'Products';
    }

    public function permission(): string
    {
        return 'inventory.products.create';
    }

    public function headers(): array
    {
        return ['sku', 'name', 'barcode', 'type', 'cost_price', 'selling_price', 'vat_rate', 'reorder_level'];
    }

    public function exampleRow(): array
    {
        return ['SKU-0001', 'Cotton Fabric (per yard)', '8801000000011', 'stockable', 120.00, 150.00, 5.0, 20];
    }

    public function validateRows(array $rows): array
    {
        $errors = [];
        $seen = [];

        foreach ($rows as $i => $row) {
            $rowErrors = [];
            $sku = trim((string) ($row['sku'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));

            if ($sku === '') {
                $rowErrors[] = 'sku is required';
            } elseif (isset($seen[$sku])) {
                $rowErrors[] = "duplicate sku \"{$sku}\" in file";
            } else {
                $seen[$sku] = true;
            }

            if ($name === '') {
                $rowErrors[] = 'name is required';
            }

            $type = strtolower(trim((string) ($row['type'] ?? 'stockable')));
            if (! in_array($type, ['stockable', 'consumable', 'service', 'bundle'], true)) {
                $rowErrors[] = "type must be stockable|consumable|service|bundle (got \"{$type}\")";
            }

            foreach (['cost_price', 'selling_price', 'vat_rate', 'reorder_level'] as $num) {
                $v = $row[$num] ?? null;
                if ($v !== null && $v !== '' && ! is_numeric($v)) {
                    $rowErrors[] = "{$num} must be a number";
                }
            }

            if ($rowErrors !== []) {
                $errors[$i] = $rowErrors;
            }
        }

        return $errors;
    }

    public function commit(array $rows): int
    {
        $count = 0;

        foreach ($rows as $row) {
            $sku = trim((string) $row['sku']);

            $product = Product::withTrashed()->where('sku', $sku)->first();

            $attributes = [
                'name' => trim((string) $row['name']),
                'type' => strtolower(trim((string) ($row['type'] ?? 'stockable'))) ?: 'stockable',
                'cost_price' => (float) ($row['cost_price'] ?? 0),
                'selling_price' => (float) ($row['selling_price'] ?? 0),
                'vat_rate' => (float) ($row['vat_rate'] ?? 0),
                'reorder_level' => (float) ($row['reorder_level'] ?? 0),
            ];

            $barcode = trim((string) ($row['barcode'] ?? ''));
            if ($barcode !== '') {
                $attributes['barcode'] = $barcode;
            }

            if ($product !== null) {
                $product->restore();
                $product->update($attributes);
            } else {
                Product::create($attributes + ['sku' => $sku, 'is_active' => true, 'status' => 'active']);
            }

            $count++;
        }

        return $count;
    }
}
