<?php

namespace Modules\PARTNER\Services;

use Illuminate\Support\Facades\DB;
use Modules\PARTNER\Models\Partner;

class PartnerCodeGeneratorService
{
    public function generate(string $type): string
    {
        $config = config('partner.code_prefix', ['vendor' => 'VEN-', 'customer' => 'CUS-', 'both' => 'PAR-']);
        $pad = (int) config('partner.code_pad', 4);

        $prefix = match ($type) {
            'vendor' => $config['vendor'] ?? 'VEN-',
            'customer' => $config['customer'] ?? 'CUS-',
            'both' => $config['both'] ?? 'PAR-',
            default => 'PAR-',
        };

        return DB::transaction(function () use ($prefix, $pad): string {
            $last = Partner::where('partner_code', 'like', $prefix.'%')
                ->lockForUpdate()
                ->orderBy('id', 'desc')
                ->first();

            $num = 0;
            if ($last) {
                $num = (int) preg_replace('/\D/', '', substr($last->partner_code, strlen($prefix)));
            }

            $next = $prefix.str_pad((string) ($num + 1), $pad, '0', STR_PAD_LEFT);

            while (Partner::where('partner_code', $next)->exists()) {
                $num++;
                $next = $prefix.str_pad((string) ($num + 1), $pad, '0', STR_PAD_LEFT);
            }

            return $next;
        });
    }
}
