<?php

namespace Tests\Feature\ACCOUNTS;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The journal line row: one line, one row of fields, in both the create and the edit page.
 *
 * Reported by users as "the input field of a line should be one inline — Account, Memo, Debit, Credit".
 * The block used to be `sm:grid-cols-2 xl:grid-cols-4` with the Memo field carrying `span="full"`, which
 * meant four things never shared a row: below 1280px the fields were a two-by-two stack, and at 1280px and
 * above the memo wrapped onto a second line under the money. That is not a preference someone should have
 * to work around when typing an entry, and it is the kind of thing a later "tidy up" quietly re-stacks.
 *
 * So the contract here is deliberately about the *shape* rather than the pixels: the row grid, its column
 * template, the heading row that mirrors it, the order of the four fields, and — the part no browser test
 * would catch — that the switch is a container query. The form sits in a shell whose sidebar collapses to
 * an icon rail, which widens the content area without the window changing size; a viewport breakpoint is
 * simply the wrong instrument there.
 */
class JournalLineRowTest extends TestCase
{
    private const GRID = '@3xl:grid-cols-[1.9rem_minmax(0,1.15fr)_minmax(0,1.1fr)_minmax(0,0.7fr)_minmax(0,0.7fr)]';

    private const ORDER = ['Account', 'Memo', 'Debit', 'Credit'];

    /**
     * @return array<string, array{0: string}>
     */
    public static function pageProvider(): array
    {
        return [
            'create' => ['modules/ACCOUNTS/Resources/js/Pages/Journals/Create.vue'],
            'edit' => ['modules/ACCOUNTS/Resources/js/Pages/Journals/Edit.vue'],
        ];
    }

    #[DataProvider('pageProvider')]
    public function test_the_four_fields_share_one_row_on_the_row_grid(string $path): void
    {
        $row = $this->lineRow($path);

        $this->assertStringContainsString('@3xl:items-start', $row, 'the cells are not top-aligned, so an error line under one field drags the others');
        $this->assertStringContainsString(self::GRID, $row);
        $this->assertStringNotContainsString('xl:grid-cols-4', $row, 'the old viewport grid is back');
        $this->assertStringNotContainsString('sm:grid-cols-2', $row, 'a second viewport breakpoint would fight the container query');

        // The four fields, in the order the request asked for, all inside the same grid element.
        $previous = -1;

        foreach (self::ORDER as $label) {
            $at = strpos($row, 'label="'.$label.'"');
            $this->assertNotFalse($at, 'the row has no '.$label.' field');
            $this->assertGreaterThan(
                $previous,
                $at,
                'the fields must read left to right as Account, Memo, Debit, Credit — `'.$label.'` is out of place'
            );
            $previous = $at;
        }
    }

    #[DataProvider('pageProvider')]
    public function test_every_cell_hides_its_label_only_where_the_heading_row_covers_it(string $path): void
    {
        $row = $this->lineRow($path);

        // Four labels at @3xl would duplicate the heading row; none at all below it would leave a field
        // with nothing to read. `FormField` takes the responsive hide through `label-class`, which keeps the
        // label in the accessibility tree either way.
        $this->assertSame(4, substr_count($row, 'label-class="@3xl:sr-only"'), 'each of the four cells needs the responsive label treatment');
        $this->assertStringNotContainsString('hideLabel', $row, 'an unconditional hide would strip the labels from the stacked layout too');
    }

    #[DataProvider('pageProvider')]
    public function test_the_heading_row_mirrors_the_rows_and_both_share_the_card_padding(string $path): void
    {
        $block = $this->lineBlock($path);
        $start = strpos($block, 'v-for="(line, i) in form.lines"');
        $this->assertNotFalse($start, 'the heading row is no longer above the line cards, so the two cannot be compared');
        $head = substr($block, 0, $start);

        $this->assertStringContainsString(self::GRID, $head, 'the headings no longer use the row template, so they drift off their columns');
        $this->assertStringContainsString('@3xl:px-3 @3xl:pr-11', $head, 'the headings are not inset like the card, so the columns cannot line up');
        $this->assertStringContainsString('p-3 transition', $head.$block, 'the card padding changed and the heading inset is now wrong');
        $this->assertStringContainsString('@3xl:pr-11', $block, 'the card must reserve the remove button the headings reserve too');

        // Both hidden from screen readers as a duplicate of the visible label would be wrong: the heading
        // row is decoration for the aligned columns, the fields keep their own labels.
        $this->assertStringContainsString('<span aria-hidden="true" />', $head);
        $this->assertMatchesRegularExpression('/<p class="[^"]*">Account<\/p>/', $head);
    }

    #[DataProvider('pageProvider')]
    public function test_the_row_is_measured_against_its_own_container(string $path): void
    {
        $src = $this->read($path);

        $this->assertStringContainsString(
            '<div class="@container space-y-3">',
            $src,
            'the lines list is not a query container, so `@3xl:` would resolve against the window again'
        );
    }

    public function test_create_and_edit_carry_the_same_row_so_neither_can_quietly_drift(): void
    {
        $this->assertSame(
            $this->lineRow('modules/ACCOUNTS/Resources/js/Pages/Journals/Create.vue'),
            $this->lineRow('modules/ACCOUNTS/Resources/js/Pages/Journals/Edit.vue'),
            'the two halves of one form diverged — the row is the same work in both, and only one of them gets fixed otherwise'
        );
    }

    public function test_the_side_pill_stays_but_its_amount_does_not(): void
    {
        $row = $this->lineRow('modules/ACCOUNTS/Resources/js/Pages/Journals/Create.vue');

        // In the gutter there is room for `DR`, not for `DR 1,000.00`, and inline the amount is already in
        // the Debit field two columns over. Below the breakpoint the amount comes back, because there the
        // pill is the only summary of the line.
        $this->assertStringContainsString('@3xl:hidden', $row);
        $this->assertStringContainsString("Number(line.debit) > 0 ? 'DR' : 'CR'", $row);
        $this->assertMatchesRegularExpression('/@3xl:hidden">\s*\{\{ \(Number\(line\.debit\)/', $row);
    }

    // -------------------------------------------------------------------------- helpers

    /** The row element: from the card that opens it to the field grid's own opening tag. */
    private function lineRow(string $path): string
    {
        $block = $this->lineBlock($path);
        $start = strpos($block, 'v-for="(line, i) in form.lines"');

        $this->assertNotFalse($start, 'the journal lines are not rendered by a v-for over form.lines any more');

        return substr($block, $start, min(strlen($block), 6000));
    }

    /** Everything between the heading row and the "Add line" button below the list. */
    private function lineBlock(string $path): string
    {
        $src = $this->read($path);
        $start = strpos($src, '<div class="hidden @3xl:grid');
        $end = strpos($src, '<div class="flex flex-wrap items-center gap-2 pt-1">');

        $this->assertNotFalse($start, 'the heading row for the journal lines is gone');
        $this->assertNotFalse($end, 'the journal line block cannot be located');
        $this->assertGreaterThan($start, $end);

        return substr($src, $start, $end - $start);
    }

    private function read(string $path): string
    {
        $full = base_path($path);
        $this->assertFileExists($full);

        return (string) file_get_contents($full);
    }
}
