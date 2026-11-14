<?php

/**
 * Phase 1 — F5 bulk import framework.
 *
 * The two-step contract: preview validates and writes nothing; commit re-validates
 * and persists in one transaction (all-or-nothing). Products upsert by SKU so a
 * re-run updates rather than duplicates. Header titles are normalized so an
 * operator's "Selling Price" column resolves to selling_price.
 *
 * Helper names are prefixed `p1imp*`.
 */

use Modules\CORE\Imports\SpreadsheetReader;
use Modules\CORE\Services\ImportService;
use Modules\INVENTORY\Models\Product;

function p1impCsv(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'p1imp').'.csv';
    $fh = fopen($path, 'w');
    foreach ($rows as $row) {
        fputcsv($fh, $row);
    }
    fclose($fh);

    return $path;
}

test('preview validates a good file and writes nothing', function () {
    $path = p1impCsv([
        ['sku', 'name', 'type', 'cost_price', 'selling_price'],
        ['P1IMP-1', 'Import Widget One', 'stockable', 10, 20],
        ['P1IMP-2', 'Import Widget Two', 'stockable', 15, 30],
    ]);

    $svc = app(ImportService::class);
    $result = $svc->preview('products', $path);

    expect($result['valid'])->toBeTrue()
        ->and($result['rows'])->toBe(2)
        ->and($result['errors'])->toBe([])
        ->and(Product::whereIn('sku', ['P1IMP-1', 'P1IMP-2'])->count())->toBe(0);

    @unlink($path);
});

test('preview reports row-level errors for a bad file', function () {
    $path = p1impCsv([
        ['sku', 'name', 'type', 'selling_price'],
        ['P1IMP-BAD', '', 'not-a-type', 'abc'],
    ]);

    $result = app(ImportService::class)->preview('products', $path);

    expect($result['valid'])->toBeFalse()
        ->and($result['errors'])->toHaveKey(0)
        ->and($result['errors'][0])->toContain('name is required');

    @unlink($path);
});

test('commit persists products and upserts by SKU on re-run', function () {
    $path = p1impCsv([
        ['sku', 'name', 'type', 'cost_price', 'selling_price'],
        ['P1IMP-UP', 'Original Name', 'stockable', 10, 20],
    ]);

    $svc = app(ImportService::class);
    $first = $svc->commit('products', $path);
    expect($first['imported'])->toBe(1)
        ->and(Product::where('sku', 'P1IMP-UP')->count())->toBe(1)
        ->and(Product::where('sku', 'P1IMP-UP')->first()->name)->toBe('Original Name');

    // Re-run with a changed name updates the same row, not a duplicate.
    $path2 = p1impCsv([
        ['sku', 'name', 'type', 'cost_price', 'selling_price'],
        ['P1IMP-UP', 'Updated Name', 'stockable', 12, 25],
    ]);
    $svc->commit('products', $path2);
    expect(Product::where('sku', 'P1IMP-UP')->count())->toBe(1)
        ->and(Product::where('sku', 'P1IMP-UP')->first()->name)->toBe('Updated Name');

    @unlink($path);
    @unlink($path2);
});

test('commit is all-or-nothing — one bad row writes none', function () {
    $path = p1impCsv([
        ['sku', 'name', 'type', 'selling_price'],
        ['P1IMP-OK', 'Good Row', 'stockable', 20],
        ['P1IMP-NO', '', 'stockable', 30],
    ]);

    $svc = app(ImportService::class);

    try {
        $svc->commit('products', $path);
        $this->fail('commit should refuse a file with errors');
    } catch (DomainException) {
    }

    expect(Product::whereIn('sku', ['P1IMP-OK', 'P1IMP-NO'])->count())->toBe(0);

    @unlink($path);
});

test('header titles are normalized to snake_case keys', function () {
    $path = p1impCsv([
        ['SKU', 'Product Name', 'Type', 'Selling Price'],
        ['P1IMP-HDR', 'Normalized Header', 'stockable', 42],
    ]);

    // "Product Name" normalizes to product_name, but the importer reads `name`,
    // so this row is invalid for name — proving normalization ran (the header is
    // not literally "name"). We assert the reader produced snake_case keys.
    $rows = SpreadsheetReader::read($path);
    expect($rows[0])->toHaveKey('product_name')
        ->and($rows[0])->toHaveKey('selling_price')
        ->and($rows[0]['sku'])->toBe('P1IMP-HDR');

    @unlink($path);
});

test('an unknown import type is refused', function () {
    $this->expectException(DomainException::class);
    app(ImportService::class)->get('does-not-exist');
});
