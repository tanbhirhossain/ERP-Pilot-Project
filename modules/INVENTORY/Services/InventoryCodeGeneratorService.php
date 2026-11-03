<?php

namespace Modules\INVENTORY\Services;

use Illuminate\Support\Facades\DB;

class InventoryCodeGeneratorService
{
    public function generate(string $prefix): string
    {
        // prefix: TRF, ADJ, SM, CNT, LOT
        $pad = 5;
        $fullPrefix = $prefix.'-';
        $tableMap = [
            'TRF' => ['table' => 'inventory_transfers', 'col' => 'transfer_no'],
            'ADJ' => ['table' => 'inventory_adjustments', 'col' => 'adjustment_no'],
            'SM'  => ['table' => 'inventory_stock_moves', 'col' => 'move_no'],
            'CNT' => ['table' => 'inventory_stock_counts', 'col' => 'count_no'],
            'LOT' => ['table' => 'inventory_lots', 'col' => 'lot_no'],
        ];
        $cfg = $tableMap[$prefix] ?? ['table' => 'inventory_stock_moves', 'col' => 'move_no'];
        return DB::transaction(function () use ($fullPrefix, $pad, $cfg): string {
            $last = DB::table($cfg['table'])->where($cfg['col'], 'like', $fullPrefix.'%')->lockForUpdate()->orderBy('id', 'desc')->first();
            $num = 0;
            if ($last) $num = (int) preg_replace('/\D/', '', substr($last->{$cfg['col']}, strlen($fullPrefix)));
            $next = $fullPrefix.str_pad((string) ($num+1), $pad, '0', STR_PAD_LEFT);
            while (DB::table($cfg['table'])->where($cfg['col'], $next)->exists()) { $num++; $next = $fullPrefix.str_pad((string) ($num+1), $pad, '0', STR_PAD_LEFT); }
            return $next;
        });
    }
}
