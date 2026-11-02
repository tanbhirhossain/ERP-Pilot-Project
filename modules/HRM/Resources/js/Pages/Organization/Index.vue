<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Building2 } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';

interface Org { id?: number; name: string; email?: string; phone?: string; address?: string; website?: string }
const props = defineProps<{ organization: Org | null }>();

const form = useForm({
  name: props.organization?.name ?? '',
  email: props.organization?.email ?? '',
  phone: props.organization?.phone ?? '',
  address: props.organization?.address ?? '',
  website: props.organization?.website ?? '',
});

function submit() {
  if (props.organization?.id) form.put(route('hrm.organizations.update'));
  else form.post(route('hrm.organizations.store'));
}
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'HRM'},{title:'Organization'}]">
    <Head title="Organization" />
    <div class="max-w-3xl mx-auto">
      <div class="mb-6">
        <h1 class="text-2xl font-bold flex items-center gap-2"><Building2 class="size-6 text-primary"/> Organization</h1>
        <p class="text-sm text-muted-foreground mt-1">Single organization profile — this is the root tenant for all HRM data.</p>
      </div>
      <div class="bg-card border rounded-xl p-6 shadow-sm">
        <form @submit.prevent="submit" class="space-y-5">
          <div class="space-y-2">
            <Label for="name">Organization Name *</Label>
            <Input id="name" v-model="form.name" placeholder="e.g. Acme Corp Ltd" />
            <p v-if="form.errors.name" class="text-sm text-destructive">{{ form.errors.name }}</p>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="space-y-2"><Label>Email</Label><Input v-model="form.email" type="email" placeholder="info@company.com"/><p v-if="form.errors.email" class="text-sm text-destructive">{{ form.errors.email }}</p></div>
            <div class="space-y-2"><Label>Phone</Label><Input v-model="form.phone" placeholder="+880..."/><p v-if="form.errors.phone" class="text-sm text-destructive">{{ form.errors.phone }}</p></div>
          </div>
          <div class="space-y-2"><Label>Address</Label><Input v-model="form.address" placeholder="Full address"/><p v-if="form.errors.address" class="text-sm text-destructive">{{ form.errors.address }}</p></div>
          <div class="space-y-2"><Label>Website</Label><Input v-model="form.website" placeholder="https://example.com"/><p v-if="form.errors.website" class="text-sm text-destructive">{{ form.errors.website }}</p></div>
          <Button type="submit" :disabled="form.processing" class="w-full sm:w-auto">{{ form.processing ? 'Saving...' : (props.organization?.id ? 'Update Organization' : 'Save Organization') }}</Button>
        </form>
      </div>
    </div>
  </AdminLayout>
</template>
