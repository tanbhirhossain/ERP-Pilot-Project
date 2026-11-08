<?php

namespace Modules\INVENTORY\DTO;

use Modules\INVENTORY\Models\Product;

final readonly class ProductData
{
    public function __construct(
        public int $id,
        public string $name,
        public string $sku,
        public ?string $barcode,
        public ?int $categoryId,
        public ?string $categoryName,
        public ?int $brandId,
        public ?string $brandName,
        public float $costPrice,
        public float $sellingPrice,
        public float $mrp,
        public string $uomSymbol,
        public string $type,
        public bool $isActive,
        public float $totalStock,
        public string $stockStatus,
    ) {}

    public static function fromModel(Product $p): self
    {
        return new self(
            id: $p->id,
            name: $p->name,
            sku: $p->sku,
            barcode: $p->barcode,
            categoryId: $p->category_id,
            categoryName: $p->category?->name,
            brandId: $p->brand_id,
            brandName: $p->brand?->name,
            costPrice: (float) $p->cost_price,
            sellingPrice: (float) $p->selling_price,
            mrp: (float) $p->mrp,
            uomSymbol: $p->uom?->symbol ?? 'pcs',
            type: $p->type,
            isActive: (bool) $p->is_active,
            totalStock: (float) ($p->total_stock ?? $p->stocks->sum('qty_on_hand')),
            stockStatus: $p->stock_status ?? 'unknown',
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'sku' => $this->sku, 'barcode' => $this->barcode,
            'category_id' => $this->categoryId, 'category_name' => $this->categoryName,
            'brand_id' => $this->brandId, 'brand_name' => $this->brandName,
            'cost_price' => $this->costPrice, 'selling_price' => $this->sellingPrice, 'mrp' => $this->mrp,
            'uom' => $this->uomSymbol, 'type' => $this->type, 'is_active' => $this->isActive,
            'total_stock' => $this->totalStock, 'stock_status' => $this->stockStatus,
        ];
    }
}
