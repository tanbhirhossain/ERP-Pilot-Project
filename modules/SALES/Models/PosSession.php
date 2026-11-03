<?php

namespace Modules\SALES\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosSession extends Model
{
    protected $table = 'pos_sessions';
    protected $fillable = ['session_no', 'warehouse_id', 'opened_by', 'closed_by', 'opening_cash', 'closing_cash', 'expected_cash', 'cash_difference', 'total_sales', 'transactions_count', 'status', 'opened_at', 'closed_at', 'notes'];
    protected $casts = ['opening_cash' => 'decimal:2', 'total_sales' => 'decimal:2', 'opened_at' => 'datetime', 'closed_at' => 'datetime'];

    public function warehouse(): BelongsTo { return $this->belongsTo(\Modules\INVENTORY\Models\Warehouse::class, 'warehouse_id'); }
    public function opener(): BelongsTo { return $this->belongsTo(\App\Models\User::class, 'opened_by'); }
    public function sales(): HasMany { return $this->hasMany(PosSale::class, 'session_id'); }

    public function isOpen(): bool { return $this->status === 'open'; }
}
