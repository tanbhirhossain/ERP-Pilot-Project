<?php

namespace Modules\ACCOUNTS\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Account extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $table = 'acc_accounts';

    protected $fillable = [
        'code', 'name', 'name_bn', 'slug', 'account_type', 'nature', 'parent_id', 'level', 'path',
        'is_group', 'is_posting', 'is_system', 'is_bank', 'is_cash', 'is_reconcile', 'currency',
        'opening_balance', 'opening_side', 'description', 'is_active', 'sort_order', 'created_by',
    ];

    protected $casts = [
        'is_group' => 'boolean', 'is_posting' => 'boolean', 'is_system' => 'boolean',
        'is_bank' => 'boolean', 'is_cash' => 'boolean', 'is_reconcile' => 'boolean',
        'is_active' => 'boolean', 'opening_balance' => 'decimal:2', 'level' => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('code');
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'account_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(AccountLedger::class, 'account_id');
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    public function scopePosting($q)
    {
        return $q->where('is_posting', true);
    }

    public function scopeGroups($q)
    {
        return $q->where('is_group', true);
    }

    public function scopeRoots($q)
    {
        return $q->whereNull('parent_id');
    }

    public function scopeOfType($q, string $type)
    {
        return $q->where('account_type', $type);
    }

    public function isGroup(): bool
    {
        return (bool) $this->is_group;
    }

    public function isPostingAccount(): bool
    {
        return (bool) $this->is_posting && ! $this->is_group;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['code', 'name', 'account_type', 'parent_id', 'is_active'])->logOnlyDirty()->useLogName('accounts');
    }

    protected static function booted(): void
    {
        static::saving(function (self $m) {
            $m->slug = \Str::slug($m->code.'-'.$m->name);
            if ($m->parent_id) {
                $parent = self::find($m->parent_id);
                $m->level = $parent ? $parent->level + 1 : 1;
                $m->path = $parent ? trim($parent->path.'/'.$parent->id, '/') : (string) $m->parent_id;
            } else {
                $m->level = 1;
                $m->path = null;
            }
            $m->is_posting = ! $m->is_group;
        });
    }
}
