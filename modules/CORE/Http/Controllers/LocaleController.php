<?php

namespace Modules\CORE\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\CORE\Support\Locale;

/**
 * Language preference — U19.
 *
 * Two writes, mirroring the two halves of `UiSettingsController`:
 *
 *   - `update()` is any signed-in user choosing the language for themselves. No permission gate: a
 *     preference is not a privilege. The choice is stored in the session (not the schema), so it is
 *     live on the very next response with no migration and no risk to the money/column guards, and it
 *     survives navigation and reloads.
 *   - `updateCompany()` sets the house default for a whole tenant (a Bangladesh office born in বাংলা)
 *     and rides the existing `settings.appearance.manage` permission — locale is shell chrome, so it
 *     belongs to whoever owns the shell, not a fresh privilege that would have to be granted everywhere.
 *
 * The redirect is not cosmetic: the response it triggers re-runs `SetLocale`, which is what actually
 * flips `app()->getLocale()`, the shared `dir`, and the `<html>` attributes for the next paint.
 */
class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', Rule::in(Locale::ids())],
        ]);

        $request->session()->put('app.locale', $data['locale']);

        return back();
    }

    public function updateCompany(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', Rule::in(Locale::ids())],
        ]);

        Locale::setCompany($data['locale'], $request->user()?->id);

        return back()->with('success', 'Company default language updated.');
    }
}
