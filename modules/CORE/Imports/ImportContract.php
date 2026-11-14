<?php

namespace Modules\CORE\Imports;

/**
 * One importable entity (products, partners, COA, opening stock).
 *
 * The framework parses the spreadsheet once, hands each importer the raw rows
 * for validation (dry-run), then calls commit() with the same rows inside a
 * transaction. Importers never touch the request or the session — they are pure
 * row-in / result-out, so they can be unit-tested without HTTP.
 */
interface ImportContract
{
    /** Machine key used in the URL, e.g. "products". */
    public function key(): string;

    /** Human label for the UI. */
    public function label(): string;

    /** Permission required to run this import. */
    public function permission(): string;

    /**
     * Column headers for the downloadable template, in order.
     *
     * @return list<string>
     */
    public function headers(): array;

    /**
     * One example row for the template (same order as headers).
     *
     * @return list<string|float|int>
     */
    public function exampleRow(): array;

    /**
     * Validate rows without writing. Returns per-row errors keyed by row number
     * (1-based, excluding the header). An empty array means every row is valid.
     *
     * @param  list<array<string,mixed>>  $rows  keyed by normalized header
     * @return array<int, list<string>>
     */
    public function validateRows(array $rows): array;

    /**
     * Persist the rows. Called inside a DB transaction, only after validateRows
     * returned no errors. Returns the number of records created/updated.
     *
     * @param  list<array<string,mixed>>  $rows
     */
    public function commit(array $rows): int;
}
