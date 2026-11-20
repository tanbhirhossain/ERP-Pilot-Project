<?php

/**
 * The two defects that made the list screens unreadable, pinned so they cannot come back.
 *
 * Both were invisible to every test the suite already had, because both are *layout* facts that
 * only exist in the markup: a table that let one column eat the row, and a header that printed a
 * database enum. A page can return 200 with the right numbers and still look like a spreadsheet of
 * mistakes — which is exactly what happened.
 */

use Illuminate\Support\Facades\File;

function uitShared(string $component): string
{
    $path = base_path('modules/CORE/Resources/js/Components/'.$component);

    expect($path)->toBeFile();

    return (string) file_get_contents($path);
}

/**
 * The component with its comments removed.
 *
 * Every assertion here is about what the markup *does*. A component that explains in a comment why
 * it no longer uppercases its headers must not fail a test that bans the string — the explanation is
 * the evidence, not the defect. The theme contract test strips comments for the same reason.
 */
function uitMarkup(string $component): string
{
    $source = uitShared($component);
    $source = (string) preg_replace('~<!--.*?-->~s', '', $source);
    $source = (string) preg_replace('~^\s*//.*$~m', '', $source);

    return (string) preg_replace('~/\*.*?\*/~s', '', $source);
}

test('the data table distributes its columns instead of letting one eat the row', function () {
    $table = uitShared('DataTable.vue');

    /*
     * Under the browser's automatic table layout, a column is as wide as its widest cell and the
     * row's slack pools wherever the widest cell happens to be. A bill list with "Padma Textile
     * Mills Ltd." in it gave the vendor column 480px and the amount column 96px, with a 500px gap
     * in the middle. `table-fixed` is what makes a declared width mean anything.
     */
    expect($table)->toContain('table-fixed');

    // Every column that declares no width of its own still needs one — `auto` under a fixed layout
    // splits the row evenly between them.
    expect($table)->toContain('columnShare');
});

test('the data table header is left aligned unless the column says otherwise', function () {
    $table = uitShared('DataTable.vue');

    /*
     * `th` centres by default in the UA stylesheet, and this project's preflight does not neutralise
     * it. The header therefore floated mid-column above left-aligned cells on every list page. The
     * fix has to be an explicit, *exclusive* alignment: `text-left` and `text-right` written on the
     * same element resolve by stylesheet order, not by intent, so a money column could show its
     * header over the wrong edge.
     */
    $markup = uitMarkup('DataTable.vue');

    // A ternary picks exactly one; two `&&` guards pick both and leave the winner to the
    // stylesheet's order. That was the bug, and it is what this pins.
    expect($markup)->toContain("? 'text-right'");
    expect($markup)->not->toContain("column.align === 'right' && 'text-right'");
    expect($markup)->not->toContain("'' : 'text-left'");
});

test('the static table stops shouting its headers in uppercase', function () {
    $static = uitMarkup('StaticTable.vue');

    /*
     * Uppercasing an 11px label throws away word shape, wide tracking blurs it further, and the
     * header used a 6px backdrop blur that sampled the row scrolling underneath — so the header
     * literally was blurry. Sentence case, normal tracking, opaque tint.
     */
    expect($static)->not->toContain('text-transform: uppercase');
    expect($static)->not->toContain('backdrop-filter: blur');
});

test('a status badge never prints a raw enum', function () {
    $badge = uitShared('StatusBadge.vue');

    /*
     * `{{ label }}` printed the stored value, so a financial screen showed "partially_paid" with an
     * underscore in it. The label is humanised in the component rather than at 40 call sites.
     */
    expect($badge)->toContain('humanise');
    expect($badge)->not->toContain('{{ label }}');
});

test('the demo seeder ships a fifo-valued product, so the fifo path is exercised', function () {
    $seeder = (string) file_get_contents(base_path('modules/INVENTORY/Database/Seeders/InventoryDemoSeeder.php'));

    /*
     * `inventory_fifo_layers` was empty and the integrity test said so. Weighted-average is the
     * column default, so a catalogue that never sets `valuation_method` never writes a layer, and
     * no test ever walked the FIFO branch of StockService.
     */
    expect($seeder)->toContain("'valuation_method' => 'fifo'");

    // And the FIFO product must not be a second SKU for a garment that already exists: the POS grid
    // showed "Cotton Fabric — Premium White" twice because it was.
    $duplicates = substr_count($seeder, "'Cotton Fabric — Premium White'");
    expect($duplicates)->toBe(1, 'the demo catalogue has two products with the same name again');
});
