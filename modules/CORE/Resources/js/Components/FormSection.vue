<script setup lang="ts">
import { cn } from '@/lib/utils';
import { ChevronDown } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * A form section: header + full-width responsive grid body.
 *
 * Visually consistent with EnterpriseCard, but built for forms — the body is a
 * grid whose density is declared once here instead of being hand-written per
 * page, and it never constrains its own width. The narrow `max-w-2xl`/`max-w-4xl`
 * wrappers scattered across the ERP left forms floating in the middle of a
 * 1500px content area with dead space either side and cramped 2-column grids.
 */
interface Props {
    title?: string;
    description?: string;
    icon?: unknown;
    /** Grid columns at the widest breakpoint. */
    cols?: 1 | 2 | 3 | 4;
    /** Skip the grid — the caller lays out the body (line-item tables, etc). */
    plain?: boolean;
    collapsible?: boolean;
    defaultOpen?: boolean;
    /** Small right-aligned badge, e.g. a step number or record count. */
    badge?: string | number;
    class?: string;
    bodyClass?: string;
    /** Tighter padding for dense sections such as journal lines. */
    dense?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    title: undefined,
    description: undefined,
    icon: undefined,
    cols: 3,
    plain: false,
    collapsible: false,
    defaultOpen: true,
    badge: undefined,
    class: undefined,
    bodyClass: undefined,
    dense: false,
});

const open = ref(props.defaultOpen);

/**
 * Density ramps with the viewport rather than jumping straight to `cols`.
 * A 4-column form is unreadable at 768px, so it steps 1 → 2 → 3 → 4.
 */
const gridClass = computed(() => {
    if (props.plain) return '';

    const ramps: Record<number, string> = {
        1: 'grid grid-cols-1',
        2: 'grid grid-cols-1 md:grid-cols-2',
        3: 'grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3',
        4: 'grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4',
    };

    return `${ramps[props.cols]} gap-x-5 gap-y-4`;
});
</script>

<template>
    <section
        :class="
            cn(
                'w-full overflow-hidden rounded-xl border bg-card shadow-sm transition-shadow',
                'hover:shadow-md',
                props.class,
            )
        "
    >
        <header
            v-if="title || description || icon || $slots.actions || badge !== undefined"
            class="flex items-start justify-between gap-3 border-b bg-muted/25 px-5 py-3.5"
        >
            <button
                v-if="collapsible"
                type="button"
                class="-mx-1 flex min-w-0 flex-1 items-start gap-3 rounded-md px-1 py-0.5 text-left transition hover:bg-muted/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                :aria-expanded="open"
                @click="open = !open"
            >
                <span
                    v-if="icon"
                    class="grid size-8 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary ring-1 ring-primary/15"
                >
                    <component :is="icon" class="size-4" />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="flex flex-wrap items-center gap-2">
                        <h2 class="text-sm font-semibold tracking-tight text-foreground">{{ title }}</h2>
                        <span
                            v-if="badge !== undefined"
                            class="rounded-full border bg-background px-2 py-0.5 text-[11px] font-medium tabular-nums text-muted-foreground"
                        >{{ badge }}</span>
                    </span>
                    <p v-if="description" class="mt-0.5 text-xs leading-relaxed text-muted-foreground">
                        {{ description }}
                    </p>
                </span>
                <ChevronDown
                    class="mt-1 size-4 shrink-0 text-muted-foreground transition-transform duration-200"
                    :class="open && 'rotate-180'"
                />
            </button>

            <template v-else>
                <div class="flex min-w-0 items-start gap-3">
                    <span
                        v-if="icon"
                        class="grid size-8 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary ring-1 ring-primary/15"
                    >
                        <component :is="icon" class="size-4" />
                    </span>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-sm font-semibold tracking-tight text-foreground">{{ title }}</h2>
                            <span
                                v-if="badge !== undefined"
                                class="rounded-full border bg-background px-2 py-0.5 text-[11px] font-medium tabular-nums text-muted-foreground"
                            >{{ badge }}</span>
                        </div>
                        <p v-if="description" class="mt-0.5 text-xs leading-relaxed text-muted-foreground">
                            {{ description }}
                        </p>
                    </div>
                </div>
            </template>

            <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2">
                <slot name="actions" />
            </div>
        </header>

        <div
            v-show="!collapsible || open"
            :class="cn(dense ? 'p-4' : 'p-5 sm:p-6', gridClass, props.bodyClass)"
        >
            <slot />
        </div>

        <footer v-if="$slots.footer" class="border-t bg-muted/20 px-5 py-3">
            <slot name="footer" />
        </footer>
    </section>
</template>
