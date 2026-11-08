<?php

namespace Modules\ACCOUNTS\Services;

use Illuminate\Support\Facades\DB;
use Modules\ACCOUNTS\Models\Account;

class AccountCodeGeneratorService
{
    /**
     * Generate next child code under parent. E.g., parent 1000 -> 1001, 1002.
     * If no parent (root), generate 1000, 2000...
     */
    public function nextCode(?int $parentId, string $accountType): string
    {
        return DB::transaction(function () use ($parentId, $accountType): string {
            if ($parentId) {
                $parent = Account::lockForUpdate()->findOrFail($parentId);
                $lastChild = Account::where('parent_id', $parentId)->lockForUpdate()->orderByDesc('code')->value('code');
                if ($lastChild) {
                    // increment last segment: assume parent code is prefix
                    $seq = (int) substr($lastChild, strlen($parent->code));
                    $nextSeq = $seq + 1;

                    return $parent->code.str_pad((string) $nextSeq, 2, '0', STR_PAD_LEFT);
                }

                return $parent->code.'01';
            }
            // root: map type to base 1000,2000...
            $baseMap = ['asset' => '1000', 'liability' => '2000', 'equity' => '3000', 'income' => '4000', 'expense' => '5000'];
            $base = $baseMap[$accountType] ?? '1000';
            $lastRoot = Account::whereNull('parent_id')->where('code', 'like', substr($base, 0, 1).'%')->lockForUpdate()->orderByDesc('code')->value('code');
            if (! $lastRoot) {
                return $base;
            }

            return (string) ((int) $lastRoot + 100);
        });
    }
}
