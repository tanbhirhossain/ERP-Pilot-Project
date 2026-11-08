<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { CircleDot, LoaderCircle, Save, X } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Sticky action bar for a form page.
 *
 * Long ERP forms scroll well past a viewport, so the submit button used to
 * disappear — the operator had to scroll back to the top, or hunt for it at the
 * very bottom past the last field. This pins the primary actions to the bottom
 * of the viewport and carries the form's running summary beside them (a journal's
 * debit/credit balance, a cart total) so the decision and the numbers are in one
 * place.
 */
interface Props {
    submitLabel?: string;
    cancelLabel?: string;
    cancelHref?: string;
    /** Show an "unsaved changes" pill — pass Inertia's `form.isDirty`. */
    dirty?: boolean;
    /** In-flight: disables both buttons and shows a spinner. */
    processing?: boolean;
    disabled?: boolean;
    /** Hide the cancel link (modal forms, or pages with nowhere to go back to). */
    hideCancel?: boolean;
    submitVariant?: 'default' | 'outline' | 'secondary' | 'destructive';
    class?: string;
}

const props = withDefaults(defineProps<Props>(), {
    submitLabel: 'Save',
    cancelLabel: 'Cancel',
    cancelHref: undefined,
    dirty: false,
    processing: false,
    disabled: false,
    hideCancel: false,
    submitVariant: 'default',
    class: undefined,
});

defineEmits<{ cancel: [] }>();

const busy = computed(() => props.processing);
</script>

<template>
    <div
        :class="
            cn(
                'sticky bottom-4 z-30 mt-6 flex flex-wrap items-center gap-3 rounded-2xl border bg-card/95 px-4 py-3 shadow-lg shadow-black/5 backdrop-blur supports-[backdrop-filter]:bg-card/80',
                props.class,
            )
        "
    >
        <!-- Running summary / status: the numbers behind the decision. -->
        <div class="flex min-w-0 flex-1 flex-wrap items-center gap-x-4 gap-y-1.5 text-sm">
            <slot name="summary" />

            <span
                v-if="dirty"
                class="inline-flex items-center gap-1.5 rounded-full border border-amber-500/30 bg-amber-500/10 px-2.5 py-0.5 text-[11px] font-medium text-amber-700 dark:text-amber-400"
            >
                <CircleDot class="size-3" aria-hidden="true" />
                Unsaved changes
            </span>
        </div>

        <div class="ml-auto flex shrink-0 items-center gap-2">
            <slot name="actions" />

            <Link v-if="!hideCancel && cancelHref" :href="cancelHref">
                <Button type="button" variant="outline" :disabled="busy">
                    <X class="size-4" />{{ cancelLabel }}
                </Button>
            </Link>
            <Button
                v-else-if="!hideCancel"
                type="button"
                variant="outline"
                :disabled="busy"
                @click="$emit('cancel')"
            >
                <X class="size-4" />{{ cancelLabel }}
            </Button>

            <Button type="submit" :variant="submitVariant" :disabled="disabled || busy">
                <LoaderCircle v-if="busy" class="size-4 animate-spin" aria-hidden="true" />
                <Save v-else class="size-4" aria-hidden="true" />
                {{ submitLabel }}
            </Button>
        </div>
    </div>
</template>
