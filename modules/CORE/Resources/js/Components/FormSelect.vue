<script setup lang="ts">
import { cn } from '@/lib/utils';
import { ChevronDown } from 'lucide-vue-next';
import type { HTMLAttributes } from 'vue';

/**
 * Native select styled to match the Input primitive.
 *
 * Pages were hand-writing `<select class="w-full h-9 rounded-md border
 * bg-background px-3 text-sm">` — an inch shorter than Input's h-10, with no
 * focus ring, no chevron and no dark-mode treatment, so every row mixing a text
 * field with a dropdown looked misaligned. Native `<select>` is kept (rather
 * than a custom listbox) because these are short fixed option sets and the
 * platform picker is faster to use.
 */
interface Props {
    modelValue?: string | number | boolean | null;
    class?: HTMLAttributes['class'];
    disabled?: boolean;
    invalid?: boolean;
    id?: string;
    placeholder?: string;
}

const props = withDefaults(defineProps<Props>(), {
    modelValue: null,
    class: undefined,
    disabled: false,
    invalid: false,
    id: undefined,
    placeholder: undefined,
});

const model = defineModel<string | number | boolean | null>();
</script>

<template>
    <div class="relative">
        <select
            :id="props.id"
            v-model="model"
            :disabled="disabled"
            :aria-invalid="invalid || undefined"
            :class="
                cn(
                    'h-10 w-full appearance-none rounded-md border border-input bg-background pl-3 pr-9 text-sm ring-offset-background transition',
                    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
                    'disabled:cursor-not-allowed disabled:opacity-50',
                    invalid && 'border-destructive focus-visible:ring-destructive',
                    model === null || model === '' ? 'text-muted-foreground' : 'text-foreground',
                    props.class,
                )
            "
        >
            <option v-if="placeholder !== undefined" value="">{{ placeholder }}</option>
            <slot />
        </select>

        <ChevronDown
            class="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
            aria-hidden="true"
        />
    </div>
</template>
