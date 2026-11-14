/* Entity types for the PARTNER module UI (U18).
 * Decimal columns are strings in JSON; relations optional. Mirrors migrations.
 */

interface Timestamps {
    created_at?: string | null;
    updated_at?: string | null;
}

export interface PartnerGroup extends Timestamps {
    id: number;
    parent_id?: number | null;
    name: string;
    code?: string | null;
    type?: string;
    description?: string | null;
    icon?: string | null;
    color?: string | null;
    is_active: boolean;
    parent?: PartnerGroup | null;
    children?: PartnerGroup[];
}

export interface PriceListItem extends Timestamps {
    id: number;
    price_list_id: number;
    product_id: number;
    product_name: string;
    sku?: string | null;
    unit_price: string;
    min_qty?: number;
    discount_pct?: string | null;
    notes?: string | null;
}

export interface PriceList extends Timestamps {
    id: number;
    name: string;
    code?: string | null;
    currency?: string;
    is_default: boolean;
    effective_from?: string | null;
    effective_to?: string | null;
    is_active: boolean;
    notes?: string | null;
    items?: PriceListItem[];
}

export interface PartnerContact extends Timestamps {
    id: number;
    partner_id: number;
    name: string;
    designation?: string | null;
    phone?: string | null;
    email?: string | null;
    is_primary: boolean;
}

export interface PartnerAddress extends Timestamps {
    id: number;
    partner_id: number;
    type?: string;
    label?: string | null;
    address_line?: string | null;
    area?: string | null;
    city?: string | null;
    district?: string | null;
    division?: string | null;
    country?: string | null;
    postal_code?: string | null;
    is_default: boolean;
}

export interface PartnerBank extends Timestamps {
    id: number;
    partner_id: number;
    bank_name: string;
    branch?: string | null;
    account_name?: string | null;
    account_no?: string | null;
    routing_no?: string | null;
    swift_code?: string | null;
    account_type?: string | null;
    is_primary: boolean;
}

export interface Partner extends Timestamps {
    id: number;
    partner_code?: string | null;
    type: string;
    name: string;
    legal_name?: string | null;
    group_id?: number | null;
    price_list_id?: number | null;
    trade_license?: string | null;
    tin?: string | null;
    bin?: string | null;
    vat_reg_no?: string | null;
    nid?: string | null;
    phone?: string | null;
    email?: string | null;
    website?: string | null;
    credit_limit?: string | null;
    payment_terms_days?: number | null;
    payment_terms?: string | null;
    opening_balance?: string | null;
    current_balance?: string | null;
    currency?: string | null;
    is_active: boolean;
    is_verified: boolean;
    verification_status?: string | null;
    notes?: string | null;
    internal_notes?: string | null;
    meta?: Record<string, unknown> | null;
    group?: PartnerGroup | null;
    price_list?: PriceList | null;
    contacts?: PartnerContact[];
    addresses?: PartnerAddress[];
    banks?: PartnerBank[];
}
