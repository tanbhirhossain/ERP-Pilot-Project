<?php
namespace Modules\HRM\Models\Employee;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class EmployeeProfile extends Model {
    use HasFactory;
    protected $guarded = [];
    protected static function newFactory() { return \Modules\HRM\Database\Factories\EmployeeProfileFactory::new(); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class, 'employee_id','employee_id'); }
}
