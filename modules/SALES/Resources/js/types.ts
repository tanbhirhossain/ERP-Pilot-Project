/* Entity types for the SALES module UI (U18).
 *
 * Mirrors the module migrations. Money columns are `decimal` in the DB and are
 * serialized as **strings** in JSON, so they are typed `string` here — wrap with
 * Number() at the display edge, exactly as the pages already do. Relations are
 * optional because a controller only eager-loads what its page needs.
 */

/** Minimal partner (customer/vendor) shape as embedded in sales documents. */
export interface PartyRef {
    id: number;
    name: string;
    partner_code?: string | null;
    address?: string | null;
    phone?: string | null;
    email?: string | null;
    tax_id?: string | null;
    legal_name?: string | null;
    type?: string;
    credit_limit?: string | number;
    verification_status?: string;
    is_active?: boolean;
    group?: { id: number; name: string } | null;
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

/* ------------------------------- Quotation ------------------------------- */

export interface QuotationItem extends Timestamps {
    id: number;
    quotation_id: number;
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
    description?: string | null;
    product?: ProductRef | null;
}

export interface Quotation extends Timestamps {
    id: number;
    quotation_no: string;
    customer_id: number;
    price_list_id?: number | null;
    quotation_date: string;
    expiry_date?: string | null;
    currency: string;
    subtotal: string;
    discount_amount: string;
    vat_amount: string;
    total_amount: string;
    status: string;
    notes?: string | null;
    terms?: string | null;
    customer?: PartyRef | null;
    items?: QuotationItem[];
}

/* --------------------------------- Order --------------------------------- */

export interface OrderItem extends Timestamps {
    id: number;
    order_id: number;
    product_id: number;
    variant_id?: number | null;
    product_name: string;
    sku?: string | null;
    qty: string;
    qty_invoiced: string;
    qty_delivered: string;
    unit_price: string;
    discount_pct: string;
    discount_amount: string;
    vat_rate: string;
    vat_amount: string;
    line_total: string;
    product?: ProductRef | null;
}

export interface Order extends Timestamps {
    id: number;
    order_no: string;
    quotation_id?: number | null;
    customer_id: number;
    warehouse_id: number;
    price_list_id?: number | null;
    order_date: string;
    delivery_date?: string | null;
    currency: string;
    subtotal: string;
    discount_amount: string;
    vat_amount: string;
    total_amount: string;
    invoiced_amount: string;
    status: string;
    payment_status: string;
    shipping_address?: string | null;
    notes?: string | null;
    customer?: PartyRef | null;
    warehouse?: WarehouseRef | null;
    quotation?: Quotation | null;
    items?: OrderItem[];
    invoices?: Invoice[];
}

/* -------------------------------- Invoice -------------------------------- */

export interface InvoiceItem extends Timestamps {
    id: number;
    invoice_id: number;
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

export interface Invoice extends Timestamps {
    id: number;
    invoice_no: string;
    order_id?: number | null;
    quotation_id?: number | null;
    customer_id: number;
    warehouse_id: number;
    journal_id?: number | null;
    invoice_date: string;
    due_date?: string | null;
    currency: string;
    subtotal: string;
    discount_amount: string;
    vat_amount: string;
    total_amount: string;
    paid_amount: string;
    balance: string;
    status: string;
    payment_status: string;
    payment_method?: string | null;
    bank_id?: number | null;
    notes?: string | null;
    customer?: PartyRef | null;
    warehouse?: WarehouseRef | null;
    order?: Order | null;
    items?: InvoiceItem[];
    payments?: Payment[];
}

/* -------------------------------- Payment -------------------------------- */

export interface Payment extends Timestamps {
    id: number;
    payment_no: string;
    invoice_id?: number | null;
    customer_id: number;
    payment_date: string;
    amount: string;
    method: string;
    bank_id?: number | null;
    reference_no?: string | null;
    notes?: string | null;
    status: string;
    invoice?: Invoice | null;
    customer?: PartyRef | null;
}

/* -------------------------------- Delivery ------------------------------- */

export interface DeliveryItem extends Timestamps {
    id: number;
    delivery_id: number;
    product_id: number;
    product_name: string;
    sku?: string | null;
    qty: string;
    qty_delivered: string;
    lot_no?: string | null;
    serial_no?: string | null;
    notes?: string | null;
    product?: ProductRef | null;
}

export interface Delivery extends Timestamps {
    id: number;
    delivery_no: string;
    order_id?: number | null;
    invoice_id?: number | null;
    customer_id: number;
    warehouse_id: number;
    delivery_date: string;
    status: string;
    notes?: string | null;
    shipping_address?: string | null;
    challan_no?: string | null;
    vehicle_no?: string | null;
    customer?: PartyRef | null;
    warehouse?: WarehouseRef | null;
    order?: Order | null;
    invoice?: Invoice | null;
    items?: DeliveryItem[];
}

/* --------------------------------- Return -------------------------------- */

export interface ReturnItem extends Timestamps {
    id: number;
    return_id: number;
    invoice_item_id?: number | null;
    product_id: number;
    qty: string;
    unit_price: string;
    line_total: string;
    vat_rate: string;
    vat_amount: string;
    condition?: string;
    lot_no?: string | null;
    notes?: string | null;
    product?: ProductRef | null;
}

export interface SalesReturn extends Timestamps {
    id: number;
    return_no: string;
    invoice_id?: number | null;
    customer_id: number;
    warehouse_id: number;
    return_date: string;
    status: string;
    refund_method?: string;
    reason?: string | null;
    subtotal: string;
    vat_amount: string;
    total_amount: string;
    is_restocked: boolean;
    notes?: string | null;
    invoice?: Invoice | null;
    customer?: PartyRef | null;
    warehouse?: WarehouseRef | null;
    items?: ReturnItem[];
}
