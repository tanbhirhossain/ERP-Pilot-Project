<?php

namespace Modules\INVENTORY\Requests\Concerns;

/**
 * Drops repeater rows the user never filled in.
 *
 * `ProductRepository::syncRelations()` already skips empty rows, but validation runs first and
 * `variants.*.name` / `suppliers.*.partner_id` are `required_with:variants` / `required_with:suppliers`.
 * The form always seeds one blank row per repeater, so the array key is always present and a plain
 * product with no variants and no supplier links was rejected on fields the user never touched.
 * Pruning here matches what the repository already intends, for every request that uses it.
 */
trait PrunesEmptyRepeaterRows
{
    /**
     * Flags are excluded from the emptiness test: the UI seeds `is_primary => true` on the first
     * blank row, so counting them would keep every row and defeat the prune. A price of 0 is real
     * data, so only null, '' and [] count as empty.
     */
    private const PRUNE_IGNORED_KEYS = ['is_primary', 'is_default', 'is_active', 'can_receive_statement'];

    /** @param list<string> $repeaterKeys */
    protected function pruneEmptyRows(array $repeaterKeys): void
    {
        $merged = [];

        foreach ($repeaterKeys as $key) {
            $rows = $this->input($key);

            if (! is_array($rows)) {
                continue;
            }

            $merged[$key] = array_values(array_filter($rows, function ($row): bool {
                if (! is_array($row)) {
                    return false;
                }

                foreach ($row as $column => $value) {
                    if (in_array($column, self::PRUNE_IGNORED_KEYS, true)) {
                        continue;
                    }

                    if ($value !== null && $value !== '' && $value !== []) {
                        return true;
                    }
                }

                return false;
            }));
        }

        if ($merged !== []) {
            $this->merge($merged);
        }
    }
}
