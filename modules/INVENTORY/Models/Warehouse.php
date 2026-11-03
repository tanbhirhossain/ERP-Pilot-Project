<?php

namespace Modules\INVENTORY\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Warehouse extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $table = 'inventory_warehouses';
    protected $guarded = [];
    protected $casts = ['is_default' => 'boolean', 'is_active' => 'boolean', 'allow_negative_stock' => 'boolean'];

    protected static function newFactory(): \Illuminate\Database\Eloquent\Factories\Factory { return \Modules\INVENTORY\Database\Factories\WarehouseFactory::new(); }

    public function manager(): BelongsTo { return $this->belongsTo(\Modules\CORE\Models\User::class, 'manager_user_id'); }
    public function locations(): HasMany { return $this->hasMany(Location::class, 'warehouse_id'); }
    public function stocks(): HasMany { return $this->hasMany(Stock::class, 'warehouse_id'); }
    public function stockMoves(): HasMany { return $this->hasMany(StockMove::class, 'warehouse_id'); }
    public function scopeActive($q) { return $q->where('is_active', true); }
    public function getActivitylogOptions(): LogOptions { return LogOptions::defaults()->useLogName('inventory_warehouse')->logAll()->logOnlyDirty()->setDescriptionForEvent(fn (string $e) => "Warehouse {$e}"); }
}
