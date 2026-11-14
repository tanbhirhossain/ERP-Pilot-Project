/* Entity types for the PURCHASE module UI (U18).
 * Money columns are decimal strings in JSON; relations are optional. Mirrors the
 * module migrations.
 */

export interface PartyRef {
    id: number;
    name: string;
    partner_code?: string | null;
    address?: string | null;
    phone?: string | null;
    email?: string | null;
    tax_id?: string | null;
    type?: string;
    is_active?: boolean;
}

export interface WarehouseRef {
    id: number;
    name: string;
    code?: string | null;
}

export interface ProductRef {
    id: number;
    name: string;
    sku?: string | null;
}

interface Timestamps {
    created_at?: string | null;
    updated_at?: string | null;
}

/* ----------------------------- Purchase Order ---------------------------- */

export interface PurchaseOrderItem extends Timestamps {
    id: number;
    order_id: number;
    product_id: number;
    variant_id?: number | null;
    product_name: string;
    sku?: string | null;
    qty: string;
    qty_received: string;
    qty_billed: string;
    unit_price: string;
    discount_pct: string;
    discount_amount: string;
    vat_rate: string;
    vat_amount: string;
    line_total: string;
    product?: ProductRef | null;
}

export interface PurchaseOrder extends Timestamps {
    id: number;
    order_no: string;
    vendor_id: number;
    warehouse_id: number;
    order_date: string;
    expected_date?: string | null;
    currency: string;
    subtotal: string;
    discount_amount: string;
    vat_amount: string;
    total_amount: string;
    received_amount: string;
    status: string;
    notes?: string | null;
    vendor?: PartyRef | null;
    warehouse?: WarehouseRef | null;
    items?: PurchaseOrderItem[];
    receipts?: GoodsReceipt[];
}

/* ------------------------------ Goods Receipt ---------------------------- */

export interface ReceiptItem extends Timestamps {
    id: number;
    receipt_id: number;
    order_item_id?: number | null;
    product_id: number;
    variant_id?: number | null;
    product_name: string;
    sku?: string | null;
    qty: string;
    unit_price: string;
    discount_pct: string;
    discount_amount: string;
    vat_rate: string;
    vat_amount: string;
    line_total: string;
    product?: ProductRef | null;
}

export interface GoodsReceipt extends Timestamps {
    id: number;
    receipt_no: string;
    order_id?: number | null;
    vendor_id: number;
    warehouse_id: number;
    receipt_date: string;
    subtotal: string;
    discount_amount: string;
    vat_amount: string;
    total_amount: string;
    status: string;
    notes?: string | null;
    vendor?: PartyRef | null;
    warehouse?: WarehouseRef | null;
    order?: PurchaseOrder | null;
    items?: ReceiptItem[];
}

/* ------------------------------- Vendor Bill ----------------------------- */

export interface BillItem extends Timestamps {
    id: number;
    bill_id: number;
    receipt_item_id?: number | null;
    product_id: number;
    variant_id?: number | null;
    product_name: string;
    sku?: string | null;
    qty: string;
    unit_price: string;
    discount_pct: string;
    discount_amount: string;
    vat_rate: string;
    vat_amount: string;
    line_total: string;
    cost_price?: string | null;
    product?: ProductRef | null;
}

export interface VendorBill extends Timestamps {
    id: number;
    bill_no: string;
    order_id?: number | null;
    receipt_id?: number | null;
    vendor_id: number;
    warehouse_id: number;
    journal_id?: number | null;
    bill_date: string;
    due_date?: string | null;
    vendor_reference?: string | null;
    currency: string;
    subtotal: string;
    discount_amount: string;
    vat_amount: string;
    total_amount: string;
    paid_amount: string;
    status: string;
    payment_status: string;
    notes?: string | null;
    vendor?: PartyRef | null;
    warehouse?: WarehouseRef | null;
    order?: PurchaseOrder | null;
    items?: BillItem[];
}

/* ------------------------------ Purchase Return -------------------------- */

export interface PurchaseReturnItem extends Timestamps {
    id: number;
    return_id: number;
    receipt_item_id?: number | null;
    product_id: number;
    variant_id?: number | null;
    product_name: string;
    sku?: string | null;
    qty: string;
    unit_price: string;
    discount_pct: string;
    discount_amount: string;
    vat_rate: string;
    vat_amount: string;
    line_total: string;
    product?: ProductRef | null;
}

export interface PurchaseReturn extends Timestamps {
    id: number;
    return_no: string;
    order_id?: number | null;
    receipt_id?: number | null;
    bill_id?: number | null;
    vendor_id: number;
    warehouse_id: number;
    return_date: string;
    subtotal: string;
    discount_amount: string;
    vat_amount: string;
    total_amount: string;
    status: string;
    notes?: string | null;
    vendor?: PartyRef | null;
    warehouse?: WarehouseRef | null;
    items?: PurchaseReturnItem[];
}

/* ------------------------------- Landed Cost ----------------------------- */

export interface LandedCostLine extends Timestamps {
    id: number;
    landed_cost_id: number;
    name: string;
    reference_no?: string | null;
    amount: string;
    account_id?: number | null;
}

export interface LandedCostAllocation extends Timestamps {
    id: number;
    landed_cost_id: number;
    receipt_item_id: number;
    product_id: number;
    qty: string;
    base_value: string;
    allocated_cost: string;
    landed_unit_cost: string;
    new_unit_cost: string;
    product?: ProductRef | null;
}

export interface LandedCost extends Timestamps {
    id: number;
    lc_no: string;
    receipt_id: number;
    allocation_basis: string;
    status: string;
    total_cost: string;
    notes?: string | null;
    receipt?: GoodsReceipt | null;
    lines?: LandedCostLine[];
    allocations?: LandedCostAllocation[];
}
