<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Link } from '@inertiajs/vue3';
interface Props {
  icon?: any;
  title: string;
  description?: string;
  actionLabel?: string;
  actionHref?: string;
  actionRoute?: string;
}
withDefaults(defineProps<Props>(), {
  icon: undefined,
  description: undefined,
  actionLabel: undefined,
  actionHref: undefined,
  actionRoute: undefined,
});
</script>
<template>
  <div class="rounded-2xl border border-dashed p-10 text-center">
    <div v-if="icon" class="mx-auto grid size-12 place-items-center rounded-full bg-muted">
      <component :is="icon" class="size-6 text-muted-foreground" />
    </div>
    <h3 class="mt-3 font-semibold">{{ title }}</h3>
    <p v-if="description" class="mt-1 text-sm text-muted-foreground max-w-md mx-auto">{{ description }}</p>
    <div v-if="actionLabel && (actionHref || actionRoute)" class="mt-4">
      <Link v-if="actionHref" :href="actionHref"><Button>{{ actionLabel }}</Button></Link>
      <Link v-else-if="actionRoute" :href="route(actionRoute)"><Button>{{ actionLabel }}</Button></Link>
    </div>
    <slot />
  </div>
</template>
