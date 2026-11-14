<?php

return [
    'code' => [
        'quotation_prefix' => env('SALES_QUOTATION_PREFIX', 'QT-'),
        'order_prefix' => env('SALES_ORDER_PREFIX', 'SO-'),
        'invoice_prefix' => env('SALES_INVOICE_PREFIX', 'INV-'),
        'pos_prefix' => env('SALES_POS_PREFIX', 'POS-'),
        'payment_prefix' => env('SALES_PAYMENT_PREFIX', 'PAY-'),
        'pad' => (int) env('SALES_CODE_PAD', 5),
    ],

    'pricing' => [
        'default_currency' => env('SALES_CURRENCY', 'BDT'),
        'default_price_list' => env('SALES_DEFAULT_PRICELIST', null),
        'vat_rate' => (float) env('SALES_VAT_RATE', 0), // line-level if null use product vat_rate
        'discount_max_pct' => (float) env('SALES_DISCOUNT_MAX', 100),
    ],

    'stock' => [
        'reserve_on_order' => env('SALES_RESERVE_ON_ORDER', true), // reserve via InventoryStockProvider on order confirm
        'deduct_on_invoice' => env('SALES_DEDUCT_ON_INVOICE', true), // deduct on invoice post
        'allow_negative' => env('SALES_ALLOW_NEGATIVE', false),
        'default_warehouse_id' => env('SALES_DEFAULT_WAREHOUSE', null),
    ],

    // Credit control (Phase 1). The same exposure rule the POS counter applies,
    // now enforced on the SO→INV credit path too. `credit_limit <= 0` on a
    // partner means "no limit set". Set these false only for a deliberate
    // no-limit deployment.
    'credit' => [
        'enforce_on_order' => env('SALES_CREDIT_ENFORCE_ON_ORDER', true),
        'enforce_on_invoice' => env('SALES_CREDIT_ENFORCE_ON_INVOICE', true),
    ],

    'pos' => [
        'allow_cash' => true,
        'allow_bank' => true,
        'allow_credit' => true,
        'require_session' => env('POS_REQUIRE_SESSION', false),
        'auto_close_session_hour' => env('POS_AUTO_CLOSE_HOUR', null),
    ],

    'workflow' => [
        'quotation_expiry_days' => (int) env('SALES_QUOTATION_EXPIRY', 7),
        'order_auto_confirm' => env('SALES_ORDER_AUTO_CONFIRM', false),
        'invoice_auto_post' => env('SALES_INVOICE_AUTO_POST', true), // also post to ACCOUNTS
    ],

    'exports' => [
        'excel_max_rows' => (int) env('SALES_EXPORT_MAX', 50000),
        'pdf_max_rows' => (int) env('SALES_EXPORT_PDF_MAX', 2000),
    ],

    'permissions' => [
        'quotations.view', 'quotations.manage',
        'orders.view', 'orders.manage', 'orders.confirm',
        'invoices.view', 'invoices.manage', 'invoices.post',
        'deliveries.view', 'deliveries.manage',
        'returns.view', 'returns.manage', 'returns.approve',
        'pos.terminal.sell', 'pos.sessions.manage',
        'payments.manage',
        'reports.view',
    ],
];
