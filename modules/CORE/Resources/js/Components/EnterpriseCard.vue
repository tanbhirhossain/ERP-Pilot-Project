<script setup lang="ts">
defineProps<{ title?: string; description?: string; icon?: any }>()
</script>
<template>
  <!--
    Item 42: the header washes in the palette's brand tint instead of a flat `bg-muted/20`, and the
    icon sits on a tinted plate. Both come from tokens, so the card follows the scheme the operator
    chose without this file knowing which one that is.
  -->
  <div class="bg-card border rounded-2xl shadow-sm overflow-hidden transition-shadow hover:shadow-md">
    <div v-if="title" class="px-5 py-4 border-b flex items-start justify-between gap-3 bg-gradient-to-r from-brand-tint to-transparent">
      <div class="min-w-0">
        <h3 class="text-sm font-semibold flex items-center gap-2.5">
          <span v-if="icon" class="grid size-7 place-items-center rounded-lg bg-primary/10 text-primary ring-1 ring-primary/15"><component :is="icon" class="size-4" /></span>
          {{ title }}
        </h3>
        <p v-if="description" class="text-xs text-muted-foreground mt-0.5" :class="{ 'pl-9.5': icon }">{{ description }}</p>
      </div>
      <div class="shrink-0"><slot name="actions" /></div>
    </div>
    <div class="p-5"><slot /></div>
  </div>
</template>
