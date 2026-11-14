<?php

namespace Modules\PAYROLL\DTO;

final readonly class EmployeePayrollData
{
    public function __construct(
        public string $employeeId,
        public string $name,
        public ?string $department,
        public ?string $designation,
        public ?string $joiningDate,
        public float $basicSalary,
        public float $grossSalary,
        public float $houseRent,
        public float $medicalAllowance,
        public float $otherAllowance,
        public ?string $payGrade,
        public float $prorationFactor = 1.0,
        public int $payableDays = 0,
        public int $daysInPeriod = 0,
        public array $salarySegments = [],
        public array $periodAmounts = [],
        public array $notes = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            employeeId: (string) ($data['employeeId'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            department: isset($data['department']) ? (string) $data['department'] : null,
            designation: isset($data['designation']) ? (string) $data['designation'] : null,
            joiningDate: isset($data['joiningDate']) ? (string) $data['joiningDate'] : null,
            basicSalary: (float) ($data['basicSalary'] ?? 0),
            grossSalary: (float) ($data['grossSalary'] ?? 0),
            houseRent: (float) ($data['houseRent'] ?? 0),
            medicalAllowance: (float) ($data['medicalAllowance'] ?? 0),
            otherAllowance: (float) ($data['otherAllowance'] ?? 0),
            payGrade: isset($data['payGrade']) ? (string) $data['payGrade'] : null,
            prorationFactor: (float) ($data['prorationFactor'] ?? 1),
            payableDays: (int) ($data['payableDays'] ?? 0),
            daysInPeriod: (int) ($data['daysInPeriod'] ?? 0),
            salarySegments: (array) ($data['salarySegments'] ?? []),
            periodAmounts: (array) ($data['periodAmounts'] ?? []),
            notes: (array) ($data['notes'] ?? []),
        );
    }
}
