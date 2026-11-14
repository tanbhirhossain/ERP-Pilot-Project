/* Entity types for the INVENTORY module UI (U18).
 * Money/qty decimal columns are strings in JSON; relations optional. Mirrors the
 * module migrations.
 */

export interface Ref {
    id: number;
    name: string;
    code?: string | null;
    sku?: string | null;
}

interface Timestamps {
    created_at?: string | null;
    updated_at?: string | null;
}

export interface Uom extends Timestamps {
    id: number;
    name: string;
    symbol: string;
    code?: string | null;
    base_uom_id?: number | null;
    conversion_factor: string;
    is_active: boolean;
    description?: string | null;
}

export interface Category extends Timestamps {
    id: number;
    parent_id?: number | null;
    name: string;
    code?: string | null;
    slug?: string | null;
    description?: string | null;
    icon?: string | null;
    color?: string | null;
    sort_order?: number;
    is_active: boolean;
    parent?: Category | null;
    children?: Category[];
}

export interface Brand extends Timestamps {
    id: number;
    name: string;
    code?: string | null;
    slug?: string | null;
    description?: string | null;
    logo?: string | null;
    website?: string | null;
    is_active: boolean;
}

export interface Warehouse extends Timestamps {
    id: number;
    name: string;
    code?: string | null;
    short_code?: string | null;
    type?: string;
    address_line?: string | null;
    city?: string | null;
    district?: string | null;
    division?: string | null;
    country?: string | null;
    phone?: string | null;
    email?: string | null;
    is_default: boolean;
    is_active: boolean;
    allow_negative_stock: boolean;
    notes?: string | null;
}

export interface Stock extends Timestamps {
    id: number;
    product_id: number;
    variant_id?: number | null;
    warehouse_id: number;
    lot_no?: string | null;
    serial_no?: string | null;
    qty_on_hand: string;
    qty_reserved: string;
    qty_available: string;
    avg_cost: string;
    total_value: string;
    product?: Product | null;
    warehouse?: Warehouse | null;
}

export interface Product extends Timestamps {
    id: number;
    name: string;
    sku?: string | null;
    barcode?: string | null;
    slug?: string | null;
    category_id?: number | null;
    brand_id?: number | null;
    uom_id?: number | null;
    type?: string;
    description?: string | null;
    short_description?: string | null;
    hs_code?: string | null;
    weight?: string | null;
    is_serialized: boolean;
    is_batch_tracked: boolean;
    reorder_level?: string | null;
    reorder_qty?: string | null;
    valuation_method?: string;
    cost_price: string;
    selling_price: string;
    mrp?: string | null;
    vat_rate?: string | null;
    currency?: string;
    image?: string | null;
    is_active: boolean;
    is_featured: boolean;
    status?: string;
    category?: Category | null;
    brand?: Brand | null;
    uom?: Uom | null;
    stocks?: Stock[];
}

export interface TransferItem extends Timestamps {
    id: number;
    transfer_id: number;
    product_id: number;
    variant_id?: number | null;
    qty_requested: string;
    qty_shipped: string;
    qty_received: string;
    unit_cost?: string | null;
    lot_no?: string | null;
    notes?: string | null;
    product?: Product | null;
}

export interface Transfer extends Timestamps {
    id: number;
    transfer_no: string;
    from_warehouse_id: number;
    to_warehouse_id: number;
    status: string;
    reason?: string | null;
    notes?: string | null;
    shipped_at?: string | null;
    received_at?: string | null;
    from_warehouse?: Warehouse | null;
    to_warehouse?: Warehouse | null;
    items?: TransferItem[];
}

export interface AdjustmentItem extends Timestamps {
    id: number;
    adjustment_id: number;
    product_id: number;
    variant_id?: number | null;
    qty: string;
    unit_cost?: string | null;
    total_cost?: string | null;
    lot_no?: string | null;
    reason?: string | null;
    product?: Product | null;
}

export interface Adjustment extends Timestamps {
    id: number;
    adjustment_no: string;
    warehouse_id: number;
    type?: string;
    status: string;
    reason?: string | null;
    notes?: string | null;
    approved_at?: string | null;
    posted_at?: string | null;
    warehouse?: Warehouse | null;
    items?: AdjustmentItem[];
}

export interface KitItem extends Timestamps {
    id: number;
    kit_id: number;
    component_product_id: number;
    required_qty: string;
    consumed_qty?: string | null;
    unit_cost?: string | null;
    line_cost?: string | null;
    is_optional: boolean;
    component?: Product | null;
}

export interface Kit extends Timestamps {
    id: number;
    kit_no: string;
    product_id: number;
    warehouse_id: number;
    qty_to_produce: string;
    qty_produced?: string | null;
    total_component_cost?: string | null;
    unit_cost?: string | null;
    status: string;
    notes?: string | null;
    product?: Product | null;
    warehouse?: Warehouse | null;
    items?: KitItem[];
}

export interface CountItem extends Timestamps {
    id: number;
    count_id: number;
    product_id: number;
    system_qty: string;
    counted_qty: string;
    variance: string;
    variance_cost?: string | null;
    action?: string;
    notes?: string | null;
    product?: Product | null;
}

export interface StockCount extends Timestamps {
    id: number;
    count_no: string;
    warehouse_id: number;
    status: string;
    count_date: string;
    notes?: string | null;
    reconciled_at?: string | null;
    warehouse?: Warehouse | null;
    items?: CountItem[];
}
