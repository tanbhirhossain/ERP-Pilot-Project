<?php

namespace Modules\ACCOUNTS\DTO;

readonly class AccountData
{
    public function __construct(
        public string $code,
        public string $name,
        public string $accountType, // asset|liability|equity|income|expense
        public string $nature, // debit|credit
        public ?int $parentId = null,
        public bool $isGroup = false,
        public bool $isSystem = false,
        public ?string $description = null,
        public bool $isActive = true,
        public ?float $openingBalance = 0,
        public string $openingSide = 'debit',
    ) {}

    public static function fromArray(array $a): self
    {
        return new self(
            code: $a['code'], name: $a['name'], accountType: $a['account_type'], nature: $a['nature'],
            parentId: $a['parent_id'] ?? null, isGroup: (bool)($a['is_group'] ?? false),
            isSystem: (bool)($a['is_system'] ?? false), description: $a['description'] ?? null,
            isActive: (bool)($a['is_active'] ?? true), openingBalance: (float)($a['opening_balance'] ?? 0),
            openingSide: $a['opening_side'] ?? 'debit',
        );
    }
}
