<?php

namespace Modules\HRM\Models\Certificate;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\CORE\Models\User;
use Modules\HRM\Models\Employee\Employee;

class CertificateRequest extends Model
{
    protected $table = 'hrm_certificate_requests';

    protected $guarded = [];

    protected $casts = ['issued_at' => 'datetime'];

    public const TYPES = ['Salary Certificate', 'NOC Certificate', 'Experience Certificate'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function isPending(): bool
    {
        return $this->status === 'Pending';
    }
}
