<?php

/**
 * NOT NULL numeric columns on line items must never receive an explicit `null`.
 *
 * The bug class: a cleared number input submits `''`, `ConvertEmptyStringsToNull` turns it into
 * `null`, the FormRequest declares the field `nullable` so validation passes, and the INSERT then
 * dies on a NOT NULL constraint — surfacing as a **500**, not a flashed error, because the
 * document controllers catch only `\DomainException` (and `VendorBillController::store()` catches
 * nothing at all).
 *
 * A column's `DEFAULT '0'` does not help: a default applies only when the key is *omitted* from
 * the INSERT, never when `null` is supplied explicitly. That distinction is the whole bug — and it
 * is why the defensive reads inside the seven `enrichTotals()` implementations looked like a fix
 * while only protecting the arithmetic:
 *
 *     $discPct = (float) ($it['discount_pct'] ?? 0);   // maths is safe…
 *     $doc->items()->create($it);                      // …but $it still holds null
 *
 * The fix lives at the persistence layer — `Modules\CORE\Support\Concerns\CoercesNullNumerics`,
 * applied to all 19 line-item models — rather than in each service, because 26 separate write
 * sites exist and most of them never pass through a document service at all: POS, sales returns,
 * deliveries, stock transfers, counts, adjustments, kits and partner price lists.
 *
 * The end-to-end consequences for the two highest-traffic documents are covered by
 * PURCHASE/VendorBillContractTest and SALES/InvoiceContractTest.
 */

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\CORE\Support\Concerns\CoercesNullNumerics;
use Modules\PURCHASE\Services\VendorBillService;
use Modules\SALES\Services\InvoiceService;
use Modules\SALES\Services\OrderService;
use Modules\SALES\Services\QuotationService;

/**
 * Every model that opts into the coercion trait, discovered rather than listed — so a new
 * line-item model that forgets the trait is not silently exempt from these checks either way.
 *
 * @return array<string, array{class-string<Model>}>
 */
function lineItemModels(): array
{
    $found = [];

    // `__DIR__`, not `base_path()`: Pest resolves dataset closures before the application is
    // booted, so the helper would hit `Container::basePath()` and the provider would be invalid.
    $root = dirname(__DIR__, 2);

    foreach (glob($root.'/modules/*/Models/*.php') ?: [] as $file) {
        $relative = substr($file, strlen($root.'/modules/'), -4);
        $class = 'Modules\\'.str_replace('/', '\\', $relative);

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if (! $reflection->isSubclassOf(Model::class) || $reflection->isAbstract()) {
            continue;
        }

        if (! in_array(CoercesNullNumerics::class, class_uses_recursive($class), true)) {
            continue;
        }

        $found[$reflection->getShortName()] = [$class];
    }

    return $found;
}

/** @return list<string> */
function nullToZeroOf(string $class): array
{
    $model = new $class;

    return (new ReflectionMethod($model, 'nullToZero'))->invoke($model);
}

/** @return array<string, array{nullable: bool}> keyed by column name */
function columnsOf(string $table): array
{
    $out = [];

    foreach (Schema::getColumns($table) as $column) {
        $out[$column['name']] = ['nullable' => (bool) $column['nullable']];
    }

    return $out;
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. Coverage: every line-item model is wired, and wired to the right columns.
// ─────────────────────────────────────────────────────────────────────────────

test('the coercion trait is applied to every line-item model', function () {
    $models = lineItemModels();

    // 19 tables end in `_items` and carry a NOT NULL numeric column that is not a key.
    expect($models)->toHaveCount(19)
        ->and($models)->toHaveKeys([
            'VendorBillItem', 'PurchaseOrderItem', 'GoodsReceiptItem', 'PurchaseReturnItem',
            'InvoiceItem', 'OrderItem', 'QuotationItem', 'SalesReturnItem', 'DeliveryItem',
            'PosSaleItem', 'PriceListItem', 'TransferItem', 'AdjustmentItem', 'CountItem',
            'KitItem', 'ChecklistItem', 'PayrollCutlistItem', 'PayrollBankAdviceItem',
            'FestivalBonusItem',
        ]);
});

test('no declared column is nullable, and none is a key', function (string $class) {
    $columns = columnsOf((new $class)->getTable());

    // PHPUnit assertions rather than Pest expectations here: `toHaveKey($key, $value)` takes an
    // expected *value* as its second argument, not a failure message, so the message would be
    // compared against the column array instead of being reported.
    foreach (nullToZeroOf($class) as $name) {
        $this->assertArrayHasKey(
            $name, $columns, "{$class}::nullToZero() names a column its table does not have"
        );

        $this->assertFalse(
            $columns[$name]['nullable'],
            "{$class} coerces {$name}, but that column is nullable — coercing it would destroy a legitimate null"
        );

        // Coercing a null `product_id` to 0 would insert a line pointing at nothing, which is
        // worse than the crash being prevented: the row saves and the damage is invisible.
        $this->assertNotSame('id', $name, "{$class} must never coerce its primary key");

        $this->assertFalse(
            str_ends_with($name, '_id'),
            "{$class} must never coerce the foreign key {$name}"
        );
    }
})->with(fn () => lineItemModels());

test('every NOT NULL numeric-cast column is covered', function (string $class) {
    $model = new $class;
    $columns = columnsOf($model->getTable());
    $declared = nullToZeroOf($class);

    // Derived from the model's own casts rather than from raw SQL type names, so the expectation
    // holds identically on sqlite, MySQL and PostgreSQL.
    $numeric = array_keys(array_filter(
        $model->getCasts(),
        fn ($cast) => (bool) preg_match('/^(int|integer|float|double|real|decimal)/i', (string) $cast)
    ));

    foreach ($numeric as $name) {
        if ($name === 'id' || str_ends_with($name, '_id')) {
            continue;
        }

        if (! array_key_exists($name, $columns) || $columns[$name]['nullable']) {
            continue;
        }

        $this->assertContains(
            $name, $declared,
            "{$class} casts {$name} numerically and its column is NOT NULL, but nullToZero() omits it — ".
            'a cleared input on that field is still a 500'
        );
    }
})->with(fn () => lineItemModels());

// ─────────────────────────────────────────────────────────────────────────────
// 2. Mechanism: what the trait actually does on insert.
// ─────────────────────────────────────────────────────────────────────────────

class LineNumericProbe extends Model
{
    use CoercesNullNumerics;

    protected $table = 'line_numeric_probe_items';

    protected $guarded = [];

    public $timestamps = false;

    protected function nullToZero(): array
    {
        return ['qty', 'discount_pct', 'line_total'];
    }
}

function makeProbeTable(): void
{
    Schema::dropIfExists('line_numeric_probe_items');

    Schema::create('line_numeric_probe_items', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('product_id')->nullable();  // FK-shaped: must never be coerced
        $table->decimal('qty', 10, 2);                         // NOT NULL, no default
        $table->decimal('discount_pct', 5, 2)->default(0);     // NOT NULL, default 0
        $table->decimal('line_total', 12, 2);                  // NOT NULL, no default
    });
}

test('an explicitly null numeric is coerced to zero instead of violating NOT NULL', function () {
    makeProbeTable();

    $row = LineNumericProbe::create([
        'product_id' => null,
        'qty' => 2,
        'discount_pct' => null,   // exactly what a cleared input becomes
        'line_total' => null,     // NOT NULL with no default at all
    ]);

    expect((float) $row->discount_pct)->toBe(0.0)
        ->and((float) $row->line_total)->toBe(0.0)
        // The FK is untouched: coercing it would have hidden a real error.
        ->and($row->product_id)->toBeNull();
});

test('an omitted column still falls through to its database default', function () {
    makeProbeTable();

    // `discount_pct` is absent from the array entirely, so the trait must not write to it and the
    // column's own DEFAULT 0 applies. This is the omitted-vs-explicitly-null distinction.
    $row = LineNumericProbe::create(['qty' => 4, 'line_total' => 40]);

    expect((float) $row->discount_pct)->toBe(0.0)
        ->and((float) $row->qty)->toBe(4.0);
});

test('a real value is never clobbered by the coercion', function () {
    makeProbeTable();

    $row = LineNumericProbe::create(['qty' => 3, 'discount_pct' => 12.5, 'line_total' => 262.5]);

    expect((float) $row->discount_pct)->toBe(12.5)
        ->and((float) $row->line_total)->toBe(262.5)
        ->and((float) $row->qty)->toBe(3.0);
});

test('zero is treated as data, not as an empty value', function () {
    makeProbeTable();

    $row = LineNumericProbe::create(['qty' => 0, 'discount_pct' => 0, 'line_total' => 0]);

    expect((float) $row->qty)->toBe(0.0)
        ->and((float) $row->discount_pct)->toBe(0.0);
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. The arithmetic the coercion sits next to must stay correct.
// ─────────────────────────────────────────────────────────────────────────────

test('enrichTotals still computes the discount-then-VAT totals correctly', function (string $serviceClass) {
    $service = app($serviceClass);
    $method = new ReflectionMethod($service, 'enrichTotals');

    $data = $method->invoke($service, [
        'items' => [
            ['product_id' => 1, 'product_name' => 'A', 'qty' => 2, 'unit_price' => 100.00, 'discount_pct' => 10.0, 'vat_rate' => 5.0],
        ],
    ]);

    // 2 × 100 = 200 gross, less 10% = 180 net, plus 5% VAT = 9 → 189.
    expect((float) $data['subtotal'])->toBe(200.0)
        ->and((float) $data['discount_amount'])->toBe(20.0)
        ->and((float) $data['vat_amount'])->toBe(9.0)
        ->and((float) $data['total_amount'])->toBe(189.0);
})->with([
    'vendor bill' => [VendorBillService::class],
    'invoice' => [InvoiceService::class],
    'sales order' => [OrderService::class],
    'quotation' => [QuotationService::class],
]);
