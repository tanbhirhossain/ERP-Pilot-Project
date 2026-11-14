<?php

namespace Modules\CORE\Services;

use Illuminate\Support\Facades\DB;
use Modules\CORE\Imports\ImportContract;
use Modules\CORE\Imports\SpreadsheetReader;

/**
 * The import orchestrator (Phase 1 — F5).
 *
 * Two-phase by design: `preview()` parses + validates and writes nothing, so the
 * operator sees exactly which rows will fail before any data lands. `commit()`
 * re-validates (the file may have changed) and then persists inside one
 * transaction — a single bad row rolls the whole batch back, never a partial
 * import that leaves the ledger half-populated.
 *
 * Importers are resolved from the container by key, so adding an entity is a
 * binding + a class, not a change here (Open/Closed).
 */
class ImportService
{
    /** @var array<string, class-string<ImportContract>> */
    private array $importers = [];

    /** @param array<string, class-string<ImportContract>> $importers */
    public function __construct(array $importers = [])
    {
        $this->importers = $importers;
    }

    public function register(string $key, string $class): void
    {
        $this->importers[$key] = $class;
    }

    /** @return array<string, ImportContract> */
    public function all(): array
    {
        $out = [];
        foreach ($this->importers as $key => $class) {
            $out[$key] = app($class);
        }

        return $out;
    }

    public function get(string $key): ImportContract
    {
        if (! isset($this->importers[$key])) {
            throw new \DomainException("Unknown import type \"{$key}\".");
        }

        return app($this->importers[$key]);
    }

    /**
     * Parse + validate, write nothing.
     *
     * @return array{rows: int, errors: array<int, list<string>>, valid: bool, preview: list<array<string,mixed>>}
     */
    public function preview(string $key, string $path): array
    {
        $importer = $this->get($key);
        $rows = SpreadsheetReader::read($path);

        if ($rows === []) {
            return ['rows' => 0, 'errors' => [], 'valid' => false, 'preview' => [], 'message' => 'The file has no data rows.'];
        }

        $errors = $importer->validateRows($rows);

        return [
            'rows' => count($rows),
            'errors' => $errors,
            'valid' => $errors === [],
            'preview' => array_slice($rows, 0, 10),
        ];
    }

    /**
     * Validate then persist in one transaction.
     *
     * @return array{imported: int, rows: int}
     */
    public function commit(string $key, string $path): array
    {
        $importer = $this->get($key);
        $rows = SpreadsheetReader::read($path);

        if ($rows === []) {
            throw new \DomainException('The file has no data rows.');
        }

        $errors = $importer->validateRows($rows);

        if ($errors !== []) {
            $first = array_key_first($errors);
            throw new \DomainException(sprintf(
                'Import refused: %d row(s) have errors. First, row %d: %s',
                count($errors),
                $first + 1,
                implode('; ', $errors[$first]),
            ));
        }

        $imported = DB::transaction(fn (): int => $importer->commit($rows));

        return ['imported' => $imported, 'rows' => count($rows)];
    }
}
