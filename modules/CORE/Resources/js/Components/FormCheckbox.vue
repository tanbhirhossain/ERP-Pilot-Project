<script setup lang="ts">
import { Checkbox } from '@/components/ui/checkbox';
import { cn } from '@/lib/utils';
import type { HTMLAttributes } from 'vue';
import { useId } from 'vue';

/**
 * A checkbox presented as a selectable row: label, description and a checked
 * state you can see at a glance.
 *
 * The ERP's boolean settings were bare `<input type="checkbox" class="rounded">`
 * next to a text label — no hit area, no explanation of what the flag actually
 * does, and no visual difference between on and off beyond a tiny tick. Several
 * of these settings are consequential (whether lateness affects salary, whether
 * an employee has a contracted weekly day off), so they deserve to say so.
 *
 * NOTE (bug fix): the radix `CheckboxRoot` exposes `checked` / `default-checked`
 * and emits `update:checked`. It has no `modelValue`, so the previous
 * `<Checkbox v-model="model">` compiled to a `modelValue` prop that was dropped
 * on the floor. The row reacted (the `model` ref toggled and the wrapper turned
 * primary) but the actual control always rendered `data-state="unchecked"`, and
 * the submitted payload carried the original value. `v-model:checked` is the
 * binding that reaches the component, which is what `v-model` was meant to be —
 * compare `Login.vue`, the one call site that got it right.
 *
 * The control also had no id: every call site renders `for=""`, so the label
 * text was not associated with the checkbox and clicking the text relied on
 * bubbling. An id is generated when the caller does not supply one.
 */
interface Props {
    label: string;
    description?: string;
    disabled?: boolean;
    invalid?: boolean;
    class?: HTMLAttributes['class'];
    id?: string;
}

const props = withDefaults(defineProps<Props>(), {
    description: undefined,
    disabled: false,
    invalid: false,
    class: undefined,
    id: undefined,
});

const model = defineModel<boolean>({ default: false });

/** Stable per-instance id so `<label for>` always resolves. */
const generatedId = useId();
const controlId = `${props.id ?? generatedId}-checkbox`;
</script>

<template>
    <label
        :for="controlId"
        :class="
            cn(
                'flex cursor-pointer items-start gap-3 rounded-xl border p-3 transition-colors',
                'hover:bg-accent/40 focus-within:ring-2 focus-within:ring-ring focus-within:ring-offset-2',
                model ? 'border-primary/40 bg-primary/[0.04]' : 'border-input bg-card',
                disabled && 'cursor-not-allowed opacity-50 hover:bg-transparent',
                invalid && 'border-destructive',
                props.class,
            )
        "
    >
        <Checkbox
            :id="controlId"
            v-model:checked="model"
            :disabled="disabled"
            :aria-invalid="invalid || undefined"
            class="mt-0.5"
        />
        <span class="min-w-0 flex-1">
            <span class="block text-[13px] font-medium leading-tight text-foreground">{{ label }}</span>
            <span
                v-if="description"
                class="mt-1 block text-xs leading-relaxed text-muted-foreground"
            >{{ description }}</span>
        </span>
    </label>
</template>
