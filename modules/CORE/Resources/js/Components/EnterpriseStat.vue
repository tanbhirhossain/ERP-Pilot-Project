<script setup lang="ts">
import { computed } from 'vue'
/*
 * Item 42: the KPI tile paints from tokens, and every tile carries a two-pixel accent bar so a wall
 * of numbers reads as a designed surface rather than a stack of grey boxes. `default` takes the
 * palette's hero ramp — the tile is brand-coloured without the author choosing a colour.
 */
const props = withDefaults(defineProps<{ label: string; value: string; sub?: string; icon?: any; tone?: 'default'|'success'|'danger'|'warning'|'info' }>(), { sub: undefined, icon: undefined, tone: 'default' })
const toneCls = computed(()=> ({ default:'border-border bg-card', success:'border-success/25 bg-success/[0.06]', danger:'border-danger/25 bg-danger/[0.06]', warning:'border-warning/25 bg-warning/[0.06]', info:'border-info/25 bg-info/[0.06]' }[props.tone]))
const iconBg = computed(()=> ({ default:'bg-primary/10 text-primary', success:'bg-success/12 text-success', danger:'bg-danger/12 text-danger', warning:'bg-warning/12 text-warning', info:'bg-info/12 text-info' }[props.tone]))
const accentBar = computed(()=> ({ default:'from-hero-from via-hero-via to-transparent', success:'from-success to-transparent', danger:'from-danger to-transparent', warning:'from-warning to-transparent', info:'from-info to-transparent' }[props.tone]))
</script>
<template>
  <div class="relative overflow-hidden rounded-2xl border p-4 shadow-sm transition-shadow hover:shadow-md" :class="toneCls">
    <span aria-hidden="true" class="absolute inset-x-0 top-0 h-[2px] bg-gradient-to-r" :class="accentBar" />
    <div class="flex items-start justify-between gap-3">
      <div class="min-w-0 flex-1">
        <p class="text-[11px] font-semibold tracking-widest uppercase text-muted-foreground">{{ label }}</p>
        <p class="text-[22px] font-bold tracking-tight font-mono truncate mt-1">{{ value }}</p>
        <p v-if="sub" class="text-xs text-muted-foreground mt-0.5">{{ sub }}</p>
      </div>
      <div v-if="icon" class="grid size-10 place-items-center rounded-xl shrink-0 ring-1 ring-inset ring-current/10" :class="iconBg"><component :is="icon" class="size-5" /></div>
    </div>
  </div>
</template>
