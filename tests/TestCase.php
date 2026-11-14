<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Feature tests assert server responses, not compiled frontend artifacts.
        $this->withoutVite();
        config(['inertia.testing.ensure_pages_exist' => false]);
    }

    /**
     * Insert rows in slices that stay inside the database's bind-variable limit.
     *
     * `DB::table(...)->insert($rows)` compiles to a single multi-row `INSERT`, and the number of
     * bound values is rows × columns. SQLite's bundled build defaults to
     * SQLITE_MAX_VARIABLE_NUMBER = 32 766 (it was 999 before 3.32), MySQL caps at 65 535 and
     * Postgres at 65 535 parameters; a fixture of a few thousand rows with a dozen columns walks
     * straight into `SQLSTATE[HY000]: General error: 1 too many SQL variables` — a failure that
     * only appears on the platform whose limit is the lowest, which is why it surfaced here and
     * not in CI.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  int  $maxBindings  Bound values allowed per statement.
     * @return int  Rows written.
     */
    protected function insertChunked(string $table, array $rows, int $maxBindings = 20000): int
    {
        if ($rows === []) {
            return 0;
        }

        $columns = max(1, count((array) $rows[0]));
        $rowsPerStatement = max(1, intdiv($maxBindings, $columns));

        $written = 0;

        foreach (array_chunk($rows, $rowsPerStatement) as $slice) {
            $written += DB::table($table)->insert($slice);
        }

        return $written;
    }
}
