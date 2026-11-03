<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
const form=useForm({ name:'', symbol:'', category:'unit', ratio_to_base:'1', is_active:true });
function submit(){ form.post(route('inventory.uoms.store')); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'UOMs',href:route('inventory.uoms.index')},{title:'Create'}]"><Head title="Create UOM"/><PageHeader title="Add Unit of Measure" description="Complete the form — required *" :icon="Package">
    <div class="max-w-2xl mx-auto"><h1 class="text-xl font-bold mb-4">Add Unit of Measure</h1><form @submit.prevent="submit" class="bg-card border rounded-2xl shadow-sm p-4 space-y-4"><p v-if="Object.keys(form.errors).length" class="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">{{ Object.values(form.errors)[0] }}</p><div class="space-y-2"><Label>Name *</Label><Input v-model="form.name" placeholder="Piece"/></div><div class="grid grid-cols-1 sm:grid-cols-2 gap-4"><div class="space-y-2"><Label>Symbol *</Label><Input v-model="form.symbol" placeholder="PCS"/></div><div class="space-y-2"><Label>Category</Label><select v-model="form.category" class="w-full h-9 rounded-md border bg-background px-3 text-sm"><option value="unit">Unit</option><option value="weight">Weight</option><option value="length">Length</option><option value="volume">Volume</option><option value="area">Area</option></select></div></div><div class="space-y-2"><Label>Ratio to Base</Label><Input v-model.number="form.ratio_to_base" type="number" step="0.0001"/></div><label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.is_active" class="rounded"/> Active</label><Button type="submit">Create</Button></form></div>    </PageHeader>
  </AdminLayout>
</template>
