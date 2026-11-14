<?php

namespace Modules\PARTNER\Imports;

use Modules\CORE\Imports\ImportContract;
use Modules\PARTNER\Models\Partner;

/**
 * Bulk vendor/customer take-on (Phase 1 — F5). Upserts by partner_code.
 *
 * Permission: held by anyone who may create either side of the partner book.
 * The route gate checks the union (vendors.create OR customers.create) because
 * a mixed file legitimately contains both.
 */
class PartnerImport implements ImportContract
{
    public function key(): string
    {
        return 'partners';
    }

    public function label(): string
    {
        return 'Vendors & Customers';
    }

    public function permission(): string
    {
        return 'partner.vendors.create';
    }

    public function headers(): array
    {
        return ['partner_code', 'name', 'type', 'phone', 'email', 'credit_limit', 'payment_terms_days', 'bin', 'tin'];
    }

    public function exampleRow(): array
    {
        return ['CUS-0001', 'Apex Retail Ltd', 'customer', '01711000000', 'apex@example.com', 100000, 30, '', ''];
    }

    public function validateRows(array $rows): array
    {
        $errors = [];
        $seen = [];

        foreach ($rows as $i => $row) {
            $rowErrors = [];
            $code = trim((string) ($row['partner_code'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            $type = strtolower(trim((string) ($row['type'] ?? '')));

            if ($code === '') {
                $rowErrors[] = 'partner_code is required';
            } elseif (isset($seen[$code])) {
                $rowErrors[] = "duplicate partner_code \"{$code}\" in file";
            } else {
                $seen[$code] = true;
            }

            if ($name === '') {
                $rowErrors[] = 'name is required';
            }

            if (! in_array($type, ['vendor', 'customer', 'both'], true)) {
                $rowErrors[] = "type must be vendor|customer|both (got \"{$type}\")";
            }

            $email = trim((string) ($row['email'] ?? ''));
            if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $rowErrors[] = "email \"{$email}\" is not valid";
            }

            foreach (['credit_limit', 'payment_terms_days'] as $num) {
                $v = $row[$num] ?? null;
                if ($v !== null && $v !== '' && ! is_numeric($v)) {
                    $rowErrors[] = "{$num} must be a number";
                }
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

        foreach ($rows as $row) {
            $code = trim((string) $row['partner_code']);

            $partner = Partner::withTrashed()->where('partner_code', $code)->first();

            $attributes = [
                'name' => trim((string) $row['name']),
                'type' => strtolower(trim((string) $row['type'])),
                'phone' => trim((string) ($row['phone'] ?? '')) ?: null,
                'email' => trim((string) ($row['email'] ?? '')) ?: null,
                'credit_limit' => (float) ($row['credit_limit'] ?? 0),
                'payment_terms_days' => (int) ($row['payment_terms_days'] ?? 0),
                'bin' => trim((string) ($row['bin'] ?? '')) ?: null,
                'tin' => trim((string) ($row['tin'] ?? '')) ?: null,
            ];

            if ($partner !== null) {
                $partner->restore();
                $partner->update($attributes);
            } else {
                Partner::create($attributes + ['partner_code' => $code, 'is_active' => true]);
            }

            $count++;
        }

        return $count;
    }
}
