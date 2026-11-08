<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { pendingDialog, settleDialog, type PendingDialog } from '../Composables/useConfirm';
import ConfirmDialog from './ConfirmDialog.vue';

/**
 * Renders whichever dialog `useConfirm()` currently has open.
 *
 * Mounted once in `AdminLayout`, next to `ToastStack`, so a page can ask a question
 * with one `await` instead of owning four refs, two handlers and a `<ConfirmDialog>`
 * tag — the eight lines of boilerplate that `PAYROLL/Pages/Reopens/Index.vue` and
 * thirty-odd other pages each carry a private copy of.
 */

/** Radix animates on close; keep the last props around long enough for that to be visible. */
const CLOSE_GRACE_MS = 200;

const open = computed({
    get: () => pendingDialog.value !== null,
    set: (value: boolean) => {
        // Esc, the overlay and the cancel button all arrive here.
        if (!value) {
            settleDialog(false);
        }
    },
});

const shown = ref<PendingDialog | null>(null);
let closer: ReturnType<typeof setTimeout> | null = null;

watch(
    pendingDialog,
    (dialog) => {
        if (dialog === null) {
            return;
        }

        if (closer !== null) {
            clearTimeout(closer);
            closer = null;
        }

        shown.value = dialog;
    },
    { immediate: true },
);

watch(open, (isOpen) => {
    if (isOpen || shown.value === null) {
        return;
    }

    closer = setTimeout(() => {
        shown.value = null;
        closer = null;
    }, CLOSE_GRACE_MS);
});

// A navigation while a question is on screen must not leave the caller awaiting forever.
onBeforeUnmount(() => {
    if (closer !== null) {
        clearTimeout(closer);
    }

    settleDialog(false);
});

function onConfirm(value: string) {
    settleDialog(true, value);
}
</script>

<template>
    <ConfirmDialog
        v-if="shown !== null"
        v-model:open="open"
        :title="shown.title"
        :description="shown.description"
        :confirm-label="shown.confirmLabel"
        :cancel-label="shown.cancelLabel"
        :danger="shown.danger"
        :processing="shown.processing"
        :input="shown.kind === 'prompt'"
        :input-label="shown.inputLabel"
        :input-placeholder="shown.inputPlaceholder"
        :input-type="shown.inputType"
        :input-required="shown.required"
        :initial-input="shown.initialValue"
        @confirm="onConfirm"
    />
</template>
