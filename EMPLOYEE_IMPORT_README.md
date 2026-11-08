# Employee Real Data Import — AMZ Hospital (Basic Mode)

## Mode: BASIC (simplified per user request)
> Imports only core fields + biometric mapping. Skips file_no, card_issue, overtime/bonus, bank, conveyance details. Migration columns stay nullable but unused.

## Source
Excel: http://erp.amzhospitalbd.com/check/employees.xlsx (239KB, 1005 employees, 35 columns, 3 sheets)
- Sheet `Worksheet` (Master): Emp ID, Biometric ID, File No, Name, Designation, Department ID/Name, Unit, NID, Blood Group, Status, Emergency Contact, Phone, Address, DOB, Joining Date, Card Issue Date, Photo, Employee Type, Job Type, Joining Salary, Allow Overtime, Allow Bonus, Payment Criteria, Basic Pay, House Rent, Medical, Conveyance, Monthly Salary, Payment Method, Bank Name, Bank A/C No, Created At, Service Year
- Sheet `Worksheet 1` (Salary Info): id, emp_id, biometric_id, basic_pay, house_rent, medical_allowance, conv_allowance, monthly_salary, method, bank_name, bank_acc_no — verified identical to master (0 mismatches)
- Sheet `Worksheet 2` (Departments): id, code, name, description — 57 rows, ~25 unique departments

File copies kept in repo:
- `database/seeders/data/employees.xlsx`
- `storage/app/employees.xlsx`

## Mapping — Emp ID → employee_id, biometric_id → device_user_id

| Excel | DB | Notes |
|-------|-----|-------|
| Emp ID | `employees.employee_id` (unique string) | `updateOrCreate` key |
| Biometric ID | `device_users.device_user_id` (global, `device_id` null) | Direct map, enables attendance pairing |
| File No | `employees.file_no` | NEW migration, 1.8% filled |
| Name | `employees.first_name`/`last_name`/`full_name` | Split on space |
| Designation | `designations.name` → `employees.designation_id` | auto-create, 105 unique |
| Department Name | `departments.name` → `employees.department_id` | auto-create from Dept sheet, 25 unique |
| NID No | `employees.nid` | raw, dashes kept |
| Blood Group | `employees.blood_group` | normalized: `0/N/A/?/B` → null/B+ |
| Status | `employees.status` | `active→Active`, `resigned→Resigned` (1 resigned) |
| Emergency Contact | `employee_profiles.emergency_contact_phone` | normalizePhone, scientific-notation fix |
| Phone | `employees.phone` | normalizePhone |
| Address | `employees.address` + profiles | |
| DOB / Joining Date | `employees.date_of_birth`/`joining_date` | `Carbon::parse`, excel serial handled |
| Card Issue Date | `employees.card_issue_date` | NEW, 0.6% filled |
| Photo | `employees.photo` | filename only, 52% filled |
| Employee Type | `employees.employment_type` | `fulltime→Permanent` |
| Job Type | `employees.job_type` | NEW, `provisional` etc |
| Joining Salary | `employees.joining_salary` | NEW, 48% filled |
| Allow Overtime/Bonus | `employees.allow_overtime/bonus` | `1→true` |
| Payment Criteria | `employees.payment_criteria` | NEW, `general` |
| Basic Pay | `employee_salaries.basic_salary` | |
| House Rent | `employee_salaries.house_rent` | |
| Medical Allowance | `employee_salaries.medical_allowance` | |
| Conveyance Allowance | `employee_salaries.conveyance_allowance` | NEW, previously `other_allowance` |
| Monthly Salary | `employee_salaries.gross_salary`/`net_salary` | sum = monthly, 100% = basic+house+medical+conveyance |
| Payment Method | `employee_salaries.payment_method` | NEW `cash/bank` |
| Bank Name | `employee_salaries.bank_name` | NEW, 94% filled |
| Bank A/C No | `employee_salaries.bank_account_no` | NEW |
| Created At | `employees.created_at` | from Excel |
| Service Year | `employees.service_year` | NEW, 73% filled |

## New columns (no existing table dropped)

Migration `2026_10_27_100000_add_excel_import_columns.php`:
- `employees`: `file_no`, `card_issue_date`, `job_type`, `joining_salary`, `allow_overtime`, `allow_bonus`, `payment_criteria`, `service_year`
- `employee_salaries`: `conveyance_allowance`, `payment_method`, `bank_name`, `bank_account_no`

CRUD updated:
- `StoreEmployeeRequest`/`UpdateEmployeeRequest` validate all new fields
- `Create.vue`/`Edit.vue`/`Show.vue` — new collapsible **Employment Details** (File No, Card Issue, Job Type, Joining Salary, Service Year, Payment Criteria, Overtime/Bonus flags) + Salary section now shows Conveyance + Payment Method/Bank
- `EmployeeController` edit payload includes new employee fields, Show loads full relations

## Run

```bash
php artisan migrate
php artisan db:seed --class="Modules\HRM\Database\Seeders\RealEmployeeImportSeeder"
# optional custom file:
php artisan db:seed --class="Modules\HRM\Database\Seeders\RealEmployeeImportSeeder" --file=/path/to/employees.xlsx
```

Progress bar + stats, idempotent (`updateOrCreate`), department/designation deduped via cache, phone/excel serial handling, Department sheet imported first.

## Verify

- HRM → Employees Index: 1005 rows
- HRM → Device Users: 1005 global mappings (`device_user_id` ↔ `employee_id`)
- Attendance Logs pairing will resolve via `device_user_id` (F18 `192.168.10.93/.94` test).

## Dependencies

`maatwebsite/excel` already in `composer.json` (`^3.1`) → provides `PhpSpreadsheet` for the seeder.
