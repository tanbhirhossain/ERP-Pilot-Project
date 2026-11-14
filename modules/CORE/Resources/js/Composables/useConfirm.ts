import { ref, type Ref } from 'vue';

/**
 * Verbs that destroy or take something away. The warning icon and the destructive button are honest here.
 */
const DESTRUCTIVE_VERBS = [
    'delete', 'remove', 'purge', 'revoke', 'destroy', 'drop', 'clear', 'wipe', 'reset', 'revert', 'undo',
    'discard', 'terminate', 'void', 'cancel', 'withdraw', 'reject', 'unlink', 'unassign', 'detach', 'disable',
];

/**
 * Verbs that create, apply or change. These must never be dressed up as a deletion — that was the whole
 * complaint: a red **Delete** button on "Award this round?", "Approve anyway?", "Backfill approval
 * requests", "Allocate monthly installments", because the shared dialog's default label was `Delete` and
 * most call sites never overrode it.
 */
const CONSTRUCTIVE_VERBS = [
    'approve', 'award', 'allocate', 'backfill', 'add', 'create', 'post', 'publish', 'send', 'submit', 'sync',
    'import', 'mark', 'set', 'update', 'edit', 'enable', 'restore', 'assign', 'link', 'apply', 'build',
    'generate', 'recalculate', 'recalc', 'rebuild', 'commit', 'run', 'adopt', 'attach', 'convert', 'release',
    'refresh', 'move', 'fill', 'fix', 'roll', 'book', 'pay', 'close', 'open', 'lock', 'unlock',
];

export interface DerivedAction {
    /** The verb the dialog should carry on its button, e.g. `Revoke`. */
    confirmLabel: string;
    /** True only when the action really takes something away. */
    danger: boolean;
}

/**
 * Read the verb out of the wording a page already chose, rather than making every page repeat it.
 *
 * The first word of the title is the natural place for it ("Revoke this token?"); when the title leads with
 * something else ("Over budget — approve anyway?"), the first recognised verb anywhere in the text wins.
 * Unrecognised text is not a licence to guess: it gets a neutral **Confirm**, and stays warning-toned, so a
 * dialog can never again offer to "Delete" a row it was asked to approve.
 */
export function describeAction(title?: string, description?: string, fallbackLabel = 'Confirm'): DerivedAction {
    const text = [title, description].filter((t): t is string => !!t).join(' ').toLowerCase().trim();

    if (text === '') {
        return { confirmLabel: fallbackLabel, danger: true };
    }

    /**
     * Where a verb bites in this sentence. An inflected form counts (`rejected` is `reject`), but the word
     * has to end there, so "clearly this will take a moment" is not a request to Clear anything.
     */
    /**
     * Where a verb bites, as a ranking key: `0` for the verb the sentence leads with (that is the
     * imperative — `Revoke this token?`), and for anything else the number of clauses that come before it
     * plus its offset. A verb later in the sentence outranks one earlier in it, because the pattern these
     * prompts share is `context — what you are about to do?` (`Over budget — approve anyway?`), and the
     * last clause is the action, not the first.
     */
    const rank = (at: number): number => {
        if (at < 0) return Number.POSITIVE_INFINITY;
        if (at === 0) return Number.NEGATIVE_INFINITY;

        const clauses = (text.slice(0, at).match(/[,.;:!?—–]/g) ?? []).length;

        // Negative, so a verb in a later clause ranks *below* one earlier: lower wins.
        return clauses * -1000 + at;
    };

    const findIn = (list: string[], destructive: boolean) => {
        let best: { verb: string; at: number; destructive: boolean } | null = null;
        let bestRank = Number.POSITIVE_INFINITY;

        for (const verb of list) {
            const at = text.search(new RegExp(`\\b${verb}(e[sd]|ed|ing|s|d)?\\b`));

            if (at < 0) {
                continue;
            }

            const found = rank(at);

            if (found < bestRank) {
                best = { verb, at, destructive };
                bestRank = found;
            }
        }

        return best;
    };

    const destructive = findIn(DESTRUCTIVE_VERBS, true);
    const constructive = findIn(CONSTRUCTIVE_VERBS, false);
    const candidates = [destructive, constructive].filter((c): c is NonNullable<typeof c> => c !== null);

    if (candidates.length === 0) {
        return { confirmLabel: fallbackLabel, danger: true };
    }

    // The winner sets the label; a destructive verb anywhere in the sentence keeps the warning tone, even
    // when a constructive verb won the label. `Clear and rebuild attendance` is an approval, and it still
    // wipes rows — the label should not lie, but neither should the styling pretend it is safe.
    const winner = candidates.reduce((a, b) => (rank(b.at) < rank(a.at) ? b : a));

    return {
        confirmLabel: winner.verb.charAt(0).toUpperCase() + winner.verb.slice(1),
        danger: destructive !== null,
    };
}

/**
 * Module-wide dialog store — the same shape as `useToast`, and for the same reason:
 * the request comes from a page and the dialog is rendered by a single host mounted
 * once in `AdminLayout`, so the two have to meet somewhere shared.
 */
export const pendingDialog: Ref<PendingDialog | null> = ref(null);

function normalise(options: ConfirmOptions | string, fallbackLabel = 'Confirm'): ConfirmOptions {
    // `confirmDialog('Delete this row?')` is the one-argument form most call sites want.
    const base: ConfirmOptions = typeof options === 'string' ? { description: options } : { ...options };

    // A caller who named a label or a tone keeps both. The derivation only fills the gap left by the ones
    // that did not, which is most of them — and those are exactly where a default of `Delete` was wrong.
    const derived = describeAction(base.title, base.description, fallbackLabel);

    return {
        ...base,
        confirmLabel: base.confirmLabel ?? derived.confirmLabel,
        danger: base.danger ?? derived.danger,
    };
}

function open<T>(request: Omit<PendingDialog, 'resolve'>): Promise<T> {
    // A second request while one is open must not strand the first caller forever:
    // it is settled as dismissed, exactly as if that user had pressed Esc.
    if (pendingDialog.value !== null) {
        settleDialog(false);
    }

    return new Promise<T>((resolve) => {
        pendingDialog.value = { ...request, resolve } as PendingDialog;
    });
}

/**
 * Ask a yes/no question through the themed dialog instead of `window.confirm()`.
 *
 * Resolves `true` only when the confirm button was used. Esc, the overlay, the
 * cancel button and an unmounted host all resolve `false` — the promise never
 * hangs, so `if (!(await confirmDialog(…))) return;` is safe in a guard.
 */
export function confirmDialog(options: ConfirmOptions | string): Promise<boolean> {
    return open<boolean>({ kind: 'confirm', ...normalise(options) });
}

/**
 * Collect a short piece of text through the themed dialog instead of `window.prompt()`.
 *
 * Mirrors the native contract so migrations stay mechanical: `null` when the user
 * cancelled, the trimmed answer otherwise. Unlike `window.prompt()` the field can be
 * labelled, made required and pre-filled — which is what the rejection-reason prompts
 * that feed the audit trail actually needed.
 */
export function promptDialog(options: PromptOptions | string): Promise<string | null> {
    // A prompt collects an answer, so the button that commits it is `Submit` unless the wording names a
    // verb ("Reject this requisition?" → `Reject`). It was `Delete`, on a dialog whose only job is to ask
    // for a reason.
    const prompt: PromptOptions = normalise(options, 'Submit') as PromptOptions;

    return open<string | null>({
        ...prompt,
        inputType: prompt.inputType ?? 'text',
        required: prompt.required ?? true,
        kind: 'prompt',
    });
}

/**
 * Close the open dialog and settle its promise. Called by `ConfirmDialogHost`.
 *
 * `value` is already trimmed by the dialog; a prompt that is dismissed resolves
 * `null` so the caller can tell "cancelled" from "answered with nothing".
 */
export function settleDialog(confirmed: boolean, value?: string): void {
    const pending = pendingDialog.value;

    if (pending === null) {
        return;
    }

    pendingDialog.value = null;

    if (pending.kind === 'prompt') {
        pending.resolve(confirmed ? (value ?? '') : null);
    } else {
        pending.resolve(confirmed);
    }
}

export function useConfirm() {
    return {
        pendingDialog,
        confirm: confirmDialog,
        prompt: promptDialog,
        dismiss: () => settleDialog(false),
    };
}
