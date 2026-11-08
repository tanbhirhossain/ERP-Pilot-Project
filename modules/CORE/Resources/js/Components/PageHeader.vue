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
    <div v-if="stats?.length" class="relative overflow-hidden rounded-xl border border-hero-ink/15 bg-gradient-to-br from-hero-from via-hero-via to-hero-to p-6 text-hero-ink shadow-[0_18px_44px_hsl(var(--foreground)/0.16),inset_0_1px_0_hsl(var(--hero-ink)/0.14)]">
      <!-- subtle grid + glow -->
      <!-- grid + two glow orbs, all from tokens: the ink grid reads on the ramp in both modes -->
      <div class="pointer-events-none absolute inset-0 bg-[linear-gradient(to_right,hsl(var(--hero-ink)/0.07)_1px,transparent_1px),linear-gradient(to_bottom,hsl(var(--hero-ink)/0.07)_1px,transparent_1px)] bg-[size:24px_24px] opacity-40"></div>
      <div class="pointer-events-none absolute -top-24 -right-24 size-80 rounded-full bg-hero-glow/30 blur-[80px]"></div>
      <div class="pointer-events-none absolute -bottom-24 -left-24 size-80 rounded-full bg-hero-glow/15 blur-[90px]"></div>
      <div class="relative flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
          <div class="flex flex-wrap items-center gap-3">
            <h1 class="flex items-center gap-3 text-[22px] font-bold tracking-tight"><span class="grid size-9 place-items-center rounded-xl bg-hero-ink/15 ring-1 ring-hero-ink/25 backdrop-blur"><component :is="icon" v-if="icon" class="size-5 text-hero-ink" /><span v-else class="text-sm">◆</span></span> {{ title }}</h1>
            <slot name="badge" />
          </div>
          <p v-if="description" class="mt-2 max-w-2xl text-sm leading-relaxed text-hero-ink/70">{{ description }}</p>
        </div>
        <div class="flex flex-wrap gap-2 shrink-0">
          <template v-for="(a, i) in actions" :key="i">
            <Link v-if="a.href" :href="a.href"><Button :variant="a.variant ?? 'secondary'" size="sm" class="rounded-full shadow-sm"><component :is="a.icon" v-if="a.icon" class="size-4" />{{ a.label }}</Button></Link>
            <Link v-else-if="a.route" :href="route(a.route, a.routeParams)"><Button :variant="a.variant ?? 'secondary'" size="sm" class="rounded-full shadow-sm"><component :is="a.icon" v-if="a.icon" class="size-4" />{{ a.label }}</Button></Link>
            <Button v-else :variant="a.variant ?? 'secondary'" size="sm" class="rounded-full shadow-sm" :disabled="a.disabled" @click="a.onClick"><component :is="a.icon" v-if="a.icon" class="size-4" />{{ a.label }}</Button>
          </template>
          <!-- Page-owned actions, verbatim: permission gates, handlers and all. -->
          <slot name="actions" />
        </div>
      </div>
      <div class="relative mt-6 grid grid-cols-2 gap-3 md:grid-cols-4">
        <div v-for="s in stats" :key="s.label" class="rounded-xl border border-hero-ink/15 bg-hero-ink/[0.08] p-3.5 backdrop-blur-md transition-colors hover:bg-hero-ink/[0.12]">
          <p class="text-[11px] font-semibold tracking-widest uppercase text-hero-ink/55">{{ s.label }}</p>
          <p class="mt-1 text-[15px] font-bold tracking-tight text-hero-ink">{{ s.value }}</p>
          <p v-if="s.sub" class="text-xs text-hero-ink/65">{{ s.sub }}</p>
        </div>
      </div>
    </div>
    <!-- Simple header — premium minimal -->
    <div v-else class="relative flex flex-wrap items-start justify-between gap-3 overflow-hidden rounded-xl border bg-card p-4 shadow-sm">
      <span aria-hidden="true" class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-hero-from via-hero-via to-transparent"></span>
      <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-2.5">
            <h1 class="flex items-center gap-2.5 text-xl font-bold tracking-tight"><span class="grid size-8 place-items-center rounded-lg bg-gradient-to-br from-hero-from to-hero-via text-hero-ink shadow-sm ring-1 ring-hero-ink/10"><component :is="icon" v-if="icon" class="size-4" /></span> {{ title }}</h1>
            <slot name="badge" />
          </div>
        <p v-if="description" class="mt-1 text-sm text-muted-foreground">{{ description }}</p>
      </div>
      <div class="flex flex-wrap gap-2 shrink-0">
        <template v-for="(a, i) in actions" :key="i">
          <Link v-if="a.href" :href="a.href"><Button :variant="a.variant ?? 'outline'" size="sm" class="rounded-full"><component :is="a.icon" v-if="a.icon" class="size-4" />{{ a.label }}</Button></Link>
          <Link v-else-if="a.route" :href="route(a.route, a.routeParams)"><Button :variant="a.variant ?? 'outline'" size="sm" class="rounded-full"><component :is="a.icon" v-if="a.icon" class="size-4" />{{ a.label }}</Button></Link>
          <Button v-else :variant="a.variant ?? 'default'" size="sm" class="rounded-full" :disabled="a.disabled" @click="a.onClick"><component :is="a.icon" v-if="a.icon" class="size-4" />{{ a.label }}</Button>
        </template>
        <!-- Page-owned actions, verbatim: permission gates, handlers and all. -->
        <slot name="actions" />
      </div>
    </div>
    <slot />
  </div>
</template>
