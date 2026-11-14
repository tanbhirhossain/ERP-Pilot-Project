<?php

namespace Modules\CORE\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\Api\ApiSpecBuilder;

/**
 * F13 — the API docs, inside the app that owns them.
 *
 * Rendered server-side from the live spec rather than fetched from a CDN-hosted
 * Swagger UI: an on-prem ERP often has no outbound network, and a docs page that needs
 * one is a docs page that shows a spinner in the customer's browser.
 */
class ApiReferenceController extends Controller
{
    public function __construct(private readonly ApiSpecBuilder $spec) {}

    public function index(Request $request): Response
    {
        return Inertia::render('CORE::Developer/ApiReference', [
            'spec' => $this->spec->build(),
            'specUrl' => url('/api/v1/openapi.json'),
            'canTry' => $request->user() !== null,
        ]);
    }
}
