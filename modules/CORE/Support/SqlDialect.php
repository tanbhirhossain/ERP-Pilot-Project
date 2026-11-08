<?php

namespace Modules\CORE\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Portable SQL fragments for expressions that differ between database engines.
 *
 * Reports group rows by calendar month. SQLite spells that `strftime('%Y-%m', col)`,
 * MySQL spells it `DATE_FORMAT(col, '%Y-%m')`, and neither understands the other.
 * Hard-coding one dialect silently breaks the report the moment the project moves
 * from the SQLite dev database to the MySQL production database (see DEPLOY.md).
 *
 * Every fragment returned here is built from a column name that the caller
 * controls (never user input), so it is safe to inline into `selectRaw` / `groupBy`.
 */
final class SqlDialect
{
    /**
     * SQL expression that renders `$column` as a `YYYY-MM` string.
     *
     * @throws RuntimeException when the active connection has no known mapping
     */
    public static function yearMonth(string $column): string
    {
        self::assertSafeIdentifier($column);

        return match (self::driver()) {
            'sqlite' => "strftime('%Y-%m', {$column})",
            'mysql', 'mariadb' => "DATE_FORMAT({$column}, '%Y-%m')",
            'pgsql' => "to_char({$column}, 'YYYY-MM')",
            'sqlsrv' => "FORMAT({$column}, 'yyyy-MM')",
            default => throw new RuntimeException(
                'SqlDialect::yearMonth() has no mapping for driver "'.self::driver().'". '
                .'Add one rather than falling back to a dialect-specific literal.'
            ),
        };
    }

    /**
     * SQL expression that renders `$column` as a `YYYY-MM-DD` string.
     */
    public static function day(string $column): string
    {
        self::assertSafeIdentifier($column);

        return match (self::driver()) {
            'sqlite' => "strftime('%Y-%m-%d', {$column})",
            'mysql', 'mariadb' => "DATE_FORMAT({$column}, '%Y-%m-%d')",
            'pgsql' => "to_char({$column}, 'YYYY-MM-DD')",
            'sqlsrv' => "FORMAT({$column}, 'yyyy-MM-dd')",
            default => throw new RuntimeException(
                'SqlDialect::day() has no mapping for driver "'.self::driver().'".'
            ),
        };
    }

    public static function driver(): string
    {
        return strtolower((string) DB::connection()->getDriverName());
    }

    /**
     * Only allow plain identifiers and qualified `table.column` / `alias.column`
     * references, optionally wrapped in parentheses-free function-free form.
     * This keeps the fragment safe to interpolate into raw SQL.
     */
    private static function assertSafeIdentifier(string $column): void
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $column)) {
            throw new RuntimeException(
                "SqlDialect refuses to interpolate '{$column}' into raw SQL: "
                .'expected a column name or table.column reference.'
            );
        }
    }
}
