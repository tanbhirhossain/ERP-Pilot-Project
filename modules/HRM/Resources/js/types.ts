/* Entity types for the HRM module UI (U18).
 * Decimal columns are strings in JSON; relations optional. Mirrors migrations.
 * Note: employees.employee_id is a string business key; the FK surrogate is id.
 */

interface Timestamps {
    created_at?: string | null;
    updated_at?: string | null;
}

export interface NamedRef {
    id: number;
    name: string;
    code?: string | null;
}

export interface Employee extends Timestamps {
    id: number;
    employee_id: string;
    user_id?: number | null;
    first_name: string;
    last_name?: string | null;
    full_name: string;
    email?: string | null;
    phone?: string | null;
    nid?: string | null;
    gender?: string | null;
    date_of_birth?: string | null;
    joining_date?: string | null;
    department_id?: number | null;
    designation_id?: number | null;
    employment_type?: string | null;
    status?: string | null;
    blood_group?: string | null;
    marital_status?: string | null;
    photo?: string | null;
    address?: string | null;
    is_active: boolean;
    department?: NamedRef | null;
    designation?: NamedRef | null;
}

export interface LeaveType extends Timestamps {
    id: number;
    name: string;
    code?: string | null;
    days_per_year: number;
    is_paid: boolean;
    color?: string | null;
    description?: string | null;
    is_active: boolean;
}

export interface LeaveRequest extends Timestamps {
    id: number;
    employee_id: string;
    leave_type_id: number;
    from_date: string;
    to_date: string;
    days: string;
    is_half_day: boolean;
    reason?: string | null;
    status: string;
    approved_at?: string | null;
    employee?: Employee | null;
    leave_type?: LeaveType | null;
}

export interface EmployeeMovement extends Timestamps {
    id: number;
    movement_no: string;
    employee_id: string;
    movement_type?: string;
    effective_date: string;
    from_department_id?: number | null;
    to_department_id?: number | null;
    from_designation_id?: number | null;
    to_designation_id?: number | null;
    from_grade?: string | null;
    to_grade?: string | null;
    from_salary?: string | null;
    to_salary?: string | null;
    increment_amount?: string | null;
    reason?: string | null;
    status: string;
    approved_at?: string | null;
    employee?: Employee | null;
    from_department?: NamedRef | null;
    to_department?: NamedRef | null;
    from_designation?: NamedRef | null;
    to_designation?: NamedRef | null;
}

export interface ShiftSwap extends Timestamps {
    id: number;
    roster_duty_id: number;
    requester_employee_id: string;
    target_employee_id: string;
    requested_shift_id?: number | null;
    target_shift_id?: number | null;
    swap_date: string;
    reason?: string | null;
    status: string;
    requester?: Employee | null;
    target?: Employee | null;
}
