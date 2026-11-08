<script setup lang="ts">
/**
 * DetailItem — one labelled value inside a read-only detail grid.
 *
 * Renders as a plain div (dt/dd would need a dl parent, which FormSection's grid is not),
 * so it can be dropped into any grid. Booleans render as Yes/No, nullish values as an em dash.
 */
import { computed } from 'vue';

type Tone = 'default' | 'muted' | 'success' | 'warning' | 'destructive' | 'primary';

const props = withDefaults(
    defineProps<{
        label: string;
        value?: string | number | boolean | null;
        /** Text shown when the value is null, undefined or an empty string. */
        empty?: string;
        tone?: Tone;
        mono?: boolean;
        prefix?: string;
        span?: 1 | 2 | 3 | 'full';
        href?: string;
        /** Opens the link in a new tab; only used with `href`. */
        external?: boolean;
        class?: string;
    }>(),
    {
        value: null,
        empty: '—',
        tone: 'default',
        mono: false,
        prefix: undefined,
        span: 1,
        href: undefined,
        external: false,
        class: undefined,
    },
);

const spanClass = computed(() => {
    if (props.span === 'full') return 'col-span-full';
    if (props.span === 2) return 'sm:col-span-2';
    if (props.span === 3) return 'sm:col-span-2 xl:col-span-3';

    return '';
});

const toneClass = computed<Record<Tone, string>>(() => ({
    default: 'text-foreground',
    muted: 'text-muted-foreground',
    success: 'text-emerald-600 dark:text-emerald-400',
    warning: 'text-amber-600 dark:text-amber-400',
    destructive: 'text-destructive',
    primary: 'text-primary',
}));

const isEmpty = computed(() => props.value === null || props.value === undefined || props.value === '');

const display = computed(() => {
    if (typeof props.value === 'boolean') return props.value ? 'Yes' : 'No';
    if (isEmpty.value) return props.empty;

    return `${props.prefix ?? ''}${props.value}`;
});
</script>

<template>
    <div :class="[spanClass, props.class]">
        <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{{ label }}</p>

        <p class="mt-1 text-sm font-medium" :class="[toneClass[tone], mono && 'font-mono']">
            <a
                v-if="href && !isEmpty"
                :href="href"
                :target="external ? '_blank' : undefined"
                :rel="external ? 'noopener noreferrer' : undefined"
                class="underline decoration-primary/40 underline-offset-2 transition hover:decoration-primary"
            >
                {{ display }}
            </a>
            <span v-else :class="isEmpty && 'text-muted-foreground/70'">
                <slot>{{ display }}</slot>
            </span>
        </p>
    </div>
</template>
