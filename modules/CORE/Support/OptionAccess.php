<?php

namespace Modules\CORE\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Authorisation gate for the `api/options/{type}` typeahead endpoints.
 *
 * Every module exposes one route that serves many kinds of reference data, and the route is the
 * wrong place to authorise it: `partner/api/options/{type}` serves vendors, customers, groups and
 * price lists, and each of those is governed by a different permission. Only the controller knows
 * which type it is about to serve.
 *
 * Before this, five of the seven modules had no authorisation at all beyond `['web', 'auth']`, so
 * any authenticated user could enumerate the whole chart of accounts, every **bank account number**,
 * the entire customer and vendor list **with phone numbers**, every product **with its selling
 * price**, and live stock levels through `inventory/api/pos-product/{key}`. HRM was gated at the
 * route level, and CORE and PURCHASE gated per type inside the controller — so the exposure was
 * invisible from the route list, which is where an audit normally looks.
 *
 * Callers declare a map and hand it over:
 *
 *     private const PERMISSIONS = ['vendors' => ['partner.vendors.view', 'purchase.bills.view']];
 *
 *     if ($denied = OptionAccess::gate($request, $type, self::PERMISSIONS)) {
 *         return $denied;
 *     }
 *
 * Two properties matter:
 *
 *  - **Deny by default.** A type that is not in the map 404s rather than falling through to the
 *    `match` default and returning an empty list. Adding a new arm without a permission therefore
 *    breaks loudly in the UI instead of silently shipping unauthorised.
 *  - **Any-of, not all-of.** Most of these types are *cross-module reference data*: the product
 *    picker is consumed by 7 PURCHASE pages, 5 SALES pages (including the POS terminal) and 5
 *    INVENTORY pages. Gating it on `inventory.products.view` alone would 403 for every purchase or
 *    sales role that had not also been granted inventory permissions, and would look like a broken
 *    dropdown rather than a permission problem. So each type lists every permission that implies
 *    needing that data, and holding any one of them is enough.
 *
 * Returns a `JsonResponse` rather than throwing so the body is always JSON. `abort(403)` would hand
 * the browser an HTML error page unless the request sent `Accept: application/json`, and these
 * endpoints are called with a bare `fetch()`.
 */
final class OptionAccess
{
    private function __construct() {}

    /**
     * @param  array<string, list<string>|string>  $permissions  type => permission, or a list of
     *                                                           permissions any of which suffices
     * @return JsonResponse|null null when the caller may proceed; a 403/404 response when not
     */
    public static function gate(Request $request, string $type, array $permissions): ?JsonResponse
    {
        if (! array_key_exists($type, $permissions)) {
            return response()->json([
                'error' => 'Unknown option type.',
                'type' => $type,
            ], 404);
        }

        $required = array_values((array) $permissions[$type]);
        $user = $request->user();

        foreach ($required as $permission) {
            if ($user !== null && $user->can($permission)) {
                return null;
            }
        }

        return response()->json([
            'error' => 'You do not have permission to read these options.',
            'type' => $type,
            // Named so an operator can see which grant is missing instead of staring at an empty
            // dropdown. These are the same strings the role editor already displays.
            'requires_any_of' => $required,
        ], 403);
    }

    /**
     * Every permission named by a set of controllers' maps.
     *
     * Used by the test that asserts none of them is a phantom: a typo here does not fail loudly,
     * because the Super Admin role holds the `*` wildcard and still gets through. It fails for
     * every real role, in production, as an empty dropdown.
     *
     * @param  list<class-string>  $controllers
     * @return array<string, list<string>> controller => permissions
     */
    public static function declaredPermissions(array $controllers): array
    {
        $out = [];

        foreach ($controllers as $controller) {
            $constant = (new \ReflectionClass($controller))->getReflectionConstant('PERMISSIONS');

            if ($constant === false) {
                continue;
            }

            $flat = [];

            foreach ((array) $constant->getValue() as $type => $required) {
                foreach ((array) $required as $permission) {
                    $flat[] = $permission;
                }
            }

            $out[$controller] = array_values(array_unique($flat));
        }

        return $out;
    }
}
