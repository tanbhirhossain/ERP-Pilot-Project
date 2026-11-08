<?php

namespace Modules\CORE\Support\Concerns;

/**
 * Keeps NOT NULL numeric columns from ever reaching the database as an explicit `null`.
 *
 * The bug class this exists for: a cleared number input submits `''`, the
 * `ConvertEmptyStringsToNull` middleware turns it into `null`, the FormRequest declares the
 * field `nullable` so validation passes, and the insert then dies on a NOT NULL constraint —
 * surfacing as a 500 rather than a flashed error, because the document controllers catch only
 * `\DomainException` (or nothing at all).
 *
 * A column's `DEFAULT '0'` does **not** help: a default applies only when the key is *omitted*
 * from the INSERT, never when `null` is supplied explicitly. That distinction is the whole bug,
 * and it is why defensive reads elsewhere in the code — `(float) ($it['discount_pct'] ?? 0)` —
 * looked like a fix while only protecting the arithmetic, not the persisted row.
 *
 * This is deliberately the *persistence* layer rather than a per-service normalisation step, so
 * that it also covers write paths no form contract passes through: POS, sales returns and
 * deliveries, stock transfers, counts, adjustments, kits and partner price lists.
 */
trait CoercesNullNumerics
{
    /**
     * NOT NULL numeric columns on this model's table. Override per model.
     *
     * This is a method rather than a property on purpose: PHP rejects a trait property that a
     * using class redefines with a different initial value ("the definition differs and is
     * considered incompatible"), which is a fatal error at class-composition time — invisible to
     * `php -l`, and it would take down every model using the trait.
     *
     * `id` and every `*_id` are excluded on purpose: coercing a null `product_id` to `0` would
     * quietly insert a line pointing at nothing, which is worse than the crash being prevented.
     *
     * @return list<string>
     */
    protected function nullToZero(): array
    {
        return [];
    }

    public static function bootCoercesNullNumerics(): void
    {
        static::creating(function ($model): void {
            $attributes = $model->getAttributes();

            foreach ($model->nullToZero() as $column) {
                // `array_key_exists` is load-bearing: an *omitted* column must still fall through
                // to its database default. Only a present-but-null value is the violation.
                if (array_key_exists($column, $attributes) && $attributes[$column] === null) {
                    $model->setAttribute($column, 0);
                }
            }
        });
    }
}
