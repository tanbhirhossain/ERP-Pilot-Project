<?php

namespace Modules\CORE\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A company-wide application setting, stored as a key/value row.
 *
 * Item 42 introduces the table with the two keys the colour scheme needs (`ui.scheme`, `ui.mode`)
 * rather than adding columns to a table for every preference that arrives later. Values are text and
 * the caller validates them against a catalogue — see `UiTheme::setCompany()`, which refuses a
 * scheme the front end does not have tokens for, because a stored typo would silently fall back to
 * the default and look like the setting had been ignored.
 *
 * @property int $id
 * @property string $key
 * @property string|null $value
 * @property int|null $updated_by
 */
class CoreSetting extends Model
{
    protected $table = 'core_settings';

    /** @var list<string> */
    protected $guarded = [];

    protected function casts(): array
    {
        return ['updated_by' => 'integer'];
    }

    /** Who last changed it — an audit question, not a security control. */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
