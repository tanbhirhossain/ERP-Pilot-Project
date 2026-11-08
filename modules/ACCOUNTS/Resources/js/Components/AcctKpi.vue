<script setup lang="ts">
import { computed } from 'vue';
interface Props {
  label: string;
  value: string;
  sub?: string;
  icon?: any;
  tone?: 'default'|'success'|'danger'|'warning'|'info'|'amber';
  trend?: string;
}
const props = withDefaults(defineProps<Props>(), { sub: undefined, icon: undefined, tone: 'default', trend: undefined });
const toneCls = computed(()=> ({
  default: 'border-border bg-card',
  success: 'border-emerald-500/20 bg-emerald-500/[0.06]',
  danger: 'border-rose-500/20 bg-rose-500/[0.06]',
  warning: 'border-amber-500/20 bg-amber-500/[0.06]',
  info: 'border-sky-500/20 bg-sky-500/[0.06]',
  amber: 'border-amber-500/20 bg-amber-500/[0.06]',
}[props.tone]));
const iconBg = computed(()=> ({
  default: 'bg-muted text-muted-foreground',
  success: 'bg-emerald-500/10 text-emerald-600',
  danger: 'bg-rose-500/10 text-rose-600',
  warning: 'bg-amber-500/10 text-amber-600',
  info: 'bg-sky-500/10 text-sky-600',
  amber: 'bg-amber-500/10 text-amber-600',
}[props.tone]));
</script>
<template>
  <div class="rounded-2xl border p-4 shadow-sm backdrop-blur-sm" :class="toneCls">
    <div class="flex items-start justify-between gap-2">
      <div class="space-y-1 min-w-0 flex-1">
        <p class="text-[11px] font-semibold tracking-widest uppercase text-muted-foreground">{{ label }}</p>
        <p class="text-xl font-bold tracking-tight truncate font-mono">{{ value }}</p>
        <p v-if="sub" class="text-xs text-muted-foreground leading-tight">{{ sub }}</p>
        <p v-if="trend" class="text-xs font-medium" :class="tone==='success'?'text-emerald-600':tone==='danger'?'text-rose-600':'text-muted-foreground'">{{ trend }}</p>
      </div>
      <div v-if="icon" class="grid size-10 place-items-center rounded-xl shrink-0" :class="iconBg">
        <component :is="icon" class="size-5" />
      </div>
    </div>
  </div>
</template>
