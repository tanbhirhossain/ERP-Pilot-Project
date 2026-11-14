<?php

namespace Modules\CORE\Support\Api;

use Attribute;

/**
 * F13 — the human-readable half of an endpoint's contract, on the method that serves it.
 *
 * Why an attribute on the handler and not an array on the route: `Route::defaults()`
 * looks like the obvious place but fills *route parameters*, so metadata parked there is
 * silently invisible to `getAction()` — the spec would publish empty summaries and nobody
 * would notice until an integrator filed the report. Reflection on the action is exact,
 * and the description then travels with the code it describes.
 *
 * What it does NOT carry is authorisation. `x-required-permission` and `x-required-ability`
 * in the generated document are read back out of the route's real middleware list, so the
 * spec can only ever describe what is actually enforced.
 *
 * A v1 route whose handler lacks this attribute makes `php artisan api:spec` throw, which
 * is the point: undocumented endpoints should fail a build, not degrade the docs.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class ApiEndpoint
{
    /**
     * @param  list<array<string, mixed>>  $query  OpenAPI parameter objects for query strings
     * @param  list<array<string, mixed>>  $path  OpenAPI parameter objects for {placeholders}
     */
    public function __construct(
        public string $summary,
        public ?string $description = null,
        public ?string $request = null,
        public ?string $response = null,
        public array $query = [],
        public array $path = [],
        public bool $public = false,
    ) {}
}
