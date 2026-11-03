<?php

namespace Modules\HRM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChecklistItem extends Model
{
    protected $table = 'hrm_checklist_items';
    protected $guarded = [];
    protected $casts = ['is_required'=>'boolean','sort_order'=>'integer'];
    public function template(): BelongsTo { return $this->belongsTo(ChecklistTemplate::class, 'template_id'); }
}
