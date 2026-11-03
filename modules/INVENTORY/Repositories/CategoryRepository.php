<?php

namespace Modules\INVENTORY\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;
use Modules\INVENTORY\Interfaces\CategoryRepositoryInterface;
use Modules\INVENTORY\Models\Category;

class CategoryRepository implements CategoryRepositoryInterface
{
    use BuildsDatatableQueries;

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        $q = $this->baseQuery();
        $this->applyDataTable($q, $dt, ['name', 'code', 'description'], $this->sortable(), $this->filters());
        return $q->paginate($dt->perPage, ['*'], 'page', $dt->page)->withQueryString();
    }

    public function exportBuilder(DataTableQuery $dt): Builder
    {
        $q = $this->baseQuery();
        $this->applyDataTable($q, $dt, ['name', 'code', 'description'], $this->sortable(), $this->filters());
        return $q;
    }

    public function options(?string $search = null, int $limit = 100): Collection
    {
        return Category::query()->active()
            ->when($search, function (Builder $q, string $term): void {
                $needle = '%'.addcslashes($term, '%_').'%';
                $q->where(fn (Builder $g) => $g->where('name', 'like', $needle)->orWhere('code', 'like', $needle));
            })
            ->orderBy('sort_order')->orderBy('name')->limit($limit)
            ->get(['id', 'name', 'code', 'parent_id'])
            ->map(fn (Category $c) => ['value' => $c->id, 'label' => $c->name . ($c->code ? " ({$c->code})" : ''), 'parent_id' => $c->parent_id])->values();
    }

    public function tree(): Collection
    {
        return Category::with('children')->whereNull('parent_id')->active()->orderBy('sort_order')->orderBy('name')->get();
    }

    public function findOrFail(int $id): Category { return Category::with(['parent', 'children', 'products:id,category_id,name'])->findOrFail($id); }

    public function create(array $data): Category
    {
        return DB::transaction(function () use ($data): Category {
            if (empty($data['slug']) && ! empty($data['name'])) $data['slug'] = Str::slug($data['name']).'-'.uniqid();
            if (empty($data['code']) && ! empty($data['name'])) $data['code'] = strtoupper(substr(preg_replace('/[^A-Z]/', '', strtoupper($data['name'])), 0, 3)).'-'.str_pad((string) (Category::count()+1), 3, '0', STR_PAD_LEFT);
            return Category::create($data);
        });
    }

    public function update(Category $category, array $data): Category { $category->update($data); return $category; }

    public function delete(Category $category): void
    {
        if ($category->children()->exists()) throw new \DomainException('Cannot delete category with children.');
        if ($category->products()->exists()) throw new \DomainException('Cannot delete category with products.');
        $category->delete();
    }

    private function baseQuery(): Builder { return Category::query()->with(['parent:id,name', 'children:id,parent_id,name'])->withCount('products'); }
    private function sortable(): array { return ['name' => 'name', 'code' => 'code', 'sort_order' => 'sort_order', 'created_at' => 'created_at']; }
    private function filters(): array { return ['parent_id' => fn (Builder $q, string $v) => $q->where('parent_id', $v), 'is_active' => fn (Builder $q, string $v) => $q->where('is_active', $v === '1' || $v === 'true')]; }
}
