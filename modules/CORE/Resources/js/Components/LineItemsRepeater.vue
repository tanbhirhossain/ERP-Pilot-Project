<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Plus, Trash2 } from 'lucide-vue-next';

defineProps<{
  title?: string;
  addLabel?: string;
  emptyText?: string;
}>();

const emit = defineEmits<{
  add: [];
}>();

defineSlots<{
  header(): unknown;
  row(props: { index: number }): unknown;
  footer(): unknown;
}>();
</script>

<template>
  <div class="rounded-xl border bg-card overflow-hidden">
    <div v-if="title" class="flex items-center justify-between px-4 py-3 border-b bg-muted/30">
      <h3 class="text-sm font-semibold">{{ title }}</h3>
      <Button type="button" variant="outline" size="sm" @click="emit('add')">
        <Plus class="size-4" /> {{ addLabel ?? 'Add Row' }}
      </Button>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-muted/40 border-b text-xs uppercase tracking-wide text-muted-foreground">
          <slot name="header" />
        </thead>
        <tbody class="divide-y">
          <slot name="row" :index="0" />
        </tbody>
        <tfoot v-if="$slots.footer" class="bg-muted/20 border-t">
          <slot name="footer" />
        </tfoot>
      </table>
    </div>
    <div v-if="emptyText" class="px-4 py-8 text-center text-sm text-muted-foreground">{{ emptyText }}</div>
  </div>
</template>
