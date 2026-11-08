<script setup lang="ts">
import { Check } from 'lucide-vue-next';

/**
 * The palette picker — item 42.
 *
 * `schemes` is the server catalogue (`UiTheme::catalogue()`, trimmed to id/label/swatch when it is
 * shared on every request). Nothing here knows what a palette contains: choosing one sets
 * `data-theme` on <html> and the token set in resources/css/themes.css does the rest, which is why
 * the same eight buttons repaint four hundred pages.
 *
 * The swatch is painted with an inline style rather than a utility class deliberately. These eight
 * hex values are the only literal colours in the feature — they exist to label a choice — and
 * SharedComponentThemeTest scans the shared components for raw palette classes, so a class here
 * would either be allowlisted or flagged, and neither says anything true.
 */
interface Scheme {
    id: string;
    label: string;
    swatch: string;
    description?: string;
}

const props = withDefaults(
    defineProps<{
        modelValue?: string | null;
        schemes: Scheme[];
        compact?: boolean;
        label?: string;
    }>(),
    { modelValue: null, compact: false, label: 'Colour scheme' },
);

const emit = defineEmits<{ select: [id: string] }>();

const isSelected = (id: string): boolean => props.modelValue === id;
</script>

<template>
    <div role="radiogroup" :aria-label="label">
        <div class="grid gap-2" :class="compact ? 'grid-cols-2 lg:grid-cols-4' : 'sm:grid-cols-2 xl:grid-cols-4'">
            <button
                v-for="scheme in schemes"
                :key="scheme.id"
                type="button"
                role="radio"
                :aria-checked="isSelected(scheme.id)"
                class="group flex items-start gap-3 rounded-xl border p-3 text-left transition focus-visible:ring-ring focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                :class="
                    isSelected(scheme.id)
                        ? 'border-primary bg-primary/5 shadow-sm ring-1 ring-primary'
                        : 'border-border bg-card hover:border-primary/40 hover:bg-muted/40'
                "
                @click="emit('select', scheme.id)"
            >
                <span
                    class="grid size-7 shrink-0 place-items-center rounded-lg ring-1 ring-foreground/15 ring-inset transition group-hover:scale-105"
                    :style="{ backgroundColor: scheme.swatch }"
                >
                    <!-- Ink that reads on a saturated brand surface in both modes: the hero token. -->
                    <Check v-if="isSelected(scheme.id)" class="text-hero-ink size-4" />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="text-foreground block truncate text-[13px] font-semibold">{{ scheme.label }}</span>
                    <span
                        v-if="!compact && scheme.description"
                        class="text-muted-foreground mt-0.5 block text-xs leading-snug"
                    >
                        {{ scheme.description }}
                    </span>
                    <span v-else class="text-muted-foreground mt-0.5 block font-mono text-[10px] tracking-wide uppercase">
                        {{ scheme.id }}
                    </span>
                </span>
            </button>
        </div>
    </div>
</template>
