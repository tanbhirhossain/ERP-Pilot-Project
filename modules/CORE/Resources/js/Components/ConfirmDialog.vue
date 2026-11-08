<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { LoaderCircle, ShieldCheck, TriangleAlert } from 'lucide-vue-next';
import { computed, nextTick, ref, watch } from 'vue';

interface Props {
    open: boolean;
    title?: string;
    description?: string;
    confirmLabel?: string;
    cancelLabel?: string;
    danger?: boolean;
    processing?: boolean;
    /**
     * Render a text field and hand its value back with `confirm`.
     *
     * This is what replaces `window.prompt()`. It stays off by default, so the
     * thirty-odd pages that use this dialog as a plain yes/no question are
     * unaffected by it — and `confirm` still fires with no argument meaning to them.
     */
    input?: boolean;
    inputLabel?: string;
    inputPlaceholder?: string;
    inputType?: 'text' | 'textarea';
    inputRequired?: boolean;
    initialInput?: string;
}

const props = withDefaults(defineProps<Props>(), {
    title: 'Are you absolutely sure?',
    description: 'This action cannot be undone.',
    confirmLabel: 'Delete',
    cancelLabel: 'Cancel',
    danger: true,
    processing: false,
    input: false,
    inputLabel: undefined,
    inputPlaceholder: undefined,
    inputType: 'text',
    inputRequired: false,
    initialInput: '',
});

const emit = defineEmits<{
    'update:open': [value: boolean];
    confirm: [value: string];
}>();

const inputValue = ref(props.initialInput);
const inputEl = ref<HTMLInputElement | HTMLTextAreaElement | null>(null);

/** An empty required answer cannot be submitted — the native prompt() had no such rule. */
const canSubmit = computed(() => !props.inputRequired || inputValue.value.trim() !== '');

function onOpenChange(value: boolean) {
    emit('update:open', value);
}

function onConfirm() {
    if (!canSubmit.value) {
        return;
    }

    emit('confirm', inputValue.value.trim());
}

// Re-arm the field every time the dialog opens, so a host that keeps the component
// mounted cannot leak the previous row's rejection reason into the next one.
watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        inputValue.value = props.initialInput;

        void nextTick(() => inputEl.value?.focus());
    },
);
</script>

<template>
    <Dialog :open="open" @update:open="onOpenChange">
        <DialogContent class="gap-0 sm:max-w-md">
            <div class="flex items-start gap-3.5">
                <span
                    class="grid size-10 shrink-0 place-items-center rounded-xl shadow-sm"
                    :class="danger ? 'bg-rose-500/10 text-rose-600 dark:text-rose-400' : 'bg-primary/10 text-primary'"
                    aria-hidden="true"
                >
                    <TriangleAlert v-if="danger" class="size-5" />
                    <ShieldCheck v-else class="size-5" />
                </span>

                <div class="min-w-0 flex-1">
                    <DialogHeader class="text-left">
                        <DialogTitle class="text-base">{{ title }}</DialogTitle>
                        <DialogDescription class="text-sm leading-relaxed">
                            {{ description }}
                        </DialogDescription>
                    </DialogHeader>
                </div>
            </div>

            <div v-if="input" class="mt-4 space-y-2">
                <Label v-if="inputLabel" for="confirm-dialog-input" class="text-sm">{{ inputLabel }}</Label>
                <Textarea
                    v-if="inputType === 'textarea'"
                    id="confirm-dialog-input"
                    ref="inputEl"
                    v-model="inputValue"
                    :placeholder="inputPlaceholder"
                    :required="inputRequired"
                    rows="3"
                    class="resize-none text-sm"
                />
                <Input
                    v-else
                    id="confirm-dialog-input"
                    ref="inputEl"
                    v-model="inputValue"
                    :placeholder="inputPlaceholder"
                    :required="inputRequired"
                    class="text-sm"
                    @keydown.enter.prevent="onConfirm"
                />
            </div>

            <DialogFooter class="mt-5 gap-2 sm:justify-end">
                <p class="text-muted-foreground mr-auto hidden items-center gap-1 text-xs sm:flex">
                    Press
                    <kbd class="bg-muted rounded border px-1.5 py-0.5 font-mono text-[10px] font-medium">Esc</kbd>
                    to cancel
                </p>
                <Button variant="outline" class="rounded-lg" :disabled="processing" @click="emit('update:open', false)">
                    {{ cancelLabel }}
                </Button>
                <Button
                    :variant="danger ? 'destructive' : 'default'"
                    class="rounded-lg"
                    :disabled="processing || !canSubmit"
                    @click="onConfirm"
                >
                    <LoaderCircle v-if="processing" class="size-4 animate-spin" />
                    {{ confirmLabel }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
