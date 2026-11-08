<?php

namespace Modules\INVENTORY\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\CORE\Models\User;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Transfer extends Model
{
    use LogsActivity, SoftDeletes;

    protected $table = 'inventory_transfers';

    protected $guarded = [];

    protected $casts = ['shipped_at' => 'datetime', 'received_at' => 'datetime'];

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TransferItem::class, 'transfer_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function canShip(): bool
    {
        return in_array($this->status, ['draft', 'pending'], true);
    }

    public function canReceive(): bool
    {
        return $this->status === 'in_transit';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('inventory_transfer')->logAll()->logOnlyDirty()->setDescriptionForEvent(fn (string $e) => "Transfer {$e}");
    }
}
