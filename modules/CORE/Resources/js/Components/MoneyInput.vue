<script setup lang="ts">
import { computed } from 'vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = withDefaults(defineProps<{
  modelValue: number | null | string;
  label?: string;
  placeholder?: string;
  currency?: string;
  disabled?: boolean;
  required?: boolean;
  error?: string;
  id?: string;
  min?: number;
  max?: number;
}>(), {
  label: undefined,
  placeholder: '0.00',
  currency: '৳',
  disabled: false,
  required: false,
  error: undefined,
  id: undefined,
  min: undefined,
  max: undefined,
});

const emit = defineEmits<{
  'update:modelValue': [value: number | null];
}>();

const inputId = computed(() => props.id ?? `money-${Math.random().toString(36).slice(2, 8)}`);

function onInput(e: Event) {
  const raw = (e.target as HTMLInputElement).value.replace(/[^0-9.\-]/g, '');
  if (raw === '' || raw === '-' ) {
    emit('update:modelValue', null);
    return;
  }
  const num = Number(raw);
  emit('update:modelValue', Number.isNaN(num) ? null : num);
}

const displayValue = computed(() => {
  if (props.modelValue === null || props.modelValue === '' as unknown as number) return '';
  return String(props.modelValue);
});
</script>

<template>
  <div class="space-y-1.5">
    <Label v-if="label" :for="inputId" class="text-sm font-medium">
      {{ label }} <span v-if="required" class="text-destructive">*</span>
    </Label>
    <div class="relative">
      <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground font-medium pointer-events-none">{{ currency }}</span>
      <Input
        :id="inputId"
        :model-value="displayValue"
        type="text"
        inputmode="decimal"
        :placeholder="placeholder"
        :disabled="disabled"
        :class="['pl-7', error ? 'border-destructive focus-visible:ring-destructive' : '']"
        @input="onInput"
      />
    </div>
    <p v-if="error" class="text-xs text-destructive">{{ error }}</p>
  </div>
</template>
