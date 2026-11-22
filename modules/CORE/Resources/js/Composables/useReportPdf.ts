import { readonly, ref, type Ref } from 'vue';
import { useToast } from './useToast';

/**
 * Server-side PDF download for report pages (item 52).
 *
 * The old "Print" button on report pages called `window.print()`, which prints
 * the rendered screen — a screenshot of the page with app chrome and theme
 * colours, not a document. This posts the page's own headings/rows/meta to the
 * `core.reports.pdf` endpoint, which renders a clean branded PDF server-side
 * and streams it back as a file download.
 *
 * The payload is exactly what the page already has on screen, so the PDF
 * matches the view the operator is looking at (filters, search, sort).
 */

export interface ReportPdfColumn {
    /** Column heading shown in the PDF. */
    heading: string;
    /** Optional alignment: `right` for money/number columns. */
    align?: 'left' | 'right';
}

export interface ReportPdfPayload {
    /** Document title (also drives the filename). */
    title: string;
    /** Optional one-line subtitle / context line. */
    subtitle?: string;
    /** View permission the caller already holds — verified server-side. */
    permission?: string;
    /** Paper orientation. Defaults to landscape for wide tables. */
    orientation?: 'portrait' | 'landscape';
    /** Key/value meta shown above the table (date range, filters, counts…). */
    meta?: Record<string, string>;
    /** Either explicit headings, or columns with per-column alignment. */
    headings?: string[];
    columns?: ReportPdfColumn[];
    /** Rows as arrays (one per column, in heading order). */
    rows?: unknown[][];
    /** Optional footer text for the colophon. */
    footer?: string;
}

interface DownloadState {
    downloading: Ref<boolean>;
}

let shared: DownloadState | null = null;

function state(): DownloadState {
    if (!shared) shared = { downloading: ref(false) };

    return shared;
}

function filenameFrom(disposition: string | null, fallback: string): string {
    if (disposition) {
        const utf8 = disposition.match(/filename\*=UTF-8''([^;]+)/i);
        if (utf8?.[1]) return decodeURIComponent(utf8[1].trim());

        const plain = disposition.match(/filename="?([^";]+)"?/i);
        if (plain?.[1]) return plain[1].trim();
    }

    return fallback;
}

function fallbackName(title: string): string {
    const slug = title.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'report';

    return `${slug}-${new Date().toISOString().slice(0, 10)}.pdf`;
}

function downloadBlob(blob: Blob, filename: string): void {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');

    link.href = url;
    link.download = filename;
    link.rel = 'noopener';

    document.body.appendChild(link);
    link.click();
    link.remove();

    window.setTimeout(() => URL.revokeObjectURL(url), 5000);
}

async function describeFailure(response: Response): Promise<string> {
    try {
        const text = await response.text();
        const titled = text.match(/<title>([^<]*)<\/title>/i);

        if (titled?.[1]) return titled[1].trim();
        if (text.trim())
            return text
                .replace(/<[^>]+>/g, ' ')
                .replace(/\s+/g, ' ')
                .trim()
                .slice(0, 200);
    } catch {
        /* body unreadable — fall through to the status code */
    }

    return `The server responded with status ${response.status}.`;
}

export function useReportPdf() {
    const toast = useToast();
    const { downloading } = state();

    /**
     * Render and download the report as a PDF.
     *
     * @returns a promise that resolves on success, rejects (and toasts) on failure.
     */
    async function downloadPdf(payload: ReportPdfPayload): Promise<void> {
        if (downloading.value) return;

        downloading.value = true;

        try {
            // Normalise columns → headings.
            const headings =
                payload.headings ??
                (payload.columns ?? []).map((column) => column.heading);

            if (!headings.length) {
                throw new Error('This report has no columns to export.');
            }

            let endpoint: string;

            try {
                endpoint = route('core.reports.pdf');
            } catch {
                endpoint = '/core/reports/pdf';
            }

            const response = await fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/pdf, */*',
                },
                body: JSON.stringify({
                    title: payload.title,
                    subtitle: payload.subtitle ?? '',
                    permission: payload.permission ?? null,
                    orientation: payload.orientation ?? 'landscape',
                    meta: payload.meta ?? {},
                    headings,
                    rows: payload.rows ?? [],
                    footer: payload.footer ?? '',
                }),
            });

            if (!response.ok) throw new Error(await describeFailure(response));

            const blob = await response.blob();

            if (!blob.size) throw new Error('The server returned an empty document.');

            downloadBlob(blob, filenameFrom(response.headers.get('Content-Disposition'), fallbackName(payload.title)));
            toast.success(`"${payload.title}" is ready — check your downloads folder.`, 'PDF downloaded');
        } catch (error) {
            const detail = error instanceof Error ? error.message : 'Unexpected error.';
            console.error('[CORE] report PDF failed', error);
            toast.error(detail, 'PDF download failed');
        } finally {
            downloading.value = false;
        }
    }

    return { downloadPdf, downloading: readonly(downloading) };
}
