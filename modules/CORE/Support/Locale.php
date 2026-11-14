<?php

namespace Modules\CORE\Support;

use Illuminate\Support\Facades\Schema;
use Modules\CORE\Models\CoreSetting;

/**
 * The UI-language catalogue and resolver — U19 (Bengali / RTL).
 *
 * A deliberate mirror of `UiTheme`, so the shell treats "which language" exactly the way it
 * already treats "which palette": one resolver, consulted by `HandleInertiaRequests` to share the
 * active code + direction with the front end and by `app.blade.php` to render the right `lang`/`dir`
 * on the `<html>` element for the first paint, before any JavaScript runs.
 *
 * Precedence, highest first:
 *
 *   1. the person's own choice for this browser — kept in the session, so a click is enough and it
 *      survives every navigation and full reload without touching the schema;
 *   2. the company default (`core_settings`: `ui.locale`), so a Bangladesh tenant can be born in
 *      বাংলা without each user choosing it;
 *   3. `DEFAULT_LOCALE`.
 *
 * The catalogue also owns **direction**, which is the half that matters most: বাংলা reads right-to-left,
 * and a table-driven app must mirror as a unit, so `dir` is derived from the code here and nowhere else.
 */
final class Locale
{
    public const DEFAULT_LOCALE = 'en';

    /** The `core_settings` key holding the company default. */
    public const COMPANY_LOCALE_KEY = 'ui.locale';

    /**
     * Supported UI languages. `dir` is the single source of truth for layout direction; a language
     * that is not listed here cannot be selected, and the shell cannot be put into an RTL state
     * that has no matching dictionary.
     *
     * @var array<string, array{id: string, label: string, native: string, dir: 'ltr'|'rtl'}>
     */
    public const LOCALES = [
        'en' => ['id' => 'en', 'label' => 'English', 'native' => 'English', 'dir' => 'ltr'],
        'bn' => ['id' => 'bn', 'label' => 'Bangla', 'native' => 'বাংলা', 'dir' => 'rtl'],
    ];

    /** @var string|null memoised per request (the company default is a single code) */
    private static ?string $companyCache = null;

    private static ?bool $tableExists = null;

    /** @return list<string> */
    public static function ids(): array
    {
        return array_keys(self::LOCALES);
    }

    public static function isValid(?string $locale): bool
    {
        return $locale !== null && isset(self::LOCALES[$locale]);
    }

    /** Layout direction for a code — defaults to ltr so an unknown code never strands the layout. */
    public static function direction(?string $locale): string
    {
        return self::LOCALES[$locale ?? self::DEFAULT_LOCALE]['dir'] ?? 'ltr';
    }

    /** @return list<array{id: string, label: string, native: string, dir: string}> */
    public static function catalogue(): array
    {
        return array_values(self::LOCALES);
    }

    /**
     * The company default, read once per request and guarded for a missing table, exactly as
     * `UiTheme::company()` is — this resolver runs on the migrate response itself, when the table
     * may not exist yet, and must degrade to the defaults rather than 500 the shell.
     */
    public static function company(): string
    {
        if (self::$companyCache !== null) {
            return self::$companyCache;
        }

        $locale = self::DEFAULT_LOCALE;

        if (self::$tableExists ??= Schema::hasTable('core_settings')) {
            $stored = CoreSetting::query()->where('key', self::COMPANY_LOCALE_KEY)->value('value');

            if (self::isValid($stored)) {
                $locale = (string) $stored;
            }
        }

        return self::$companyCache = $locale;
    }

    /** Write the company default. An unrecognised code is refused, not silently ignored. */
    public static function setCompany(string $locale, ?int $updatedBy = null): void
    {
        if (! self::isValid($locale)) {
            throw new \DomainException("Unknown UI locale: {$locale}");
        }

        if (! (self::$tableExists ??= Schema::hasTable('core_settings'))) {
            throw new \DomainException('The core_settings table does not exist yet; run the migrations.');
        }

        CoreSetting::query()->updateOrCreate(
            ['key' => self::COMPANY_LOCALE_KEY],
            ['value' => $locale, 'updated_by' => $updatedBy],
        );

        self::flush();
    }

    /** Forget the memo — used by the controller after a write, and by tests. */
    public static function flush(): void
    {
        self::$companyCache = null;
        self::$tableExists = null;
    }

    /**
     * The trimmed shape shared on every Inertia response. The switcher needs the active code, the
     * direction it implies, and the two-option catalogue (id / label / native) to draw its menu.
     *
     * @return array<string, mixed>
     */
    public static function share(): array
    {
        $code = app()->getLocale();

        if (! self::isValid($code)) {
            $code = self::DEFAULT_LOCALE;
        }

        return [
            'code' => $code,
            'dir' => self::direction($code),
            'company' => self::company(),
            'locales' => array_map(
                static fn (array $l): array => ['id' => $l['id'], 'label' => $l['label'], 'native' => $l['native']],
                self::catalogue(),
            ),
        ];
    }
}
