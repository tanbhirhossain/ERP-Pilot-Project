<?php

namespace Modules\CORE\Support;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;
use Modules\CORE\Models\CoreSetting;

/**
 * The colour-scheme catalogue, and the resolver that decides which scheme applies to a request.
 *
 * Item 42. The shell used to offer one thing — a light/dark toggle reading `localStorage`, so the
 * choice belonged to a browser rather than to a person, and there was no colour choice at all beyond
 * the single near-black `--primary` the starter kit shipped with. This class is the other half of the
 * fix: the palette values themselves live in `resources/css/themes.css`, keyed by the identifiers
 * declared here, and `ThemePaletteTest` fails if the two drift apart in either direction.
 *
 * Precedence, highest first:
 *
 *   1. the user's own override (`users.ui_scheme` / `users.ui_mode`),
 *   2. the company default (`core_settings`: `ui.scheme` / `ui.mode`),
 *   3. the constants below.
 *
 * A company default that a user cannot escape is a policy, not a preference; a user preference that
 * ignores the company default makes every printed document and every projected screen a surprise.
 * Both are wanted, so both exist and the resolver reports which one won — the settings page shows it,
 * because "why is mine green?" needs an answer on screen rather than in a docblock.
 */
final class UiTheme
{
    /** Applied when nobody has chosen anything. */
    public const DEFAULT_SCHEME = 'indigo';

    /** `system` follows the OS, which is the least surprising default for a first login. */
    public const DEFAULT_MODE = 'system';

    /** @var list<string> */
    public const MODES = ['light', 'dark', 'system'];

    /** The `core_settings` keys holding the company default. */
    public const COMPANY_SCHEME_KEY = 'ui.scheme';

    public const COMPANY_MODE_KEY = 'ui.mode';

    /**
     * The catalogue. `swatch` is the only literal colour in the PHP layer and it exists purely to
     * paint the chip in the picker; every colour the application actually renders comes from the
     * token set in `themes.css`, which is what makes a scheme switch repaint 400 pages.
     *
     * @var array<string, array{id: string, label: string, swatch: string, description: string}>
     */
    public const SCHEMES = [
        'indigo' => [
            'id' => 'indigo',
            'label' => 'Indigo Executive',
            'swatch' => '#4f46e5',
            'description' => 'The house default: deep, calm, and the closest match to the shells the ERP already ships.',
        ],
        'royal' => [
            'id' => 'royal',
            'label' => 'Royal Blue',
            'swatch' => '#2563eb',
            'description' => 'Classic finance blue. Reads as trustworthy on a balance sheet and on a projector.',
        ],
        'emerald' => [
            'id' => 'emerald',
            'label' => 'Emerald Enterprise',
            'swatch' => '#059669',
            'description' => 'Green-led for retail and inventory operations where growth is the story.',
        ],
        'teal' => [
            'id' => 'teal',
            'label' => 'Teal Ledger',
            'swatch' => '#0d9488',
            'description' => 'Cool and clinical; strong contrast against the warm status colours.',
        ],
        'violet' => [
            'id' => 'violet',
            'label' => 'Violet Regent',
            'swatch' => '#7c3aed',
            'description' => 'A richer, more editorial accent for tenant-facing deployments.',
        ],
        'rose' => [
            'id' => 'rose',
            'label' => 'Rose Crimson',
            'swatch' => '#e11d48',
            'description' => 'High energy, for brands that want the shell to feel alive.',
        ],
        'amber' => [
            'id' => 'amber',
            'label' => 'Amber Copper',
            'swatch' => '#d97706',
            'description' => 'Warm and industrial. Distinct from the amber warning tone, which stays semantic.',
        ],
        'graphite' => [
            'id' => 'graphite',
            'label' => 'Graphite Neutral',
            'swatch' => '#18181b',
            'description' => 'No brand hue at all: ink only, for tenants who want the data to be the colour.',
        ],
    ];

    /** @var array<string, string>|null memoised per request: settings are read on every Inertia share */
    private static ?array $companyCache = null;

    private static ?bool $tableExists = null;

    /** @return list<string> */
    public static function schemeIds(): array
    {
        return array_keys(self::SCHEMES);
    }

    /**
     * @return list<array{id: string, label: string, swatch: string, description: string}>
     */
    public static function catalogue(): array
    {
        return array_values(self::SCHEMES);
    }

    public static function isValidScheme(?string $scheme): bool
    {
        return $scheme !== null && isset(self::SCHEMES[$scheme]);
    }

    public static function isValidMode(?string $mode): bool
    {
        return $mode !== null && in_array($mode, self::MODES, true);
    }

    /**
     * The company default, read once per request.
     *
     * The `Schema::hasTable` guard is not decoration: this resolver runs from
     * `HandleInertiaRequests`, so it also runs on the response of any request made before the
     * migration has been applied — including `php artisan migrate` itself on a fresh database. A
     * missing table must degrade to the defaults rather than take the whole shell down with it.
     *
     * @return array{scheme: string, mode: string}
     */
    public static function company(): array
    {
        if (self::$companyCache !== null) {
            return self::$companyCache;
        }

        $scheme = self::DEFAULT_SCHEME;
        $mode = self::DEFAULT_MODE;

        if (self::$tableExists ??= Schema::hasTable('core_settings')) {
            $stored = CoreSetting::query()
                ->whereIn('key', [self::COMPANY_SCHEME_KEY, self::COMPANY_MODE_KEY])
                ->pluck('value', 'key');

            if (self::isValidScheme($stored[self::COMPANY_SCHEME_KEY] ?? null)) {
                $scheme = (string) $stored[self::COMPANY_SCHEME_KEY];
            }

            if (self::isValidMode($stored[self::COMPANY_MODE_KEY] ?? null)) {
                $mode = (string) $stored[self::COMPANY_MODE_KEY];
            }
        }

        return self::$companyCache = ['scheme' => $scheme, 'mode' => $mode];
    }

    /** Forget the memo — used by the settings controller after a write, and by tests. */
    public static function flush(): void
    {
        self::$companyCache = null;
        self::$tableExists = null;
    }

    /**
     * Write the company default. Values are validated by the caller against the catalogue; an
     * unrecognised scheme is refused here too, because a stored typo would silently fall back to
     * indigo for every user and look like the setting was ignored.
     */
    public static function setCompany(string $scheme, string $mode, ?int $updatedBy = null): void
    {
        if (! self::isValidScheme($scheme) || ! self::isValidMode($mode)) {
            throw new \DomainException("Unknown colour scheme or mode: {$scheme}/{$mode}");
        }

        if (! (self::$tableExists ??= Schema::hasTable('core_settings'))) {
            throw new \DomainException('The core_settings table does not exist yet; run the migrations.');
        }

        foreach ([self::COMPANY_SCHEME_KEY => $scheme, self::COMPANY_MODE_KEY => $mode] as $key => $value) {
            CoreSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $value, 'updated_by' => $updatedBy],
            );
        }

        self::flush();
    }

    /**
     * The trimmed shape shared on every Inertia response.
     *
     * `resolve()` carries the catalogue with its descriptions, which is what the settings page
     * renders. Sharing that on every request would put a kilobyte of prose into every XHR payload
     * for a screen almost nobody visits, so the shared copy keeps the id, label and swatch — enough
     * for the quick switcher in the shell header — and drops the rest.
     *
     * @return array<string, mixed>
     */
    public static function share(?Authenticatable $user): array
    {
        $resolved = self::resolve($user);

        $resolved['schemes'] = array_map(
            static fn (array $scheme): array => [
                'id' => $scheme['id'],
                'label' => $scheme['label'],
                'swatch' => $scheme['swatch'],
            ],
            $resolved['schemes'],
        );

        return $resolved;
    }

    /**
     * What applies to this request.
     *
     * The parameter is the framework's base authenticatable, not `Modules\CORE\Models\User`, even
     * though that is what the auth provider resolves at runtime. Two classes in this application
     * extend it — the module's User and the starter kit's `App\Models\User`, which the kit's own
     * auth tests still log in with — and hinting the subclass made every Inertia response a 500
     * TypeError for the parent. `can()` and attribute access both come from the base class, so
     * nothing is lost by widening it.
     *
     * `dark` is resolved for `light`/`dark` only. `system` cannot be answered server-side — the
     * operating system preference lives in the browser — so the shell renders the light tokens and
     * the inline bootstrap in `app.blade.php` settles it before first paint.
     *
     * @return array{
     *     scheme: string, mode: string, source: 'user'|'company'|'default',
     *     company_scheme: string, company_mode: string,
     *     user_scheme: string|null, user_mode: string|null,
     *     dark: bool, can_manage_company: bool,
     *     schemes: list<array{id: string, label: string, swatch: string, description: string}>,
     *     modes: list<string>
     * }
     */
    public static function resolve(?Authenticatable $user): array
    {
        $company = self::company();

        $userScheme = $user?->ui_scheme;
        $userMode = $user?->ui_mode;

        $scheme = self::isValidScheme($userScheme) ? (string) $userScheme : $company['scheme'];
        $mode = self::isValidMode($userMode) ? (string) $userMode : $company['mode'];

        $source = 'default';

        if (self::isValidScheme($userScheme) || self::isValidMode($userMode)) {
            $source = 'user';
        } elseif ($scheme !== self::DEFAULT_SCHEME || $mode !== self::DEFAULT_MODE) {
            $source = 'company';
        }

        return [
            'scheme' => $scheme,
            'mode' => $mode,
            'source' => $source,
            'company_scheme' => $company['scheme'],
            'company_mode' => $company['mode'],
            'user_scheme' => self::isValidScheme($userScheme) ? (string) $userScheme : null,
            'user_mode' => self::isValidMode($userMode) ? (string) $userMode : null,
            'dark' => $mode === 'dark',
            'can_manage_company' => (bool) $user?->can('settings.appearance.manage'),
            'schemes' => self::catalogue(),
            'modes' => self::MODES,
        ];
    }
}
