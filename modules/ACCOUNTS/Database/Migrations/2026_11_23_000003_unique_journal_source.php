<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One source document, one journal — enforced by the database.
 *
 * Every posting guard is check-then-insert: the service reads "not posted yet" and then writes
 * the journal. Two closes of the same POS drawer, or a double-clicked Post button, can both pass
 * the check before either writes — and on SQLite the row locks that would serialize them are
 * no-ops. The loser must hit this wall instead of writing a second journal: its insert fails,
 * its transaction rolls back, and the day posts exactly once.
 *
 * Manual journals carry no source (NULL), and NULLs never conflict, so they are unaffected.
 * Verified against the live dev database before writing: no two journals share a source.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Belt and braces: refuse to migrate a database that already double-posted, naming the
        // offenders, rather than crashing inside CREATE UNIQUE INDEX.
        $dups = DB::table('acc_journals')
            ->whereNotNull('source_type')
            ->groupBy('source_type', 'source_id')
            ->havingRaw('COUNT(*) > 1')
            ->selectRaw('source_type, source_id, COUNT(*) AS n')
            ->get();

        if ($dups->isNotEmpty()) {
            $list = $dups->map(fn ($d) => "{$d->source_type}#{$d->source_id} ({$d->n})")->implode(', ');
            throw new \RuntimeException("Cannot add the one-journal-per-source constraint: already posted twice: {$list}.");
        }

        $indexes = collect(Schema::getIndexes('acc_journals'))->mapWithKeys(fn ($i) => [strtolower($i['name']) => true]);

        Schema::table('acc_journals', function (Blueprint $t) use ($indexes): void {
            if ($indexes->has('acc_journals_source_type_source_id_index')) {
                $t->dropIndex('acc_journals_source_type_source_id_index');
            }
            if (! $indexes->has('acc_journals_source_unique')) {
                $t->unique(['source_type', 'source_id'], 'acc_journals_source_unique');
            }
        });
    }

    public function down(): void
    {
        $indexes = collect(Schema::getIndexes('acc_journals'))->mapWithKeys(fn ($i) => [strtolower($i['name']) => true]);

        Schema::table('acc_journals', function (Blueprint $t) use ($indexes): void {
            if ($indexes->has('acc_journals_source_unique')) {
                $t->dropUnique('acc_journals_source_unique');
            }
            if (! $indexes->has('acc_journals_source_type_source_id_index')) {
                $t->index(['source_type', 'source_id'], 'acc_journals_source_type_source_id_index');
            }
        });
    }
};
