<?php

namespace Modules\CORE\Support;

use Illuminate\Http\Request;

/**
 * Immutable value object describing one server-side DataTable query.
 *
 * Everything a table needs to fetch exactly one page — search term, sort,
 * pagination and whitelisted filters — is parsed and validated in one place
 * so repositories can stay free of HTTP concerns (Interface Segregation /
 * Single Responsibility).
 */
final class DataTableQuery
{
    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    /**
     * @param  array<string, string>  $filters  validated, non-empty filter values
     * @param  list<string>  $allowedSorts
     * @param  list<string>  $allowedFilters
     */
    public function __construct(
        public readonly ?string $search = null,
        public readonly ?string $sort = null,
        public readonly string $direction = 'desc',
        public readonly int $page = 1,
        public readonly int $perPage = self::DEFAULT_PER_PAGE,
        public readonly array $filters = [],
        public readonly string $defaultSort = 'created_at',
        public readonly string $defaultDirection = 'desc',
    ) {}

    /**
     * Build a validated query from the current request.
     *
     * @param  list<string>  $allowedSorts  public sort keys safe to expose
     * @param  list<string>  $allowedFilters  public filter keys safe to expose
     */
    public static function fromRequest(
        Request $request,
        array $allowedSorts = [],
        array $allowedFilters = [],
        string $defaultSort = 'created_at',
        string $defaultDirection = 'desc',
    ): self {
        $rules = [
            'search' => ['nullable', 'string', 'max:255'],
            'dir' => ['nullable', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];

        if ($allowedSorts !== []) {
            $rules['sort'] = ['nullable', 'string', 'in:'.implode(',', $allowedSorts)];
        }

        $request->validate($rules);

        $filters = [];
        foreach ((array) $request->input('filters', []) as $key => $value) {
            if (! in_array($key, $allowedFilters, true) || ! is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);

            if ($value !== '') {
                $filters[(string) $key] = mb_substr($value, 0, 255);
            }
        }

        $search = trim((string) $request->input('search', ''));
        $sort = $request->input('sort');
        $direction = (string) $request->input('dir', $defaultDirection);

        return new self(
            search: $search !== '' ? mb_substr($search, 0, 255) : null,
            sort: is_string($sort) && $sort !== '' && in_array($sort, $allowedSorts, true) ? $sort : null,
            direction: in_array($direction, ['asc', 'desc'], true) ? $direction : $defaultDirection,
            page: max(1, (int) $request->input('page', 1)),
            perPage: min(self::MAX_PER_PAGE, max(1, (int) ($request->input('per_page') ?: self::DEFAULT_PER_PAGE))),
            filters: $filters,
            defaultSort: $defaultSort,
            defaultDirection: $defaultDirection,
        );
    }

    public function hasFilter(string $key): bool
    {
        return isset($this->filters[$key]);
    }

    public function filter(string $key): ?string
    {
        return $this->filters[$key] ?? null;
    }

    /**
     * The public query string state, handy for building "export current view" URLs.
     *
     * @return array<string, mixed>
     */
    public function toQueryString(): array
    {
        $query = [
            'page' => $this->page,
            'per_page' => $this->perPage,
        ];

        if ($this->search !== null) {
            $query['search'] = $this->search;
        }

        if ($this->sort !== null) {
            $query['sort'] = $this->sort;
            $query['dir'] = $this->direction;
        }

        if ($this->filters !== []) {
            $query['filters'] = $this->filters;
        }

        return $query;
    }
}
