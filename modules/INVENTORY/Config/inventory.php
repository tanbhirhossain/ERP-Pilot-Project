<?php

return [
    'sku_prefix' => env('INVENTORY_SKU_PREFIX', 'SKU-'),
    'sku_pad' => (int) env('INVENTORY_SKU_PAD', 5),
    'barcode_prefix' => env('INVENTORY_BARCODE_PREFIX', ''),

    'valuation' => [
        'default_method' => env('INVENTORY_VALUATION_METHOD', 'weighted'), // weighted | fifo
        'allow_negative_stock' => env('INVENTORY_ALLOW_NEGATIVE', false),
    ],

    'reorder' => [
        'check_on_movement' => env('INVENTORY_REORDER_CHECK', true),
        'low_stock_threshold_pct' => (int) env('INVENTORY_LOW_STOCK_PCT', 100),
    ],

    'transfer' => [
        'require_approval' => env('INVENTORY_TRANSFER_APPROVAL', false),
    ],

    'exports' => [
        'excel_max_rows' => (int) env('INVENTORY_EXPORT_EXCEL_MAX_ROWS', 50000),
        'pdf_max_rows' => (int) env('INVENTORY_EXPORT_PDF_MAX_ROWS', 2000),
    ],
];
