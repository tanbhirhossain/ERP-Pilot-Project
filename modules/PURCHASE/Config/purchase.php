<?php

return [
    'code' => [
        'order_prefix' => env('PURCHASE_ORDER_PREFIX', 'PO-'),
        'receipt_prefix' => env('PURCHASE_RECEIPT_PREFIX', 'GRN-'),
        'bill_prefix' => env('PURCHASE_BILL_PREFIX', 'PB-'),
        'payment_prefix' => env('PURCHASE_PAYMENT_PREFIX', 'PP-'),
        'return_prefix' => env('PURCHASE_RETURN_PREFIX', 'PR-'),
        // F18. `PR-` was already taken by returns, so requisitions are REQ- and a requisition number is
        // never ambiguous on a paper file or in a search.
        'requisition_prefix' => env('PURCHASE_REQUISITION_PREFIX', 'REQ-'),
        'rfq_prefix' => env('PURCHASE_RFQ_PREFIX', 'RFQ-'),
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
        'purchase.landed.view', 'purchase.landed.manage',
        'purchase.payments.manage',
        'purchase.reports.view',
        // F18. Requisition is split three ways (see / ask / decide) and RFQ two (key in a quote / choose
        // a winner), because the whole document exists to separate those hands.
        'purchase.requisitions.view', 'purchase.requisitions.manage', 'purchase.requisitions.approve',
        'purchase.rfq.view', 'purchase.rfq.manage', 'purchase.rfq.award',
    ],
];
