/* Entity types for the PAYROLL module UI (U18).
 * Decimal columns are strings in JSON; relations optional. Mirrors migrations.
 * Note: PAYROLL has no tsconfig path alias, so pages import these via relative
 * paths (e.g. '../../types').
 */

interface Timestamps {
    created_at?: string | null;
    updated_at?: string | null;
}

export interface SalarySheet extends Timestamps {
    id: number;
    pay_year: number;
    pay_month: number;
    status: string;
    include_punishments: boolean;
    employee_count: number;
    processed_count: number;
    gross_total: string;
    deduction_total: string;
    net_total: string;
    payroll_cutlist_id?: number | null;
    started_at?: string | null;
    finished_at?: string | null;
    failure_message?: string | null;
    slips?: PayrollSlip[];
}

export interface PayrollSlip extends Timestamps {
    id: number;
    salary_sheet_id: number;
    employee_id: string;
    employee_name: string;
    department?: string | null;
    designation?: string | null;
    pay_year: number;
    pay_month: number;
    basic_salary: string;
    gross_salary: string;
    total_benefits: string;
    total_deductions: string;
    net_salary: string;
    payment_status: string;
    paid_at?: string | null;
    breakdown_json?: Record<string, unknown> | null;
    sheet?: SalarySheet | null;
}

export interface BankAdvice extends Timestamps {
    id: number;
    advice_no: string;
    pay_year: number;
    pay_month: number;
    salary_sheet_id?: number | null;
    bank_id?: number | null;
    bank_name?: string | null;
    branch_name?: string | null;
    account_no?: string | null;
    total_amount: string;
    employee_count: number;
    status: string;
    file_path?: string | null;
    file_format?: string | null;
    narration?: string | null;
    confirmed_at?: string | null;
    sheet?: SalarySheet | null;
}
