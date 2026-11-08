<?php

namespace Modules\PAYROLL\DTO;

final readonly class AttendancePayrollSummary
{
    /** @param array<string, mixed> $sourceBreakdown */
    public function __construct(
        public float $unpaidLeaveDays = 0,
        public int $lateCount = 0,
        public int $earlyOutCount = 0,
        public int $punishmentDays = 0,
        public bool $punishable = false,
        public int $missingPunchCount = 0,
        public float $approvedOvertimeHours = 0,
        public float $approvedExtraDutyHours = 0,
        public int $dutyHours = 8,
        public array $sourceBreakdown = [],
    ) {}
}
