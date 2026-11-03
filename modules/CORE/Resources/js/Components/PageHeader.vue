<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
interface Action { label: string; href?: string; route?: string; routeParams?: Record<string, any>; variant?: 'default' | 'outline' | 'ghost' | 'secondary'; icon?: any; onClick?: () => void; disabled?: boolean; }
interface Props { title: string; description?: string; icon?: any; actions?: Action[]; stats?: Array<{ label: string; value: string | number; sub?: string }>; }
withDefaults(defineProps<Props>(), { description: undefined, icon: undefined, actions: () => [], stats: undefined });
</script>
<template>
  <div class="space-y-5">
    <!-- Premium dark glass header when stats exist -->
    <div v-if="stats?.length" class="relative overflow-hidden rounded-2xl border border-white/10 bg-[#0B0F1E] p-6 text-white shadow-[0_16px_40px_hsl(var(--foreground)/0.12),inset_0_1px_0_hsl(var(--foreground)/0.06)]">
      <!-- subtle grid + glow -->
      <div class="pointer-events-none absolute inset-0 bg-[linear-gradient(to_right,hsl(var(--foreground)/0.05)_1px,transparent_1px),linear-gradient(to_bottom,hsl(var(--foreground)/0.05)_1px,transparent_1px)] bg-[size:24px_24px] opacity-20"></div>
      <div class="pointer-events-none absolute -top-24 -right-24 size-80 rounded-full bg-indigo-500/20 blur-[80px]"></div>
      <div class="pointer-events-none absolute -bottom-24 -left-24 size-80 rounded-full bg-violet-500/15 blur-[80px]"></div>
      <div class="relative flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
          <h1 class="flex items-center gap-3 text-[22px] font-bold tracking-tight"><span class="grid size-9 place-items-center rounded-xl bg-white/10 ring-1 ring-white/15 backdrop-blur"><component :is="icon" v-if="icon" class="size-5 text-white" /><span v-else class="text-sm">◆</span></span> {{ title }}</h1>
          <p v-if="description" class="mt-2 max-w-2xl text-sm leading-relaxed text-white/65">{{ description }}</p>
        </div>
        <div class="flex flex-wrap gap-2 shrink-0">
          <template v-for="(a, i) in actions" :key="i">
            <Link v-if="a.href" :href="a.href"><Button :variant="a.variant ?? 'secondary'" size="sm" class="rounded-full shadow-sm"><component :is="a.icon" v-if="a.icon" class="size-4" />{{ a.label }}</Button></Link>
            <Link v-else-if="a.route" :href="route(a.route, a.routeParams)"><Button :variant="a.variant ?? 'secondary'" size="sm" class="rounded-full shadow-sm"><component :is="a.icon" v-if="a.icon" class="size-4" />{{ a.label }}</Button></Link>
            <Button v-else :variant="a.variant ?? 'secondary'" size="sm" class="rounded-full shadow-sm" :disabled="a.disabled" @click="a.onClick"><component :is="a.icon" v-if="a.icon" class="size-4" />{{ a.label }}</Button>
          </template>
        </div>
      </div>
      <div class="relative mt-6 grid grid-cols-2 gap-3 md:grid-cols-4">
        <div v-for="s in stats" :key="s.label" class="rounded-xl border border-white/10 bg-white/[0.07] p-3.5 backdrop-blur-md hover:bg-white/[0.09] transition-colors">
          <p class="text-[11px] font-semibold tracking-widest uppercase text-white/50">{{ s.label }}</p>
          <p class="mt-1 text-[15px] font-bold tracking-tight text-white">{{ s.value }}</p>
          <p v-if="s.sub" class="text-xs text-white/60">{{ s.sub }}</p>
        </div>
      </div>
    </div>
    <!-- Simple header — premium minimal -->
    <div v-else class="flex flex-wrap items-start justify-between gap-3 rounded-2xl border bg-card p-4 shadow-sm">
      <div class="min-w-0">
        <h1 class="flex items-center gap-2.5 text-xl font-bold tracking-tight"><span class="grid size-8 place-items-center rounded-lg bg-primary text-primary-foreground shadow-sm"><component :is="icon" v-if="icon" class="size-4" /></span> {{ title }}</h1>
        <p v-if="description" class="mt-1 text-sm text-muted-foreground">{{ description }}</p>
      </div>
      <div class="flex flex-wrap gap-2 shrink-0">
        <template v-for="(a, i) in actions" :key="i">
          <Link v-if="a.href" :href="a.href"><Button :variant="a.variant ?? 'outline'" size="sm" class="rounded-full"><component :is="a.icon" v-if="a.icon" class="size-4" />{{ a.label }}</Button></Link>
          <Link v-else-if="a.route" :href="route(a.route, a.routeParams)"><Button :variant="a.variant ?? 'outline'" size="sm" class="rounded-full"><component :is="a.icon" v-if="a.icon" class="size-4" />{{ a.label }}</Button></Link>
          <Button v-else :variant="a.variant ?? 'default'" size="sm" class="rounded-full" :disabled="a.disabled" @click="a.onClick"><component :is="a.icon" v-if="a.icon" class="size-4" />{{ a.label }}</Button>
        </template>
      </div>
    </div>
    <slot />
  </div>
</template>
