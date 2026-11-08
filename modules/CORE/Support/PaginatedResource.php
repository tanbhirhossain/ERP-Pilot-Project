<?php

namespace Modules\CORE\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Normalises a paginator into the exact `{ data, meta }` payload the
 * DataTable component consumes — keeping presentation concerns out of the
 * Vue layer and the controllers terse.
 */
final class PaginatedResource
{
    /**
     * @return array{data: list<mixed>, meta: array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}}
     */
    public static function from(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }
}
