<script setup lang="ts">
import { cn } from '@/lib/utils';
import { useVModel } from '@vueuse/core';
import { ref, type HTMLAttributes } from 'vue';

const props = defineProps<{
    // `null` is admitted deliberately: a money field has to be able to hold *nothing*, because 0 is a
    // value and not an absence. The POS till binds `number | null` refs for exactly that reason — a
    // drawer closed without a count must not send a count of zero.
    defaultValue?: string | number | null;
    modelValue?: string | number | null;
    class?: HTMLAttributes['class'];
}>();

const emits = defineEmits<{
    (e: 'update:modelValue', payload: string | number): void;
}>();

const modelValue = useVModel(props, 'modelValue', emits, {
    passive: true,
    defaultValue: props.defaultValue,
});

const input = ref<HTMLInputElement | null>(null);

/**
 * `<script setup>` components are closed by default: a template ref bound to
 * this component exposed nothing, so `someRef.value?.focus()` resolved to
 * `undefined` and the optional call silently did nothing.
 *
 * That is invisible until something depends on it — the POS terminal refocuses
 * the barcode field after every scan, and a hardware scanner typing into an
 * unfocused field loses the sale. Expose the imperative methods the underlying
 * element already provides.
 */
defineExpose({
    focus: (options?: FocusOptions) => input.value?.focus(options),
    blur: () => input.value?.blur(),
    select: () => input.value?.select(),
    setSelectionRange: (
        start: number,
        end: number,
        direction?: 'forward' | 'backward' | 'none',
    ) => input.value?.setSelectionRange(start, end, direction),
    /** The raw element, for measurements or `scrollIntoView`. */
    el: input,
});
</script>

<template>
    <input
        ref="input"
        v-model="modelValue"
        :class="
            cn(
                'flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
                props.class,
            )
        "
    />
</template>
