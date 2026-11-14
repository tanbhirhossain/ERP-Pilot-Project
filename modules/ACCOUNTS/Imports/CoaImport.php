<?php

namespace Modules\ACCOUNTS\Imports;

use Modules\ACCOUNTS\Models\Account;
use Modules\CORE\Imports\ImportContract;

/**
 * Bulk chart-of-accounts take-on (Phase 1 — F5). Upserts by code.
 *
 * parent_code is resolved to parent_id; a blank parent means a root account.
 * is_group/is_posting are derived from the `posting` flag so an operator cannot
 * accidentally create a group that accepts postings.
 */
class CoaImport implements ImportContract
{
    public function key(): string
    {
        return 'coa';
    }

    public function label(): string
    {
        return 'Chart of Accounts';
    }

    public function permission(): string
    {
        return 'accounts.coa.manage';
    }

    public function headers(): array
    {
        return ['code', 'name', 'account_type', 'nature', 'parent_code', 'posting'];
    }

    public function exampleRow(): array
    {
        return ['5600', 'Office Supplies', 'expense', 'debit', '5000', 'yes'];
    }

    public function validateRows(array $rows): array
    {
        $errors = [];
        $seen = [];
        $validTypes = ['asset', 'liability', 'equity', 'income', 'expense'];
        $validNatures = ['debit', 'credit'];

        // Codes present in the file are valid parents even before they exist.
        $fileCodes = [];
        foreach ($rows as $row) {
            $c = trim((string) ($row['code'] ?? ''));
            if ($c !== '') {
                $fileCodes[$c] = true;
            }
        }

        foreach ($rows as $i => $row) {
            $rowErrors = [];
            $code = trim((string) ($row['code'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            $type = strtolower(trim((string) ($row['account_type'] ?? '')));
            $nature = strtolower(trim((string) ($row['nature'] ?? '')));
            $parent = trim((string) ($row['parent_code'] ?? ''));

            if ($code === '') {
                $rowErrors[] = 'code is required';
            } elseif (isset($seen[$code])) {
                $rowErrors[] = "duplicate code \"{$code}\" in file";
            } else {
                $seen[$code] = true;
            }

            if ($name === '') {
                $rowErrors[] = 'name is required';
            }

            if (! in_array($type, $validTypes, true)) {
                $rowErrors[] = 'account_type must be asset|liability|equity|income|expense';
            }

            if (! in_array($nature, $validNatures, true)) {
                $rowErrors[] = 'nature must be debit|credit';
            }

            if ($parent !== '' && $parent === $code) {
                $rowErrors[] = 'an account cannot be its own parent';
            }

            if ($parent !== '' && ! isset($fileCodes[$parent]) && ! Account::where('code', $parent)->exists()) {
                $rowErrors[] = "parent_code \"{$parent}\" does not exist (in file or chart)";
            }

            if ($rowErrors !== []) {
                $errors[$i] = $rowErrors;
            }
        }

        return $errors;
    }

    public function commit(array $rows): int
    {
        $count = 0;

        // Two passes: create/update every account first (parents may come later
        // in the file), then wire parent_id once all codes exist.
        foreach ($rows as $row) {
            $code = trim((string) $row['code']);
            $posting = strtolower(trim((string) ($row['posting'] ?? 'yes')));
            $isPosting = in_array($posting, ['yes', '1', 'true', 'y'], true);

            $account = Account::withTrashed()->where('code', $code)->first();

            $attributes = [
                'name' => trim((string) $row['name']),
                'account_type' => strtolower(trim((string) $row['account_type'])),
                'nature' => strtolower(trim((string) $row['nature'])),
                'is_posting' => $isPosting,
                'is_group' => ! $isPosting,
                'is_active' => true,
            ];

            if ($account !== null) {
                $account->restore();
                $account->update($attributes);
            } else {
                Account::create($attributes + ['code' => $code]);
            }

            $count++;
        }

        // Second pass: resolve parents.
        foreach ($rows as $row) {
            $code = trim((string) $row['code']);
            $parentCode = trim((string) ($row['parent_code'] ?? ''));

            if ($parentCode === '') {
                continue;
            }

            $parent = Account::where('code', $parentCode)->first();
            $account = Account::where('code', $code)->first();

            if ($parent !== null && $account !== null) {
                $account->update(['parent_id' => $parent->id]);
            }
        }

        return $count;
    }
}
