<?php
namespace Modules\HRM\Models\Employee;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class EmployeeExperience extends Model {
    use HasFactory;
    protected $guarded = [];
    protected $casts = ['from_date'=>'date','to_date'=>'date','is_current'=>'boolean'];
    protected static function newFactory() { return \Modules\HRM\Database\Factories\EmployeeExperienceFactory::new(); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class, 'employee_id','employee_id'); }
}
