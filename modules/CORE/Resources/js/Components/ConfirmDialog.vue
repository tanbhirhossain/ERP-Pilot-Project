<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { LoaderCircle, ShieldCheck, TriangleAlert } from 'lucide-vue-next';

interface Props {
    open: boolean;
    title?: string;
    description?: string;
    confirmLabel?: string;
    cancelLabel?: string;
    danger?: boolean;
    processing?: boolean;
}

withDefaults(defineProps<Props>(), {
    title: 'Are you absolutely sure?',
    description: 'This action cannot be undone.',
    confirmLabel: 'Delete',
    cancelLabel: 'Cancel',
    danger: true,
    processing: false,
});

const emit = defineEmits<{
    'update:open': [value: boolean];
    confirm: [];
}>();

function onOpenChange(value: boolean) {
    emit('update:open', value);
}
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

            <DialogFooter class="mt-5 gap-2 sm:justify-end">
                <p class="text-muted-foreground mr-auto hidden items-center gap-1 text-xs sm:flex">
                    Press
                    <kbd class="bg-muted rounded border px-1.5 py-0.5 font-mono text-[10px] font-medium">Esc</kbd>
                    to cancel
                </p>
                <Button variant="outline" class="rounded-lg" :disabled="processing" @click="emit('update:open', false)">
                    {{ cancelLabel }}
                </Button>
                <Button :variant="danger ? 'destructive' : 'default'" class="rounded-lg" :disabled="processing" @click="emit('confirm')">
                    <LoaderCircle v-if="processing" class="size-4 animate-spin" />
                    {{ confirmLabel }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
