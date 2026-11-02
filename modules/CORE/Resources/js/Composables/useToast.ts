import { ref, type Ref } from 'vue';

export type ToastType = 'success' | 'error' | 'warning' | 'info';

export interface Toast {
    id: number;
    type: ToastType;
    title: string;
    message: string;
    duration: number;
}

/**
 * Module-wide toast store — every consumer (the flash watcher inside
 * ToastStack and any programmatic call) shares this single reactive list.
 */
export const toasts: Ref<Toast[]> = ref([]);

let sequence = 0;

const DEFAULT_DURATION = 5000;

const DEFAULT_TITLES: Record<ToastType, string> = {
    success: 'Success',
    error: 'Something went wrong',
    warning: 'Attention',
    info: 'Heads up',
};

const MAX_VISIBLE = 4;

function show(type: ToastType, message: string, title?: string, duration = DEFAULT_DURATION) {
    const id = ++sequence;

    // Production guard: never flood the screen — retire the oldest first.
    while (toasts.value.length >= MAX_VISIBLE) {
        toasts.value.shift();
    }

    toasts.value.push({
        id,
        type,
        title: title ?? DEFAULT_TITLES[type],
        message,
        duration,
    });

    if (duration > 0) {
        window.setTimeout(() => dismiss(id), duration);
    }

    return id;
}

export function dismiss(id: number) {
    const index = toasts.value.findIndex((toast) => toast.id === id);

    if (index !== -1) {
        toasts.value.splice(index, 1);
    }
}

export function useToast() {
    return {
        toasts,
        show,
        dismiss,
        success: (message: string, title?: string, duration?: number) => show('success', message, title, duration),
        error: (message: string, title?: string, duration?: number) => show('error', message, title, duration),
        warning: (message: string, title?: string, duration?: number) => show('warning', message, title, duration),
        info: (message: string, title?: string, duration?: number) => show('info', message, title, duration),
    };
}
