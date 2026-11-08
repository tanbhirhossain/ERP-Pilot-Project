<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {ArrowLeft, ArrowLeftRight, Check, X, Clock, User, FileText} from 'lucide-vue-next';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
const props = defineProps<{ swap: any }>();
const processing=ref(false);
function approve(){ processing.value=true; router.post(route('hrm.swaps.approve', props.swap.id), {}, { onFinish(){ processing.value=false; }}); }
function reject(){ if(!confirm('Reject?')) return; processing.value=true; router.post(route('hrm.swaps.reject', props.swap.id), {}, { onFinish(){ processing.value=false; }}); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Roster' }, { title: 'Roster Swaps', href: route('hrm.swaps.index') }, { title: `#${swap.id}` }]">
    <Head :title="`Swap #${swap.id}`"/>
    <PageHeader title="Pages Detail" description="Swap Date" :icon="FileText">
    <div class="space-y-4">
      <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold flex items-center gap-2"><ArrowLeftRight class="size-5"/> Swap #{{ swap.id }} <span class="px-2.5 py-0.5 rounded-full text-xs font-medium" :class="swap.status==='Pending'?'bg-amber-100 text-amber-700': swap.status==='Approved'?'bg-emerald-100 text-emerald-700': swap.status==='Rejected'?'bg-red-100 text-red-700':'bg-muted'">{{ swap.status }}</span></h1>
        <Link :href="route('hrm.swaps.index')"><Button variant="outline" size="sm"><ArrowLeft class="size-4"/> Back</Button></Link>
      </div>
      <div class="bg-card border rounded-xl shadow-sm p-6 space-y-4">
        <div class="grid grid-cols-2 gap-4 text-sm">
          <div><p class="text-muted-foreground flex items-center gap-1"><Clock class="size-3"/> Swap Date</p><p class="font-mono font-semibold">{{ swap.swap_date }}</p></div>
          <div><p class="text-muted-foreground">Status</p><p class="font-semibold">{{ swap.status }}</p></div>
          <div><p class="text-muted-foreground flex items-center gap-1"><User class="size-3"/> Requester</p><p class="font-semibold">{{ swap.requester?.name }} <span class="font-mono text-muted-foreground">({{ swap.requester_employee_id }})</span></p></div>
          <div><p class="text-muted-foreground">Target</p><p class="font-semibold">{{ swap.target?.name || '—' }} <span v-if="swap.target_employee_id" class="font-mono text-muted-foreground">({{ swap.target_employee_id }})</span></p></div>
          <div><p class="text-muted-foreground">Requested Shift</p><p class="font-semibold">{{ swap.requestedShift?.shift_name || '—' }}</p></div>
          <div><p class="text-muted-foreground">Target Shift</p><p class="font-semibold">{{ swap.targetShift?.shift_name || '—' }}</p></div>
          <div class="col-span-2"><p class="text-muted-foreground">Reason</p><p class="whitespace-pre-wrap">{{ swap.reason || '—' }}</p></div>
        </div>
        <div v-if="swap.status==='Pending'" class="flex gap-2 pt-2 border-t">
          <Button @click="approve" :disabled="processing" class="bg-emerald-600 hover:bg-emerald-700"><Check class="size-4"/> Approve & Exchange</Button>
          <Button variant="destructive" @click="reject" :disabled="processing"><X class="size-4"/> Reject</Button>
        </div>
        <p v-else class="text-xs text-muted-foreground border-t pt-2">Decision by {{ swap.approver?.name || swap.approved_by || '—' }} on {{ swap.updated_at }}</p>
      </div>
    </div>
      </PageHeader>
  </AdminLayout>
</template>
