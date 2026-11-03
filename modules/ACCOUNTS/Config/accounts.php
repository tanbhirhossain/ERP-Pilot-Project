<?php

return [
    'code' => [
        'journal_prefix' => env('ACCOUNTS_JOURNAL_PREFIX', 'JV-'),
        'voucher_prefix' => env('ACCOUNTS_VOUCHER_PREFIX', 'V-'),
        'pad' => (int) env('ACCOUNTS_CODE_PAD', 5),
    ],

    'fiscal_year' => [
        'start_month' => (int) env('ACCOUNTS_FY_START_MONTH', 7), // Bangladesh July-June
        'start_day' => (int) env('ACCOUNTS_FY_START_DAY', 1),
    ],

    'posting' => [
        'require_fiscal_open' => env('ACCOUNTS_REQUIRE_FISCAL_OPEN', true),
        'allow_future_posting' => env('ACCOUNTS_ALLOW_FUTURE', false),
        'lock_closed_period' => env('ACCOUNTS_LOCK_CLOSED', true),
    ],

    'defaults' => [
        'currency' => env('ACCOUNTS_CURRENCY', 'BDT'),
        'receivable_code' => env('ACCOUNTS_AR_CODE', '1100'),
        'payable_code' => env('ACCOUNTS_AP_CODE', '2100'),
        'cash_code' => env('ACCOUNTS_CASH_CODE', '1001'),
        'bank_code' => env('ACCOUNTS_BANK_CODE', '1002'),
        'sales_code' => env('ACCOUNTS_SALES_CODE', '4001'),
        'purchase_code' => env('ACCOUNTS_PURCHASE_CODE', '5100'),
        'vat_payable_code' => env('ACCOUNTS_VAT_CODE', '2110'),
        'vat_input_code' => env('ACCOUNTS_VAT_INPUT_CODE', '1330'),
        'grni_code' => env('ACCOUNTS_GRNI_CODE', '2115'),
        'inventory_code' => env('ACCOUNTS_INVENTORY_CODE', '1200'),
    ],

    'exports' => [
        'excel_max_rows' => (int) env('ACCOUNTS_EXPORT_MAX', 50000),
        'pdf_max_rows' => (int) env('ACCOUNTS_EXPORT_PDF_MAX', 2000),
    ],

    'permissions' => [
        'coa.view', 'coa.manage',
        'journals.view', 'journals.create', 'journals.post', 'journals.void',
        'vouchers.view', 'vouchers.manage',
        'banks.manage',
        'fiscal.manage',
        'reports.view',
        'payables.view', 'receivables.view',
    ],
];
