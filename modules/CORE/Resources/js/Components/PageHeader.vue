<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
interface Action { label: string; href?: string; route?: string; routeParams?: Record<string, any>; variant?: 'default' | 'outline' | 'ghost' | 'secondary'; icon?: any; onClick?: () => void; disabled?: boolean; }
interface Props { title: string; description?: string; icon?: any; actions?: Action[]; stats?: Array<{ label: string; value: string | number; sub?: string }>; }
withDefaults(defineProps<Props>(), { description: undefined, icon: undefined, actions: () => [], stats: undefined });
</script>
<template>
  <div class="space-y-5">
    <!--
      Premium hero when stats exist.
      Item 42: this used to be `bg-[#0B0F1E]` with `text-white` — a navy that no palette could
      change, in an application whose entire colour story is supposed to be the scheme the operator
      picked. It now paints the palette's hero ramp (`hero-from` -> `hero-via` -> `hero-to`) with
      `hero-ink` for every letter, so the same markup is indigo, teal, rose or graphite depending on
      the scheme, and dark mode needs no override because the ramp is defined per mode.
    -->
    <!--
      Header with figures.
      Item 42 shipped this as a full-bleed gradient ramp (hero-from -> hero-via -> hero-to) carrying
      the title, the description, the actions *and* the stats. On a screen holding three purchase
      orders, a bar of saturated indigo was the loudest object in the frame while the three rows the
      user came for sat beneath it — the page announced its own name louder than its contents.
      The ramp is now a 3px rule: it still carries the palette, it no longer competes. The title sits
      on the page, and the figures sit on a surface, where a reader can scan them.
    -->
    <div v-if="stats?.length" class="space-y-4">
      <div class="relative overflow-hidden rounded-lg border bg-card shadow-[0_1px_2px_hsl(var(--foreground)/0.04)]">
        <span aria-hidden="true" class="absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-hero-from via-hero-via to-hero-to"></span>
        <div class="flex flex-wrap items-start justify-between gap-3 p-4 pt-5">
          <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2.5">
              <h1 class="flex items-center gap-2.5 text-[19px] font-semibold tracking-tight">
                <span v-if="icon" class="grid size-8 place-items-center rounded-md bg-brand-tint text-brand-tint-ink ring-1 ring-inset ring-primary/10">
                  <component :is="icon" class="size-4" />
                </span>
                {{ title }}
              </h1>
              <slot name="badge" />
            </div>
            <p v-if="description" class="mt-1.5 max-w-2xl text-[13px] leading-relaxed text-muted-foreground">{{ description }}</p>
          </div>
          <div class="flex flex-wrap gap-2 shrink-0">
            <template v-for="(a, i) in actions" :key="i">
              <Link v-if="a.href" :href="a.href"><Button :variant="a.variant ?? 'outline'" size="sm"><component :is="a.icon" v-if="a.icon" class="size-4" />{{ a.label }}</Button></Link>
              <Link v-else-if="a.route" :href="route(a.route, a.routeParams)"><Button :variant="a.variant ?? 'outline'" size="sm"><component :is="a.icon" v-if="a.icon" class="size-4" />{{ a.label }}</Button></Link>
              <Button v-else :variant="a.variant ?? 'default'" size="sm" :disabled="a.disabled" @click="a.onClick"><component :is="a.icon" v-if="a.icon" class="size-4" />{{ a.label }}</Button>
            </template>
            <slot name="actions" />
          </div>
        </div>
        <div class="grid grid-cols-2 gap-px border-t bg-border/60 md:grid-cols-4">
          <div v-for="s in stats" :key="s.label" class="bg-card px-4 py-3">
            <p class="eyebrow">{{ s.label }}</p>
            <p class="num-display mt-1 text-[17px] font-semibold text-foreground">{{ s.value }}</p>
            <p v-if="s.sub" class="mt-0.5 text-[11px] text-muted-foreground">{{ s.sub }}</p>
          </div>
        </div>
      </div>
    </div>
    <!-- Simple header — premium minimal -->
    <div v-else class="relative flex flex-wrap items-start justify-between gap-3 overflow-hidden rounded-lg border bg-card p-4 pt-5 shadow-[0_1px_2px_hsl(var(--foreground)/0.04)]">
      <span aria-hidden="true" class="absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-hero-from via-hero-via to-hero-to opacity-80"></span>
      <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-2.5">
            <h1 class="flex items-center gap-2.5 text-[19px] font-semibold tracking-tight">
              <span v-if="icon" class="grid size-8 place-items-center rounded-md bg-brand-tint text-brand-tint-ink ring-1 ring-inset ring-primary/10"><component :is="icon" class="size-4" /></span>
              {{ title }}
            </h1>
            <slot name="badge" />
          </div>
        <p v-if="description" class="mt-1.5 max-w-3xl text-[13px] leading-relaxed text-muted-foreground">{{ description }}</p>
      </div>
      <div class="flex flex-wrap gap-2 shrink-0">
        <template v-for="(a, i) in actions" :key="i">
          <Link v-if="a.href" :href="a.href"><Button :variant="a.variant ?? 'outline'" size="sm"><component :is="a.icon" v-if="a.icon" class="size-4" />{{ a.label }}</Button></Link>
          <Link v-else-if="a.route" :href="route(a.route, a.routeParams)"><Button :variant="a.variant ?? 'outline'" size="sm"><component :is="a.icon" v-if="a.icon" class="size-4" />{{ a.label }}</Button></Link>
          <Button v-else :variant="a.variant ?? 'default'" size="sm" :disabled="a.disabled" @click="a.onClick"><component :is="a.icon" v-if="a.icon" class="size-4" />{{ a.label }}</Button>
        </template>
        <!-- Page-owned actions, verbatim: permission gates, handlers and all. -->
        <slot name="actions" />
      </div>
    </div>
    <slot />
  </div>
</template>
