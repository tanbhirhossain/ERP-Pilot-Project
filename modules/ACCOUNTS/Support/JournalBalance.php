<?php

namespace Modules\ACCOUNTS\Support;

use DomainException;

/**
 * The one definition of "this journal is balanced".
 *
 * Three places used to decide that independently, and they disagreed:
 *
 *   - `JournalService::assertBalanced()` accepted any difference up to **0.01**;
 *   - `StoreJournalRequest::withValidator()` accepted the same 0.01, computed separately;
 *   - `Journal::isBalanced()` — which is what `post()` gates on — demanded **exact** agreement at two
 *     decimal places, via `bccomp(..., 2)`.
 *
 * The gap between a 0.01 tolerance and an exact 2dp comparison is a journal that is accepted as a
 * draft and can then never be posted: the sides differ by less than the create-time tolerance but
 * round to different cents, so `post()` refuses it forever with "Journal not balanced" on a journal
 * the system had already declared balanced. Sub-cent line amounts are not a typing mistake — they are
 * what an unrounded unit price times a quantity, a VAT split or a discount allocation produces, which
 * is the exposure P1.2 (money stored as float) describes.
 *
 * The rule here is the one that matches the storage: **compare the two sides after rounding to the
 * currency's two decimal places.** That rejects anything which would store as a different number of
 * cents, and accepts binary-float noise from summing lines (33.33 + 33.33 + 33.34) which the old
 * tolerance also accepted, so no legitimate journal starts failing.
 *
 * Lines arrive as arrays from the web form and DTO, and as `JournalLine` models from a loaded
 * relation, so both shapes are read.
 */
final class JournalBalance
{
    /**
     * Slack allowed between two values that have already been rounded to cents. Not a business
     * tolerance — a genuine cent difference is 0.01, so this only absorbs representation error in
     * the rounded floats themselves.
     */
    private const EPSILON = 0.0001;

    private function __construct() {}

    /**
     * Sum both sides of a set of lines.
     *
     * @param  iterable<int, array<string, mixed>|object>  $lines
     * @return array{debit: float, credit: float}
     */
    public static function sums(iterable $lines): array
    {
        $debit = 0.0;
        $credit = 0.0;

        foreach ($lines as $line) {
            $debit += self::side($line, 'debit');
            $credit += self::side($line, 'credit');
        }

        return ['debit' => $debit, 'credit' => $credit];
    }

    /**
     * @param  iterable<int, array<string, mixed>|object>  $lines
     */
    public static function isBalanced(iterable $lines): bool
    {
        $sums = self::sums($lines);

        return abs(round($sums['debit'], 2) - round($sums['credit'], 2)) <= self::EPSILON;
    }

    /**
     * @param  iterable<int, array<string, mixed>|object>  $lines
     *
     * @throws DomainException
     */
    public static function assertBalanced(iterable $lines): void
    {
        $sums = self::sums($lines);
        $debit = round($sums['debit'], 2);
        $credit = round($sums['credit'], 2);

        if (abs($debit - $credit) > self::EPSILON) {
            throw new DomainException(sprintf(
                'Journal not balanced: debit %s != credit %s',
                number_format($debit, 2, '.', ''),
                number_format($credit, 2, '.', ''),
            ));
        }

        if ($debit === 0.0) {
            throw new DomainException('Journal total cannot be zero.');
        }
    }

    /**
     * Header totals derived from the lines, so the stored header can never disagree with the rows
     * that were actually written. Rounded to cents: these land in `decimal:2` columns.
     *
     * @param  iterable<int, array<string, mixed>|object>  $lines
     * @return array{total_debit: float, total_credit: float}
     */
    public static function totals(iterable $lines): array
    {
        $sums = self::sums($lines);

        return [
            'total_debit' => round($sums['debit'], 2),
            'total_credit' => round($sums['credit'], 2),
        ];
    }

    /**
     * Read one side of one line, from either shape.
     *
     * @param  array<string, mixed>|object  $line
     */
    private static function side(array|object $line, string $key): float
    {
        $value = is_array($line) ? ($line[$key] ?? 0) : ($line->{$key} ?? 0);

        return (float) ($value ?? 0);
    }
}
