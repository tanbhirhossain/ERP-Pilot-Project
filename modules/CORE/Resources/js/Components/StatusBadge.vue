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

/*
 * Item 42: every tone is a semantic token from resources/css/themes.css.
 *
 * This map used to carry six raw palette colours plus six `dark:text-*-400` counterparts, which is
 * the badge knowing about the theme — the exact thing a token exists to prevent. It also meant the
 * colour of "success" was a decision made here, per component, so a palette that is not indigo still
 * showed indigo badges. The status tokens are defined per mode and never per palette, so green means
 * the same thing under every scheme and the dark variants are simply gone.
 */
const toneClass = computed(() => {
  const map: Record<Tone, string> = {
    neutral: 'bg-neutral-ink/10 text-neutral-ink ring-neutral-ink/25',
    info: 'bg-info/10 text-info ring-info/25',
    success: 'bg-success/10 text-success ring-success/25',
    warning: 'bg-warning/10 text-warning ring-warning/25',
    danger: 'bg-danger/10 text-danger ring-danger/25',
    primary: 'bg-primary/10 text-primary ring-primary/25',
  };
  return map[props.tone];
});

const dotClass = computed(() => {
  const map: Record<Tone, string> = {
    neutral: 'bg-neutral-ink',
    info: 'bg-info',
    success: 'bg-success',
    warning: 'bg-warning',
    danger: 'bg-danger',
    primary: 'bg-primary',
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
