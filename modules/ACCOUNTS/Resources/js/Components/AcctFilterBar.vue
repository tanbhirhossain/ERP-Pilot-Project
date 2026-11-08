<script setup lang="ts">
defineProps<{ description?: string }>();
</script>
<template>
  <div class="bg-card border rounded-2xl p-4 shadow-sm flex flex-wrap items-end gap-3">
    <slot />
    <div v-if="description" class="text-xs text-muted-foreground ml-auto self-center hidden sm:block">{{ description }}</div>
  </div>
</template>
