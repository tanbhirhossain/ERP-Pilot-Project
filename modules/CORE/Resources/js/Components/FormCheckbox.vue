<script setup lang="ts">
import { Checkbox } from '@/components/ui/checkbox';
import { cn } from '@/lib/utils';
import type { HTMLAttributes } from 'vue';

/**
 * A checkbox presented as a selectable row: label, description and a checked
 * state you can see at a glance.
 *
 * The ERP's boolean settings were bare `<input type="checkbox" class="rounded">`
 * next to a text label — no hit area, no explanation of what the flag actually
 * does, and no visual difference between on and off beyond a tiny tick. Several
 * of these settings are consequential (whether lateness affects salary, whether
 * an employee has a contracted weekly day off), so they deserve to say so.
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
</script>

<template>
    <label
        :for="props.id"
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
            :id="props.id"
            v-model="model"
            :disabled="disabled"
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
