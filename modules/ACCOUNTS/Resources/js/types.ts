/* Entity types for the ACCOUNTS module UI (U18).
 * Decimal columns are strings in JSON; relations optional. Mirrors migrations.
 */

interface Timestamps {
    created_at?: string | null;
    updated_at?: string | null;
}

export interface FiscalYear extends Timestamps {
    id: number;
    name: string;
    code?: string | null;
    start_date: string;
    end_date: string;
    status: string;
    closed_at?: string | null;
    notes?: string | null;
    periods?: FiscalPeriod[];
}

export interface FiscalPeriod extends Timestamps {
    id: number;
    fiscal_year_id: number;
    name: string;
    month_no: number;
    start_date: string;
    end_date: string;
    status: string;
}

export interface Account extends Timestamps {
    id: number;
    code: string;
    name: string;
    name_bn?: string | null;
    slug?: string | null;
    account_type?: string;
    nature?: string;
    parent_id?: number | null;
    level?: number;
    path?: string | null;
    is_group: boolean;
    is_posting: boolean;
    is_system: boolean;
    is_bank: boolean;
    is_cash: boolean;
    is_reconcile: boolean;
    currency?: string;
    opening_balance?: string | null;
    opening_side?: string | null;
    description?: string | null;
    is_active: boolean;
    parent?: Account | null;
    children?: Account[];
}

export interface JournalLine extends Timestamps {
    id: number;
    journal_id: number;
    account_id: number;
    debit: string;
    credit: string;
    description?: string | null;
    partner_id?: number | null;
    partner_type?: string | null;
    line_no?: number;
    account?: Account | null;
}

export interface Journal extends Timestamps {
    id: number;
    journal_no: string;
    reference_no?: string | null;
    journal_date: string;
    type?: string;
    fiscal_year_id?: number | null;
    fiscal_period_id?: number | null;
    narration?: string | null;
    total_debit: string;
    total_credit: string;
    status: string;
    partner_id?: number | null;
    partner_type?: string | null;
    source_type?: string | null;
    source_id?: number | null;
    posted_at?: string | null;
    voided_at?: string | null;
    void_reason?: string | null;
    lines?: JournalLine[];
    fiscal_year?: FiscalYear | null;
}

export interface Bank extends Timestamps {
    id: number;
    name: string;
    code?: string | null;
    bank_name?: string | null;
    branch_name?: string | null;
    account_no?: string | null;
    account_holder?: string | null;
    swift_code?: string | null;
    account_id?: number | null;
    currency?: string;
    opening_balance?: string | null;
    current_balance?: string | null;
    is_default: boolean;
    is_reconcile: boolean;
    is_active: boolean;
    notes?: string | null;
    account?: Account | null;
}

/* ------------------------- F17: TDS/VDS + Mushak 9.1 ---------------------- */

export interface WithholdingRule extends Timestamps {
    id: number;
    name: string;
    type: 'tds' | 'vds';
    section?: string | null;
    rate: string;
    threshold: string;
    is_active: boolean;
    effective_from?: string | null;
    notes?: string | null;
}

export interface TaxDeposit extends Timestamps {
    id: number;
    deposit_no: string;
    type: 'tds' | 'vds' | 'vat';
    period: string;
    amount: string;
    deposit_date: string;
    bank_id?: number | null;
    challan_no?: string | null;
    treasury_code?: string | null;
    notes?: string | null;
    bank?: { id: number; name: string } | null;
}

export interface Withholding extends Timestamps {
    id: number;
    type: 'tds' | 'vds';
    direction: 'inward' | 'outward';
    rule_id?: number | null;
    partner_id: number;
    bill_id?: number | null;
    invoice_id?: number | null;
    period: string;
    base_amount: string;
    rate: string;
    amount: string;
    status: 'pending' | 'deposited' | 'certified';
    deposit_id?: number | null;
    certificate_no?: string | null;
    deposited_at?: string | null;
    notes?: string | null;
    partner?: { id: number; name: string } | null;
    bill?: { id: number; bill_no: string } | null;
    invoice?: { id: number; invoice_no: string } | null;
    rule?: { id: number; name: string; type: string } | null;
    deposit?: { id: number; deposit_no: string } | null;
}

export interface VatReturn {
    period: { from: string; to: string; label: string; month: string };
    company: { name: string; address: string; bin: string };
    output: {
        standard: { base: number; vat: number };
        reduced: { base: number; vat: number };
        zero: { base: number };
        total_base: number;
        total_vat: number;
    };
    output_adjustment: number;
    net_output_vat: number;
    input: { total_base: number; total_vat: number };
    input_adjustment: number;
    net_input_vat: number;
    vds_credit: number;
    net_payable: number;
    credit_carried_forward: number;
    registers: {
        sales: Array<{ date: string; ref: string; party: string; channel: string; base: number; vat: number; total: number }>;
        purchases: Array<{ date: string; ref: string; party: string; base: number; vat: number; total: number }>;
    };
}

// ── F15: Fixed assets & depreciation ─────────────────────────────────────
export interface AssetCategoryOption {
    id: number;
    name: string;
    code: string;
    default_method: 'straight_line' | 'declining_balance';
    default_useful_life_years: number;
    default_salvage_pct: number;
    default_db_factor: number;
}

export interface FixedAsset {
    id: number;
    asset_code: string;
    name: string;
    category_id: number;
    supplier_partner_id?: number | null;
    invoice_ref?: string | null;
    purchase_date: string;
    in_service_date?: string | null;
    cost: string;
    salvage_value: string;
    method?: 'straight_line' | 'declining_balance' | null;
    useful_life_years?: number | null;
    db_factor?: string | null;
    accumulated_dep: string;
    net_book_value: string;
    status: 'draft' | 'in_use' | 'fully_depreciated' | 'disposed';
    location?: string | null;
    disposed_date?: string | null;
    disposal_proceeds?: string | null;
    notes?: string | null;
    category?: { id: number; name: string; code: string } | null;
    supplier?: { id: number; name: string } | null;
    depreciations?: Array<Record<string, unknown>>;
}

export interface DepreciationCharge {
    ok: boolean;
    charge: number;
    opening_nbv: number;
    accumulated_after: number;
    closing_nbv: number;
    fully: boolean;
    method: 'straight_line' | 'declining_balance';
    basis: string;
}

export interface DepreciationPreviewRow {
    asset: { id: number; asset_code: string; name: string };
    charge: DepreciationCharge;
}

export interface DepreciationRun {
    id: number;
    run_no: string;
    period: string;
    run_date: string;
    total_amount: string;
    line_count: number;
    status: string;
    journal?: { id: number; journal_no: string; status: string } | null;
}

// ── F14: Budgets & variance ──────────────────────────────────────────────
export interface BudgetSummary {
    id: number;
    name: string;
    code: string;
    department?: string | null;
    status: 'draft' | 'approved' | 'locked';
    line_count: number;
    budgeted: number;
    fiscal_year?: string | null;
}

export interface BudgetVarianceRow {
    account_id: number;
    code: string;
    name: string;
    nature: 'debit' | 'credit';
    budget: number;
    actual: number;
    variance: number;
    pct: number;
    favourable: boolean;
}

export interface BudgetVarianceReport {
    budget: { id: number; name: string; code: string; department?: string | null; status: string; period?: string | null; fiscal_year?: string | null };
    periods: string[];
    rows: BudgetVarianceRow[];
    unfavorable: BudgetVarianceRow[];
    totals: { budget: number; actual: number; variance: number; pct: number; overrun_count: number };
}
