<script setup lang="ts">
import { cn } from '@/lib/utils';
import { AlertCircle } from 'lucide-vue-next';
import { computed, useId, useSlots } from 'vue';

/**
 * One labelled form control: label, required marker, hint, inline error.
 *
 * Every page hand-rolled this as `<div class="space-y-2"><Label>…</Label><Input/>
 * <p v-if="form.errors.x" class="text-red-600">…</p></div>`, with the spacing,
 * the error colour and the required marker drifting from page to page — and the
 * error text never associated with its input. This is the single source.
 *
 * The generated `id` is passed to the default slot so the control can be wired
 * to its label:
 *
 *   <FormField label="Name" :error="form.errors.name" required>
 *     <template #default="{ id }"><Input :id="id" v-model="form.name" /></template>
 *   </FormField>
 *
 * A plain (non-scoped) slot still works — the label just uses aria-labelledby
 * fallback ordering, so nothing breaks when migrating a page incrementally.
 */
interface Props {
    label?: string;
    /** Server-side validation message, e.g. `form.errors.employee_id`. */
    error?: string | null;
    help?: string;
    required?: boolean;
    /** Grid columns to occupy inside a FormSection grid. */
    span?: 1 | 2 | 3 | 4 | 'full';
    /** Hide the label visually but keep it for screen readers. */
    hideLabel?: boolean;
    class?: string;
    labelClass?: string;
}

const props = withDefaults(defineProps<Props>(), {
    label: undefined,
    error: null,
    help: undefined,
    required: false,
    span: 1,
    hideLabel: false,
    class: undefined,
    labelClass: undefined,
});

const slots = useSlots();
const generatedId = useId();
const fieldId = computed(() => `${generatedId}-field`);
const errorId = computed(() => `${generatedId}-error`);
const helpId = computed(() => `${generatedId}-help`);

const spanClass = computed(() => {
    // `col-span-full` works at any grid density, so a section can change its
    // `cols` without its full-width fields breaking.
    if (props.span === 'full') return 'col-span-full';

    return {
        1: '',
        2: 'md:col-span-2',
        3: 'md:col-span-2 xl:col-span-3',
        4: 'md:col-span-2 xl:col-span-4',
    }[props.span as 1 | 2 | 3 | 4];
});

/** A scoped slot means the caller can wire the id through to the control. */
const isScoped = computed(() => (slots.default?.length ?? 0) > 0);
</script>

<template>
    <div :class="cn('min-w-0 space-y-1.5', spanClass, props.class)">
        <div v-if="label || help || $slots.labelAction" class="flex items-baseline justify-between gap-2">
            <label
                :for="isScoped ? fieldId : undefined"
                :id="isScoped ? undefined : `${fieldId}-label`"
                :class="
                    cn(
                        'text-[13px] font-medium leading-none text-foreground',
                        hideLabel && 'sr-only',
                        props.labelClass,
                    )
                "
            >
                {{ label }}
                <span v-if="required" class="ml-0.5 text-destructive" aria-hidden="true">*</span>
            </label>

            <span class="shrink-0 text-[11px] text-muted-foreground">
                <slot name="labelAction" />
            </span>
        </div>

        <slot :id="fieldId" :aria-describedby="error ? errorId : help ? helpId : undefined" :invalid="!!error" />

        <p v-if="help && !error" :id="helpId" class="text-xs leading-relaxed text-muted-foreground">
            {{ help }}
        </p>

        <p
            v-if="error"
            :id="errorId"
            role="alert"
            class="flex items-start gap-1 text-xs font-medium leading-relaxed text-destructive"
        >
            <AlertCircle class="mt-px size-3.5 shrink-0" aria-hidden="true" />
            <span>{{ error }}</span>
        </p>
    </div>
</template>
