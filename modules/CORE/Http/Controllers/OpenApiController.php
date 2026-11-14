<?php

namespace Modules\CORE\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Modules\CORE\Support\Api\ApiEndpoint;
use Modules\CORE\Support\Api\ApiSpecBuilder;

/**
 * F13 — the running contract, served from itself.
 *
 * Built per request from the live route table rather than read from
 * `docs/openapi.json`, so what a client fetches is what this deployment actually
 * routes. The file in `docs/` is the same bytes, committed so the diff of a route
 * change shows up in review; `php artisan api:spec --check` is what keeps the two
 * from parting company.
 */
class OpenApiController extends Controller
{
    public function __construct(private readonly ApiSpecBuilder $spec) {}

    #[ApiEndpoint(
        summary: 'This document, as machine-readable OpenAPI 3.1',
        description: 'Generated from the live route table on every request, so it can only describe what this deployment actually serves. `docs/openapi.json` is the same bytes, committed for review; `php artisan api:spec --check` fails when they part company.',
        public: true,
    )]
    public function __invoke(): Response
    {
        return response($this->spec->toJson(), 200, [
            'Content-Type' => 'application/json',
            // A contract is not a secret and should not be a cache entry either: a
            // stale spec is worse than a reparse.
            'Cache-Control' => 'no-store',
        ]);
    }
}
