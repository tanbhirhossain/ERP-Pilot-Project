<script setup lang="ts">
defineProps<{ title?: string; hint?: string; icon?: any }>();
</script>
<template>
  <div class="py-12 flex flex-col items-center text-center">
    <div class="size-14 rounded-2xl bg-muted grid place-items-center border border-dashed"><component :is="icon" v-if="icon" class="size-6 text-muted-foreground" /><span v-else class="text-lg">∅</span></div>
    <p class="mt-3 text-sm font-semibold">{{ title ?? 'Nothing here yet' }}</p>
    <p v-if="hint" class="text-xs text-muted-foreground max-w-sm mt-1">{{ hint }}</p>
    <div class="mt-4"><slot /></div>
  </div>
</template>
