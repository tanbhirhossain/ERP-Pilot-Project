/* Display helpers shared by the CORE module UI. */

const numberFormatter = new Intl.NumberFormat('en-US');

export function formatNumber(value: number | null | undefined): string {
    return numberFormatter.format(Number(value ?? 0));
}

export function formatCompact(value: number | null | undefined): string {
    return new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 }).format(
        Number(value ?? 0),
    );
}

/**
 * A timestamp renders at 115px in a list column and at ~160px in a detail grid; the same string used
 * for both wraps in the narrow one and reads as two facts ("14 Nov 2026" / "03:33 pm") in the wide
 * one. `width` lets the caller say which it is, instead of every caller hard-coding a fixed format.
 *
 * A `date` column — a receipt date, a bill date, a joining date — is a *day*, not an instant, and it
 * does not survive the round trip through an instant unchanged.
 *
 * Laravel casts it with timezone information, so `2026-11-20` in the database leaves as
 * `2026-11-19T18:00:00.000000Z`: the day starts at midnight in Asia/Dhaka, which is six hours earlier
 * in UTC. Formatting that instant in UTC prints the 19th, and formatting it in a browser further west
 * prints the 19th too — the receipt disagreed with the ledger it posted to, depending on who opened it.
 *
 * The day is therefore recovered the only way it can be: by reading the instant in the zone the day
 * was written in (see `APP_TIMEZONE` below), then ignoring the time. `created_at` and `posted_at` are
 * real instants and still format in the reader's own zone, because "when did this happen" is local.
 */

/** The zone every date column in this app is written in. Must match `config('app.timezone')`. */
const APP_TIMEZONE = 'Asia/Dhaka';

function isDateOnly(value: string): boolean {
    return /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/.test(value);
}

/**
 * The calendar day an instant names, in the app's zone.
 *
 * Built from `en-CA` parts because `en-CA` is the one common locale whose date order is ISO
 * (`2026-11-20`), and a plain ISO string is what `new Date()` reads back without any further
 * timezone guessing.
 */
function dayOf(value: string): string {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: APP_TIMEZONE,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(new Date(value));

    return parts;
}

export function formatDate(
    value: string | null | undefined,
    withTime = false,
    width: 'short' | 'long' = 'long',
): string {
    if (!value) return '—';

    const dateOnly = isDateOnly(value);

    // A day-cast column: read it in the zone it was written in, print the day, stop.
    if (dateOnly) {
        const day = dayOf(value);
        const date = new Date(`${day}T00:00:00Z`);

        if (Number.isNaN(date.getTime())) return value;

        return date.toLocaleDateString('en-GB', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
            timeZone: 'UTC',
        });
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) return value;

    // A very old calendar date (a joining date, a holiday) has no meaningful time: midnight reads as
    // a fabricated fact, so it is dropped rather than printed.
    const midnight = date.getHours() === 0 && date.getMinutes() === 0;

    if (width === 'short') {
        return date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
    }

    if (withTime && !midnight) {
        return date.toLocaleString('en-GB', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            hour12: true,
        });
    }

    return date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

export function formatDateTime(value: string | null | undefined, width: 'short' | 'long' = 'long'): string {
    return formatDate(value, true, width);
}

const RELATIVE_UNITS: [Intl.RelativeTimeFormatUnit, number][] = [
    ['year', 60 * 60 * 24 * 365],
    ['month', 60 * 60 * 24 * 30],
    ['week', 60 * 60 * 24 * 7],
    ['day', 60 * 60 * 24],
    ['hour', 60 * 60],
    ['minute', 60],
];

const relativeFormatter = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });

export function timeAgo(value: string | null | undefined): string {
    if (!value) return '—';

    const timestamp = new Date(value).getTime();

    if (Number.isNaN(timestamp)) return value;

    const seconds = Math.round((timestamp - Date.now()) / 1000);
    const absolute = Math.abs(seconds);

    for (const [unit, size] of RELATIVE_UNITS) {
        if (absolute >= size) {
            return relativeFormatter.format(Math.round(seconds / size), unit);
        }
    }

    return 'just now';
}

export function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('');
}

/** First-letter badge color, deterministic per name (premium avatar chips). */
export function avatarTone(seed: string): string {
    const tones = [
        'from-indigo-500 to-violet-500',
        'from-sky-500 to-cyan-500',
        'from-emerald-500 to-teal-500',
        'from-rose-500 to-pink-500',
        'from-amber-500 to-orange-500',
        'from-fuchsia-500 to-purple-500',
    ];

    let hash = 0;

    for (let i = 0; i < seed.length; i += 1) {
        hash = (hash * 31 + seed.charCodeAt(i)) >>> 0;
    }

    return tones[hash % tones.length];
}

const bdtNumberFormatter = new Intl.NumberFormat('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/**
 * A money string that cannot break its line.
 *
 * The taka sign is a *spaced* glyph: in `1,690.00` the ৳ sits above the cap height of the digits, so
 * a column of amounts either pushes it onto its own line or clips it. Every cell of a price list was
 * doing one or the other — some rows rendered "৳" as a superscript on the line above and left the
 * number alone below it, which reads as a rendering fault rather than a price.
 *
 * Wrapping the sign with the digits in a `nowrap` span and dropping the space keeps the two together.
 * The formatter stays `en-BD` so the grouping is the lakh/crore grouping a Bangladeshi reader expects.
 */
export function formatCurrency(value: number | null | undefined, currency: string = 'BDT'): string {
    if (value === null || value === undefined) return '—';
    // Bengali taka signs. `৳15,720.00` is two glyphs colliding: the taka sign already has a
    // descender on that side, and the digit beside it closes the gap to nothing, so four-figure
    // amounts read as "৳15" wrapped onto its own line. The space is not decoration — it is what
    // separates the symbol from the numeral in every printed taka statement.
    if (currency === 'BDT') return `৳ ${bdtNumberFormatter.format(Number(value))}`;
    return new Intl.NumberFormat('en-US', { style: 'currency', currency, maximumFractionDigits: 2 }).format(Number(value));
}

export function formatCurrencyCompact(value: number | null | undefined): string {
    if (value === null || value === undefined) return '—';
    return `৳ ${new Intl.NumberFormat('en-BD', { notation: 'compact', maximumFractionDigits: 1 }).format(Number(value))}`;
}

/** Badge tones for audit events. */
export function eventTone(event: string | null): { dot: string; chip: string } {
    switch (event) {
        case 'created':
            return { dot: 'bg-emerald-500', chip: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' };
        case 'updated':
        case 'permissions_synced':
        case 'roles_synced':
            return { dot: 'bg-amber-500', chip: 'bg-amber-500/10 text-amber-600 dark:text-amber-400' };
        case 'deleted':
            return { dot: 'bg-rose-500', chip: 'bg-rose-500/10 text-rose-600 dark:text-rose-400' };
        case 'login':
            return { dot: 'bg-sky-500', chip: 'bg-sky-500/10 text-sky-600 dark:text-sky-400' };
        case 'logout':
            return { dot: 'bg-slate-500', chip: 'bg-slate-500/10 text-slate-600 dark:text-slate-400' };
        case 'login_failed':
            return { dot: 'bg-rose-600', chip: 'bg-rose-600/10 text-rose-600 dark:text-rose-400' };
        case 'registered':
            return { dot: 'bg-violet-500', chip: 'bg-violet-500/10 text-violet-600 dark:text-violet-400' };
        default:
            return { dot: 'bg-slate-400', chip: 'bg-slate-500/10 text-slate-600 dark:text-slate-400' };
    }
}
