<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {ArrowLeft, Bell, Check, X, Clock, User, FileText} from 'lucide-vue-next';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
const props=defineProps<{ informed:any }>();
const processing=ref(false);
function ack(){ processing.value=true; router.post(route('hrm.informed.acknowledge', props.informed.id),{},{onFinish(){processing.value=false;}}); }
function rej(){ if(!confirm('Reject?')) return; processing.value=true; router.post(route('hrm.informed.reject', props.informed.id),{},{onFinish(){processing.value=false;}}); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'HRM'},{title:'Informed', href: route('hrm.informed.index')},{title:`#${informed.id}`}]">
    <Head :title="`Informed #${informed.id}`"/>
    <PageHeader title="Pages Detail" description="Employee" :icon="FileText">
    <div class="max-w-3xl mx-auto space-y-4">
      <div class="flex items-center justify-between"><h1 class="text-xl font-bold flex items-center gap-2"><Bell class="size-5"/> Notice #{{ informed.id }} <span class="px-2.5 py-0.5 rounded-full text-xs font-medium" :class="informed.status==='Pending'?'bg-amber-100 text-amber-700': informed.status==='Acknowledged'?'bg-emerald-100 text-emerald-700': informed.status==='Rejected'?'bg-red-100 text-red-700':'bg-muted'">{{ informed.status }}</span></h1><Link :href="route('hrm.informed.index')"><Button variant="outline" size="sm"><ArrowLeft class="size-4"/> Back</Button></Link></div>
      <div class="bg-card border rounded-2xl shadow-sm p-6 space-y-4">
        <div class="grid grid-cols-2 gap-4 text-sm">
          <div><p class="text-muted-foreground flex items-center gap-1"><User class="size-3"/> Employee</p><p class="font-semibold font-mono">{{ informed.employee_id }} <span class="text-muted-foreground font-normal">{{ informed.employee }}</span></p></div>
          <div><p class="text-muted-foreground flex items-center gap-1"><Clock class="size-3"/> Date</p><p class="font-mono font-semibold">{{ informed.informed_date }}</p></div>
          <div><p class="text-muted-foreground">Type</p><p><span class="px-2 py-0.5 rounded-full text-xs border font-medium" :class="informed.informed_type==='Late Coming'?'bg-amber-50 text-amber-700 border-amber-200': informed.informed_type==='Early Out'?'bg-blue-50 text-blue-700 border-blue-200':'bg-muted'">{{ informed.informed_type }}</span> <span class="text-muted-foreground text-xs">{{ informed.expected_time ? '→ '+informed.expected_time.slice(0,5) : '' }}</span></p></div>
          <div><p class="text-muted-foreground">Status</p><p class="font-semibold">{{ informed.status }} <span v-if="informed.approver" class="text-muted-foreground font-normal">by {{ informed.approver }}</span></p></div>
          <div class="col-span-2"><p class="text-muted-foreground">Reason</p><p class="whitespace-pre-wrap">{{ informed.reason || '—' }}</p></div>
        </div>
        <div v-if="informed.status==='Pending'" class="flex gap-2 pt-2 border-t">
          <Button @click="ack" :disabled="processing" class="bg-emerald-600 hover:bg-emerald-700"><Check class="size-4"/> Acknowledge</Button>
          <Button variant="destructive" @click="rej" :disabled="processing"><X class="size-4"/> Reject</Button>
          <Link :href="route('hrm.informed.edit', informed.id)" class="ml-auto"><Button variant="outline">Edit</Button></Link>
        </div>
        <p v-else class="text-xs text-muted-foreground border-t pt-2">Decision {{ informed.approved_at ? 'on '+informed.approved_at : '' }} by {{ informed.approver || '—' }}</p>
      </div>
    </div>
      </PageHeader>
  </AdminLayout>
</template>
