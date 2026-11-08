<?php

namespace Modules\PAYROLL\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxSlab extends Model
{
    protected $table = 'tax_slabs';

    protected $guarded = [];

    protected $casts = ['lower_limit' => 'decimal:2', 'upper_limit' => 'decimal:2', 'rate_percent' => 'decimal:4'];

    public function configuration(): BelongsTo
    {
        return $this->belongsTo(TaxConfiguration::class, 'tax_configuration_id');
    }
}
