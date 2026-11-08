<?php

namespace Modules\PARTNER\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\CORE\Support\Concerns\CoercesNullNumerics;

class PriceListItem extends Model
{
    use CoercesNullNumerics;

    /** NOT NULL numeric columns — `*_id` is excluded on purpose; see the trait. */
    protected function nullToZero(): array
    {
        return ['unit_price', 'min_qty', 'discount_pct'];
    }

    protected $guarded = [];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'discount_pct' => 'decimal:2',
        'min_qty' => 'integer',
    ];

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }
}
