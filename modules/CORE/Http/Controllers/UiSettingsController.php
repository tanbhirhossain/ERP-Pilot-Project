<?php

namespace Modules\CORE\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\UiTheme;

/**
 * UI Settings — colour scheme and light/dark mode (item 42).
 *
 * Two write endpoints, because two different people are being served:
 *
 *   - `update()` is any signed-in user choosing for themselves. No permission gate: a preference is
 *     not a privilege, and gating it would mean a warehouse operator stuck with whichever scheme an
 *     administrator liked. `null` means "follow the company default", which is how the reset works.
 *   - `updateCompany()` sets the house default and is gated on `settings.appearance.manage`.
 *
 * The scheme itself is never stored as a colour. The identifier is stored, the tokens live in
 * `resources/css/themes.css`, and `ThemePaletteTest` keeps the two lists identical — so a scheme
 * that reaches the database always has a palette behind it, and a palette that nobody can select
 * fails a test rather than sitting quietly in a stylesheet.
 */
class UiSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('CORE::Settings/Appearance', [
            'ui' => UiTheme::resolve($request->user()),
        ]);
    }

    /** The signed-in user's own override. `null` on either field clears it. */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'scheme' => ['sometimes', 'nullable', 'string', Rule::in(UiTheme::schemeIds())],
            'mode' => ['sometimes', 'nullable', 'string', Rule::in(UiTheme::MODES)],
        ]);

        $user = $request->user();

        if (array_key_exists('scheme', $data)) {
            $user->ui_scheme = $data['scheme'];
        }

        if (array_key_exists('mode', $data)) {
            $user->ui_mode = $data['mode'];
        }

        $user->save();
        UiTheme::flush();

        $effective = UiTheme::resolve($user);

        return back()->with('success', sprintf(
            'Your interface now uses %s in %s mode.',
            UiTheme::SCHEMES[$effective['scheme']]['label'],
            $effective['mode'],
        ));
    }

    /** The company default, applied to everyone who has not chosen for themselves. */
    public function updateCompany(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'scheme' => ['required', 'string', Rule::in(UiTheme::schemeIds())],
            'mode' => ['required', 'string', Rule::in(UiTheme::MODES)],
        ]);

        UiTheme::setCompany($data['scheme'], $data['mode'], $request->user()?->id);

        return back()->with('success', sprintf(
            'Company default set to %s in %s mode. Users who chose their own scheme keep it.',
            UiTheme::SCHEMES[$data['scheme']]['label'],
            $data['mode'],
        ));
    }
}
