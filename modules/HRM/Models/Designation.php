<?php
namespace Modules\HRM\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Designation extends Model {
    use HasFactory;
    protected $guarded = [];
    protected $casts = ['is_active'=>'boolean'];
    protected static function newFactory() { return \Modules\HRM\Database\Factories\DesignationFactory::new(); }
    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
    public function employees(): HasMany { return $this->hasMany(\Modules\HRM\Models\Employee\Employee::class, 'designation_id'); }
}
