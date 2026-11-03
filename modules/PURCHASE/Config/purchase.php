<?php

return [
    'code' => [
        'order_prefix' => env('PURCHASE_ORDER_PREFIX', 'PO-'),
        'receipt_prefix' => env('PURCHASE_RECEIPT_PREFIX', 'GRN-'),
        'bill_prefix' => env('PURCHASE_BILL_PREFIX', 'PB-'),
        'payment_prefix' => env('PURCHASE_PAYMENT_PREFIX', 'PP-'),
        'return_prefix' => env('PURCHASE_RETURN_PREFIX', 'PR-'),
        'pad' => (int) env('PURCHASE_CODE_PAD', 5),
    ],
    'stock' => [
        'post_on_receipt' => true, // stock IN on receipt post
        'allow_over_receipt' => false,
    ],
    'workflow' => [
        'order_auto_approve' => false,
        'bill_auto_post' => true,
        'approval_threshold' => (float) env('PURCHASE_APPROVAL_THRESHOLD', 50000), // BDT
    ],
    'permissions' => [
        'purchase.orders.view', 'purchase.orders.manage', 'purchase.orders.confirm', 'purchase.orders.approve',
        'purchase.receipts.view', 'purchase.receipts.manage', 'purchase.receipts.post', 'purchase.receipts.void',
        'purchase.bills.view', 'purchase.bills.manage', 'purchase.bills.post', 'purchase.bills.void',
        'purchase.returns.view', 'purchase.returns.manage', 'purchase.returns.post',
        'purchase.payments.manage',
        'purchase.reports.view',
    ],
];
