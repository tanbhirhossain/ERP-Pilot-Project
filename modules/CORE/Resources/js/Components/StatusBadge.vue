<script setup lang="ts">
import { computed } from 'vue';

type Tone = 'neutral' | 'info' | 'success' | 'warning' | 'danger' | 'primary';

const props = withDefaults(defineProps<{
  label: string;
  tone?: Tone;
  dot?: boolean;
  size?: 'sm' | 'md';
}>(), {
  tone: 'neutral',
  dot: false,
  size: 'sm',
});

const toneClass = computed(() => {
  const map: Record<Tone, string> = {
    neutral: 'bg-zinc-500/10 text-zinc-600 dark:text-zinc-400 ring-zinc-500/20',
    info: 'bg-sky-500/10 text-sky-600 dark:text-sky-400 ring-sky-500/20',
    success: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 ring-emerald-500/20',
    warning: 'bg-amber-500/10 text-amber-600 dark:text-amber-400 ring-amber-500/20',
    danger: 'bg-rose-500/10 text-rose-600 dark:text-rose-400 ring-rose-500/20',
    primary: 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 ring-indigo-500/20',
  };
  return map[props.tone];
});

const dotClass = computed(() => {
  const map: Record<Tone, string> = {
    neutral: 'bg-zinc-500',
    info: 'bg-sky-500',
    success: 'bg-emerald-500',
    warning: 'bg-amber-500',
    danger: 'bg-rose-500',
    primary: 'bg-indigo-500',
  };
  return map[props.tone];
});
</script>

<template>
  <span
    class="inline-flex items-center gap-1.5 font-medium rounded-full ring-1 ring-inset whitespace-nowrap"
    :class="[toneClass, size === 'sm' ? 'px-2.5 py-0.5 text-xs' : 'px-3 py-1 text-sm']"
  >
    <span v-if="dot" class="size-1.5 rounded-full flex-shrink-0" :class="dotClass" />
    {{ label }}
  </span>
</template>
