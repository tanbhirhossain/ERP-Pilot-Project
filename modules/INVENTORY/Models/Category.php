<?php

namespace Modules\INVENTORY\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Category extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $table = 'inventory_categories';
    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];

    protected static function newFactory(): \Illuminate\Database\Eloquent\Factories\Factory { return \Modules\INVENTORY\Database\Factories\CategoryFactory::new(); }

    public function parent(): BelongsTo { return $this->belongsTo(self::class, 'parent_id'); }
    public function children(): HasMany { return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name'); }
    public function products(): HasMany { return $this->hasMany(Product::class, 'category_id'); }
    public function scopeActive($q) { return $q->where('is_active', true); }
    public function getActivitylogOptions(): LogOptions { return LogOptions::defaults()->useLogName('inventory_category')->logAll()->logOnlyDirty()->setDescriptionForEvent(fn (string $e) => "Category {$e}"); }
}
