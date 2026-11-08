<script setup lang="ts">
import { AlertTriangle, CheckCircle2, Info, X, XCircle } from 'lucide-vue-next';
import { watch } from 'vue';
import { useFlash } from '../Composables/useAcl';
import { dismiss, toasts, useToast, type ToastType } from '../Composables/useToast';

const { flash } = useFlash();
const toast = useToast();

const ICONS: Record<ToastType, typeof CheckCircle2> = {
    success: CheckCircle2,
    error: XCircle,
    warning: AlertTriangle,
    info: Info,
};

const TONES: Record<ToastType, string> = {
    success: 'text-emerald-600 bg-emerald-500/10 dark:text-emerald-400',
    error: 'text-rose-600 bg-rose-500/10 dark:text-rose-400',
    warning: 'text-amber-600 bg-amber-500/10 dark:text-amber-400',
    info: 'text-sky-600 bg-sky-500/10 dark:text-sky-400',
};

const BAR_TONES: Record<ToastType, string> = {
    success: 'bg-emerald-500',
    error: 'bg-rose-500',
    warning: 'bg-amber-500',
    info: 'bg-sky-500',
};

// Bridge server-side flash messages (redirect ->with('success', ...)) into
// the premium toast stack — everything stays inside the CORE module.
watch(
    flash,
    (value) => {
        if (value.success) toast.success(value.success);
        if (value.error) toast.error(value.error);
        if (value.warning) toast.warning(value.warning);
        if (value.info) toast.info(value.info);
    },
    { deep: true, immediate: true },
);
</script>

<template>
    <div
        data-core-toasts
        class="pointer-events-none fixed top-4 right-4 z-[200] flex w-[calc(100vw-2rem)] max-w-sm flex-col gap-3 print:hidden"
        role="region"
        aria-live="polite"
        aria-label="Notifications"
    >
        <TransitionGroup name="toast">
            <div
                v-for="item in toasts"
                :key="item.id"
                class="toast-card border-border/70 bg-card/95 pointer-events-auto relative overflow-hidden rounded-xl border p-4 pr-10 shadow-2xl shadow-black/10 backdrop-blur"
                @mouseenter="item.duration = 0"
            >
                <div class="flex items-start gap-3">
                    <div class="grid size-9 shrink-0 place-items-center rounded-lg" :class="TONES[item.type]">
                        <component :is="ICONS[item.type]" class="size-5" />
                    </div>

                    <div class="min-w-0 flex-1 pt-0.5">
                        <p class="text-foreground text-sm font-semibold">{{ item.title }}</p>
                        <p class="text-muted-foreground mt-0.5 text-sm leading-snug">{{ item.message }}</p>
                    </div>

                    <button
                        type="button"
                        class="text-muted-foreground/70 hover:bg-muted hover:text-foreground focus-visible:ring-ring absolute top-2.5 right-2.5 rounded-md p-1 transition focus-visible:ring-2 focus-visible:outline-none"
                        aria-label="Dismiss notification"
                        @click="dismiss(item.id)"
                    >
                        <X class="size-4" />
                    </button>
                </div>

                <span
                    class="absolute bottom-0 left-0 h-0.5 origin-left opacity-70"
                    :class="BAR_TONES[item.type]"
                    :style="{
                        width: '100%',
                        animation: `toast-progress ${item.duration}ms linear forwards`,
                    }"
                />
            </div>
        </TransitionGroup>
    </div>
</template>

<style scoped>
.toast-enter-active,
.toast-leave-active {
    transition: all 0.32s cubic-bezier(0.21, 1.02, 0.73, 1);
}

.toast-enter-from {
    opacity: 0;
    transform: translateX(28%) scale(0.96);
}

.toast-leave-to {
    opacity: 0;
    transform: translateX(16%) scale(0.96);
}

.toast-move {
    transition: transform 0.3s cubic-bezier(0.21, 1.02, 0.73, 1);
}

.toast-card:hover span[style*='toast-progress'] {
    animation-play-state: paused;
}

@keyframes toast-progress {
    from {
        transform: scaleX(1);
    }
    to {
        transform: scaleX(0);
    }
}
</style>
