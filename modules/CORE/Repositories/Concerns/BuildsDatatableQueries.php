<?php

namespace Modules\CORE\Repositories\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Modules\CORE\Support\DataTableQuery;

/**
 * Shared query building for every repository that backs a DataTable.
 *
 * Designed for tables of any size: only the current page is ever hydrated,
 * searching/sorting happens inside the database with whitelisted columns,
 * and the client never receives more than `per_page` rows (capped at 100).
 */
trait BuildsDatatableQueries
{
    /**
     * @param  list<string>  $searchColumns  DB columns eligible for LIKE search
     * @param  array<string, string>  $sortable  public sort key => DB column
     * @param  array<string, \Closure(Builder, string): void>  $filterHandlers
     */
    protected function applyDataTable(
        Builder $query,
        DataTableQuery $dt,
        array $searchColumns,
        array $sortable = [],
        array $filterHandlers = [],
    ): void {
        if ($dt->search !== null && $searchColumns !== []) {
            $needle = '%'.addcslashes($dt->search, '%_').'%';

            $query->where(function (Builder $group) use ($searchColumns, $needle): void {
                foreach ($searchColumns as $index => $column) {
                    $index === 0
                        ? $group->where($column, 'like', $needle)
                        : $group->orWhere($column, 'like', $needle);
                }
            });
        }

        foreach ($dt->filters as $key => $value) {
            if (isset($filterHandlers[$key])) {
                $filterHandlers[$key]($query, $value);
            }
        }

        $column = $dt->sort !== null ? ($sortable[$dt->sort] ?? null) : null;

        if ($column !== null) {
            $query->orderBy($column, $dt->direction === 'asc' ? 'asc' : 'desc');
        } else {
            $query->orderBy($dt->defaultSort, $dt->defaultDirection);
        }
    }
}
