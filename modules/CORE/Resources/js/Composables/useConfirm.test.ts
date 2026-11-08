/*
 * Tests for the shared confirm/prompt store and the host that renders it.
 *
 * These mount the real `ConfirmDialogHost` and click the real buttons, because the
 * defect worth guarding lives in the seam: a promise that never settles turns a
 * destructive-action guard into a dead click, and no unit test of the store on its
 * own can catch the host failing to call `settleDialog`.
 *
 * Radix `Dialog` teleports into `document.body`, so the assertions read the document
 * rather than the wrapper.
 */

import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { nextTick } from 'vue';

import ConfirmDialogHost from '../Components/ConfirmDialogHost.vue';
import { confirmDialog, pendingDialog, promptDialog, settleDialog } from './useConfirm';

/** jsdom lacks a few things radix-vue reaches for; stub only what is missing. */
if (typeof globalThis.ResizeObserver === 'undefined') {
    globalThis.ResizeObserver = class {
        observe() {}
        unobserve() {}
        disconnect() {}
    } as unknown as typeof ResizeObserver;
}

if (typeof Element.prototype.scrollIntoView !== 'function') {
    Element.prototype.scrollIntoView = () => {};
}

let wrapper: ReturnType<typeof mount> | null = null;

function mountHost() {
    wrapper = mount(ConfirmDialogHost, { attachTo: document.body });

    return wrapper;
}

function bodyText(): string {
    return document.body.textContent ?? '';
}

function button(label: string): HTMLButtonElement | undefined {
    return [...document.body.querySelectorAll<HTMLButtonElement>('button')].find(
        (element) => element.textContent?.trim() === label,
    );
}

function field(): HTMLInputElement | HTMLTextAreaElement | null {
    return document.body.querySelector<HTMLInputElement | HTMLTextAreaElement>('#confirm-dialog-input');
}

async function type(value: string) {
    const element = field();

    if (element === null) {
        throw new Error('the dialog rendered no input field');
    }

    element.value = value;
    element.dispatchEvent(new Event('input', { bubbles: true }));

    await nextTick();
}

describe('useConfirm', () => {
    beforeEach(() => {
        pendingDialog.value = null;
    });

    afterEach(() => {
        wrapper?.unmount();
        wrapper = null;
        pendingDialog.value = null;
        document.body.innerHTML = '';
    });

    it('resolves true when the confirm button is used, and clears the store', async () => {
        mountHost();

        const answer = confirmDialog({ description: 'Void this journal entry?', confirmLabel: 'Void entry', danger: true });
        await nextTick();

        expect(bodyText()).toContain('Void this journal entry?');
        expect(pendingDialog.value).not.toBeNull();

        button('Void entry')?.click();
        await nextTick();

        await expect(answer).resolves.toBe(true);
        expect(pendingDialog.value).toBeNull();
    });

    it('resolves false when the user cancels, so a guard can simply return', async () => {
        mountHost();

        const answer = confirmDialog({ description: 'Release this sheet?' });
        await nextTick();

        button('Cancel')?.click();
        await nextTick();

        await expect(answer).resolves.toBe(false);
        expect(pendingDialog.value).toBeNull();
    });

    it('takes a bare string as the description', async () => {
        mountHost();

        const answer = confirmDialog('Delete this row?');
        await nextTick();

        expect(bodyText()).toContain('Delete this row?');

        settleDialog(false);
        await expect(answer).resolves.toBe(false);
    });

    it('collects a trimmed answer from a required prompt', async () => {
        mountHost();

        const answer = promptDialog({
            description: 'The reason is written to the audit trail.',
            inputLabel: 'Rejection reason',
            confirmLabel: 'Reject request',
        });
        await nextTick();

        expect(field()).not.toBeNull();
        // Required by default: an empty answer cannot be submitted at all, which is
        // the rule window.prompt() could not express and the audit trail depends on.
        expect(button('Reject request')?.disabled).toBe(true);

        await type('   ');
        expect(button('Reject request')?.disabled).toBe(true);

        await type('  Employee did not submit supporting documents  ');
        expect(button('Reject request')?.disabled).toBe(false);

        button('Reject request')?.click();
        await nextTick();

        await expect(answer).resolves.toBe('Employee did not submit supporting documents');
    });

    it('resolves null when a prompt is cancelled, like window.prompt() did', async () => {
        mountHost();

        const answer = promptDialog({ description: 'Reason?', inputLabel: 'Reason' });
        await nextTick();

        button('Cancel')?.click();
        await nextTick();

        await expect(answer).resolves.toBeNull();
    });

    it('allows an empty answer only when the prompt says it is optional', async () => {
        mountHost();

        const answer = promptDialog({ inputLabel: 'Note', required: false, confirmLabel: 'Save' });
        await nextTick();

        expect(button('Save')?.disabled).toBe(false);

        button('Save')?.click();
        await nextTick();

        await expect(answer).resolves.toBe('');
    });

    it('settles an open request as dismissed when a second one arrives', async () => {
        mountHost();

        const first = confirmDialog({ description: 'First question' });
        await nextTick();

        const second = confirmDialog({ description: 'Second question' });
        await nextTick();

        // Without this the first caller awaits forever and its button stays dead.
        await expect(first).resolves.toBe(false);
        expect(bodyText()).toContain('Second question');

        settleDialog(true);
        await expect(second).resolves.toBe(true);
    });

    it('settles a pending request when the host unmounts, as on navigation', async () => {
        const host = mountHost();

        const answer = confirmDialog({ description: 'Question still open when the page changes' });
        await nextTick();

        host.unmount();
        wrapper = null;

        await expect(answer).resolves.toBe(false);
        expect(pendingDialog.value).toBeNull();
    });

    it('re-arms the field on reopen so one row\'s reason cannot leak into the next', async () => {
        mountHost();

        const first = promptDialog({ inputLabel: 'Reason', initialValue: 'Carried forward balance' });
        await nextTick();

        expect(field()?.value).toBe('Carried forward balance');

        settleDialog(false);
        await expect(first).resolves.toBeNull();

        // The host keeps the closed dialog mounted for its exit animation, so reopening
        // has to reset the value rather than trust the component being freshly created.
        const second = promptDialog({ inputLabel: 'Reason' });
        await nextTick();

        expect(field()?.value ?? '').toBe('');

        settleDialog(false);
        await expect(second).resolves.toBeNull();
    });
});
