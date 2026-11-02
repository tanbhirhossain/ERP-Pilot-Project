<?php
namespace Modules\HRM\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Department extends Model {
    use HasFactory;
    protected $guarded = [];
    protected $casts = ['is_active'=>'boolean'];
    protected static function newFactory() { return \Modules\HRM\Database\Factories\DepartmentFactory::new(); }
    public function parent(): BelongsTo { return $this->belongsTo(Department::class, 'parent_id'); }
    public function children(): HasMany { return $this->hasMany(Department::class, 'parent_id'); }
    public function employees(): HasMany { return $this->hasMany(\Modules\HRM\Models\Employee\Employee::class, 'department_id'); }
    public function designations(): HasMany { return $this->hasMany(Designation::class); }
}
