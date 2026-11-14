<?php

namespace Modules\CORE\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedView extends Model
{
    protected $table = 'core_saved_views';

    protected $fillable = ['user_id', 'table_key', 'name', 'state'];

    protected $casts = ['state' => 'array'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
