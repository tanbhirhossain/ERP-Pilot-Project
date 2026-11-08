<?php

return [
    'code_prefix' => [
        'vendor' => env('PARTNER_VENDOR_PREFIX', 'VEN-'),
        'customer' => env('PARTNER_CUSTOMER_PREFIX', 'CUS-'),
        'both' => env('PARTNER_BOTH_PREFIX', 'PAR-'),
    ],
    'code_pad' => (int) env('PARTNER_CODE_PAD', 4),

    'default_currency' => env('PARTNER_DEFAULT_CURRENCY', 'BDT'),

    'credit_check' => [
        'block_on_exceed' => env('PARTNER_CREDIT_BLOCK', false),
        'warning_threshold_pct' => (int) env('PARTNER_CREDIT_WARNING_PCT', 80),
    ],

    'exports' => [
        'excel_max_rows' => (int) env('PARTNER_EXPORT_EXCEL_MAX_ROWS', 50000),
        'pdf_max_rows' => (int) env('PARTNER_EXPORT_PDF_MAX_ROWS', 2000),
    ],
];
