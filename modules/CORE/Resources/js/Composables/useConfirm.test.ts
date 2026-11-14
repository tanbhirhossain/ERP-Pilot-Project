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
import { confirmDialog, describeAction, pendingDialog, promptDialog, settleDialog } from './useConfirm';

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

describe('the button says what the action is', () => {
    /*
     * The complaint this answers: "some of the confirmation messages use the delete form, when you click on
     * delete it will confirm — too funny a version of AI". `ConfirmDialog` was written for one job (before
     * a deletion) and its defaults were `Are you absolutely sure? / This action cannot be undone. /
     * [Delete] / danger`. Eleven of the twenty-odd call sites passed their own label; the other ten —
     * "Award this round?", "Over budget — approve anyway?", "Backfill approval requests", "Allocate monthly
     * installments", "Revoke this token?" — silently inherited a red Delete button for an action that
     * deletes nothing.
     *
     * So the label is now read out of the wording the page already chose, and the defaults went neutral.
     */

    it('names the verb the sentence leads with', () => {
        expect(describeAction('Revoke this token?').confirmLabel).toBe('Revoke');
        expect(describeAction('Award this round?')).toEqual({ confirmLabel: 'Award', danger: false });
        expect(describeAction('Allocate monthly installments').confirmLabel).toBe('Allocate');
        expect(describeAction('Backfill approval requests').confirmLabel).toBe('Backfill');
        expect(describeAction('Post the journal').confirmLabel).toBe('Post');
    });

    it('is destructive only when something is really taken away', () => {
        expect(describeAction('Revoke this token?').danger).toBe(true);
        expect(describeAction('Delete these 4 rows?').danger).toBe(true);
        expect(describeAction('Purge expired sessions').danger).toBe(true);
        // The whole point: approving is not deleting, and these used to look exactly like it.
        expect(describeAction('Approve anyway?').danger).toBe(false);
        expect(describeAction('Award this round?').danger).toBe(false);
    });

    it('finds the verb when the sentence leads with something else', () => {
        expect(describeAction('Over budget — approve anyway?').confirmLabel).toBe('Approve');
        expect(describeAction('This cannot be undone — delete the draft?').confirmLabel).toBe('Delete');
        // Inflections count as the same verb; a word that merely starts like one does not.
        // Context first, action last — the clause order these prompts share.
        expect(describeAction('Invoice 7 was rejected, send it again?')).toEqual({ confirmLabel: 'Send', danger: true });
        expect(describeAction('Clearly this will take a moment').confirmLabel).toBe('Confirm');
    });

    it('takes the verb the imperative is built on and keeps the warning when one is present', () => {
        // The leading verb owns the label; a destructive verb anywhere keeps the tone, because a rebuild
        // really does clear the rows it replaces.
        expect(describeAction('Clear and rebuild attendance')).toEqual({ confirmLabel: 'Clear', danger: true });
        expect(describeAction('Rebuild, then clear the cache')).toEqual({ confirmLabel: 'Rebuild', danger: true });
    });

    it('falls back to a neutral Confirm rather than guessing Delete', () => {
        expect(describeAction('Is this right?')).toEqual({ confirmLabel: 'Confirm', danger: true });
        expect(describeAction(undefined, undefined).confirmLabel).toBe('Confirm');
        expect(describeAction('', '', 'Submit').confirmLabel).toBe('Submit');
    });

    it('leaves a label the caller wrote alone', async () => {
        mountHost();

        // `danger: false` plus a custom label is what twenty-odd call sites should have written; the point
        // of the derivation is that they no longer have to. An explicit choice still outranks it.
        const answer = confirmDialog({ title: 'Revoke this token?', confirmLabel: 'Yes, revoke it', danger: false });
        await nextTick();

        const revoke = button('Yes, revoke it');

        expect(revoke).toBeDefined();
        expect(revoke?.className).not.toContain('bg-destructive');
        expect(button('Revoke')).toBeUndefined();

        settleDialog(false);
        await expect(answer).resolves.toBe(false);
    });

    it('renders a derived label on the button, not the old Delete', async () => {
        mountHost();

        const answer = confirmDialog('Award this round?');
        await nextTick();

        expect(bodyText()).toContain('Award this round?');
        expect(button('Delete')).toBeUndefined();
        expect(button('Cancel')?.textContent?.trim()).toBe('Cancel');

        const approve = button('Award');

        expect(approve).toBeDefined();
        // Non-destructive wording must not pick up the destructive styling either.
        expect(approve?.className).not.toContain('bg-destructive');

        approve?.click();
        await expect(answer).resolves.toBe(true);
    });

    it('renders a destructive button for the wording that deserves one', async () => {
        mountHost();

        const answer = confirmDialog('Revoke this token? This cannot be undone.');
        await nextTick();

        const revoke = button('Revoke');

        expect(revoke?.className).toContain('bg-destructive');

        revoke?.click();
        await expect(answer).resolves.toBe(true);
    });

    it('does not promise the action cannot be undone when it can', async () => {
        mountHost();

        const answer = confirmDialog({ title: 'Allocate monthly installments', description: 'Five rows are created.' });
        await nextTick();

        expect(bodyText()).not.toContain('This action cannot be undone.');

        button('Allocate')?.click();
        await expect(answer).resolves.toBe(true);
    });

    it('submits a prompt rather than deleting from it', async () => {
        mountHost();

        const answer = promptDialog('Why is this being rejected?');
        await nextTick();

        expect(button('Delete')).toBeUndefined();
        // "reject" is in the sentence, so the verb wins over the `Submit` fallback — and rejecting is
        // destructive, which the icon now reflects.
        expect(button('Reject')).toBeDefined();

        await type('Balance carried forward');

        button('Reject')?.click();
        await expect(answer).resolves.toBe('Balance carried forward');
    });

    it('uses Submit for a prompt whose wording names no verb', async () => {
        mountHost();

        const answer = promptDialog({ title: 'Reason', inputLabel: 'Reason' });
        await nextTick();

        expect(button('Delete')).toBeUndefined();
        expect(button('Confirm')).toBeUndefined();
        expect(button('Submit')).toBeDefined();

        settleDialog(false);
        await expect(answer).resolves.toBe(null);
    });
});
