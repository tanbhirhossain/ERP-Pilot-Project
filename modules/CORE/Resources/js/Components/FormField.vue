<script setup lang="ts">
import { cn } from '@/lib/utils';
import { AlertCircle } from 'lucide-vue-next';
import { computed, onMounted, ref, useId, useSlots } from 'vue';

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
 * A plain (non-scoped) slot still works — a slot that never receives the id
 * cannot be attached to a `<label for>`, so the label falls back to
 * `aria-labelledby` ordering and the field keeps its own control untouched.
 *
 * NOTE (bug fix): the previous version decided `isScoped` from
 * `slots.default.length > 0`. Every default slot — `#default="{ id }"` and a
 * plain `<SearchableSelect />` child alike — compiles to a non-empty function,
 * so that check was always true. The result was `<label for>` pointing at an id
 * that no element ever rendered, across all ~309 `<FormField>` call sites:
 * clicking a label did nothing, `getByLabel()` found nothing, and screen
 * readers announced an unlabelled input. We now resolve the label target from
 * the control that actually rendered inside the field.
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

/**
 * The id of the control that actually rendered in the default slot.
 *
 * Read from the DOM once the field is mounted: a scoped slot forwards `fieldId`
 * to `<Input :id>`, `<FormSelect :id>` and friends, while a raw child such as
 * `<SearchableSelect>` renders its own `<button role="combobox">`. Whatever the
 * case, if an element carries an id we point the label at it; if nothing does,
 * `controlId` stays undefined and the label is only referenced by
 * `aria-labelledby`, which is always valid.
 */
const root = ref<HTMLElement | null>(null);

const controlId = ref<string | undefined>(undefined);
const labelId = computed(() => `${fieldId.value}-label`);

onMounted(() => {
    const el = root.value;
    if (!el) return;

    // First focusable control in the field, in document order. SearchableSelect
    // renders a bare `<button type="button">` trigger, so a button that is not a
    // plain action button is a legitimate label target too.
    const control = el.querySelector<HTMLElement>(
        'input, select, textarea, [role="combobox"], button:not([data-action])',
    );

    if (!control) return;

    if (control.id) {
        controlId.value = control.id;

        return;
    }

    // The child component owns its markup and was given no `id` (for example
    // `<SearchableSelect>` inside a plain FormField). We cannot invent an `id`
    // it would keep across re-renders, so associate the label through ARIA
    // instead: `aria-labelledby` is valid on any control and is not rebound by
    // those components, which keeps `getByLabel()` and screen readers working.
    control.setAttribute('aria-labelledby', labelId.value);
});

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

/** Keep the legacy `isScoped` name for readers of the template. */
const isScoped = computed(() => controlId.value !== undefined);
</script>

<template>
    <div ref="root" :class="cn('min-w-0 space-y-1.5', spanClass, props.class)">
        <div v-if="label || help || $slots.labelAction" class="flex items-baseline justify-between gap-2">
            <label

                :for="controlId"
                :id="isScoped ? undefined : labelId"
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
