import { ref, type Ref } from 'vue';

export interface ConfirmOptions {
    title?: string;
    description?: string;
    confirmLabel?: string;
    cancelLabel?: string;
    danger?: boolean;
    processing?: boolean;
}

export interface PromptOptions extends ConfirmOptions {
    inputLabel?: string;
    inputPlaceholder?: string;
    inputType?: 'text' | 'textarea';
    /** A prompt answer must be non-empty unless this is explicitly set to false. */
    required?: boolean;
    initialValue?: string;
}

/** One open request, including the promise it has to settle. */
export interface PendingDialog extends PromptOptions {
    kind: 'confirm' | 'prompt';
    resolve: (value: boolean | string | null) => void;
}

/**
 * Module-wide dialog store — the same shape as `useToast`, and for the same reason:
 * the request comes from a page and the dialog is rendered by a single host mounted
 * once in `AdminLayout`, so the two have to meet somewhere shared.
 */
export const pendingDialog: Ref<PendingDialog | null> = ref(null);

function normalise(options: ConfirmOptions | string): ConfirmOptions {
    // `confirmDialog('Delete this row?')` is the one-argument form most call sites want.
    return typeof options === 'string' ? { description: options } : { ...options };
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
    const prompt: PromptOptions = typeof options === 'string' ? { description: options } : { ...options };

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
