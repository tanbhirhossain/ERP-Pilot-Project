<script setup lang="ts">
defineProps<{ title?: string; description?: string; icon?: any }>()
</script>
<template>
  <div class="bg-card border rounded-2xl shadow-sm overflow-hidden">
    <div v-if="title" class="px-5 py-4 border-b flex items-start justify-between gap-3 bg-muted/20">
      <div class="min-w-0">
        <h3 class="text-sm font-semibold flex items-center gap-2"><component :is="icon" v-if="icon" class="size-4 text-primary" />{{ title }}</h3>
        <p v-if="description" class="text-xs text-muted-foreground mt-0.5">{{ description }}</p>
      </div>
      <div class="shrink-0"><slot name="actions" /></div>
    </div>
    <div class="p-5"><slot /></div>
  </div>
</template>
