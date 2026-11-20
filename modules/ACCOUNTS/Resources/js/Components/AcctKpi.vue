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
/*
 * Tone is carried by the *value*, not by the card.
 *
 * Each KPI used to paint its whole surface — a pink card, a lilac card, a mint card — so a row of
 * four figures looked like four different things instead of four numbers of the same kind. On a
 * balance sheet the reader is comparing magnitudes; colouring the background competes with the
 * digits and makes every figure shout at the same volume, which is the same as none of them
 * shouting. The card stays a card; only the figure is allowed to be red or green, and only when it
 * means something.
 */
const toneCls = computed(()=> 'border-border bg-card');
const valueCls = computed(()=> ({
  default: 'text-foreground',
  success: 'text-emerald-600 dark:text-emerald-400',
  danger: 'text-rose-600 dark:text-rose-400',
  warning: 'text-amber-600 dark:text-amber-400',
  info: 'text-sky-600 dark:text-sky-400',
  amber: 'text-amber-600 dark:text-amber-400',
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
  <div class="rounded-lg border p-3.5" :class="toneCls">
    <div class="flex items-start justify-between gap-2">
      <div class="space-y-1 min-w-0 flex-1">
        <p class="eyebrow">{{ label }}</p>
        <p class="num-display text-[21px] font-semibold truncate" :class="valueCls">{{ value }}</p>
        <p v-if="sub" class="text-[11.5px] text-muted-foreground leading-snug">{{ sub }}</p>
        <p v-if="trend" class="text-xs font-medium" :class="tone==='success'?'text-emerald-600':tone==='danger'?'text-rose-600':'text-muted-foreground'">{{ trend }}</p>
      </div>
      <div v-if="icon" class="grid size-8 place-items-center rounded-md shrink-0" :class="iconBg">
        <component :is="icon" class="size-4" />
      </div>
    </div>
  </div>
</template>
