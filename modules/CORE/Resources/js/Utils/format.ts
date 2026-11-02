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

export function formatDate(value: string | null | undefined, withTime = false): string {
    if (!value) return '—';

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) return value;

    return date.toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        ...(withTime ? { hour: '2-digit', minute: '2-digit', hour12: true } : {}),
    });
}

export function formatDateTime(value: string | null | undefined): string {
    return formatDate(value, true);
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
