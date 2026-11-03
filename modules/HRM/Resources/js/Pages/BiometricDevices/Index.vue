<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2, Wifi, WifiOff, Play } from 'lucide-vue-next';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import ConfirmDialog from '../../../../../CORE/Resources/js/Components/ConfirmDialog.vue';
import DataTable, { type DataTableColumn } from '../../../../../CORE/Resources/js/Components/DataTable.vue';
import type { Paginator } from '../../../../../CORE/Resources/js/types';
import { formatDate } from '../../../../../CORE/Resources/js/Utils/format';
interface Row { id:number; name:string; ip_address:string; port:number; serial_number?:string; location?:string; status:string; is_enabled:boolean; auto_sync_enabled:boolean; last_sync_at?:string }
defineProps<{ devices: Paginator<Row> }>();
const columns: DataTableColumn[] = [
  {key:'name',label:'Device',sortable:true},{key:'ip_address',label:'IP Address',sortable:true},{key:'location',label:'Location'},{key:'status',label:'Status',align:'center'},{key:'last_sync_at',label:'Last Sync',sortable:true},{key:'actions',label:'',align:'right',exportable:false,width:'8rem'}
];
const confirmOpen=ref(false), pending=ref<Row|null>(null), processing=ref(false);
function askDelete(r:Record<string,unknown>){ pending.value=r as unknown as Row; confirmOpen.value=true; }
function confirmDelete(){ if(!pending.value) return; processing.value=true; router.delete(route('hrm.devices.destroy',pending.value.id),{preserveScroll:true,onFinish(){processing.value=false;confirmOpen.value=false;pending.value=null;}}); }
function testDevice(id:number){ router.post(route('hrm.devices.test',id),{},{preserveScroll:true}); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'HRM'},{title:'Biometric Devices'}]">
    <Head title="Biometric Devices"/>
    <PageHeader title="Biometric Devices" description="ZKTeCo machines — configure IP/port & sync settings." :icon="Wifi" :actions="[{ label:'Add Device', route:'hrm.devices.create', icon: Plus }]">
    <DataTable route-name="hrm.devices.index" title="Biometric Devices" :columns="columns" :rows="(devices.data as unknown as Record<string,unknown>[])" :meta="devices.meta">
      <template #cell-name="{row}"><div><p class="font-semibold flex items-center gap-1.5"><Fingerprint class="size-4 text-muted-foreground"/>{{ row.name }}</p><p class="text-xs text-muted-foreground">{{ row.port }} • {{ row.serial_number || 'No SN' }}</p></div></template>
      <template #cell-ip_address="{row}"><span class="font-mono text-sm bg-muted px-1.5 py-0.5 rounded">{{ row.ip_address }}:{{ row.port }}</span></template>
      <template #cell-location="{row}"><span class="text-sm">{{ row.location || '—' }}</span></template>
      <template #cell-status="{row}"><span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium" :class="row.status==='Active'?'bg-emerald-500/10 text-emerald-600': row.status==='Maintenance'?'bg-amber-500/10 text-amber-600':'bg-zinc-500/10 text-zinc-600'"><Wifi v-if="row.is_enabled" class="size-3"/><WifiOff v-else class="size-3"/>{{ row.status }}</span><span v-if="row.auto_sync_enabled" class="ml-1 inline-flex px-1.5 py-0.5 rounded bg-blue-500/10 text-blue-600 text-[10px]">AUTO</span></template>
      <template #cell-last_sync_at="{row}"><span class="text-sm text-muted-foreground">{{ row.last_sync_at ? formatDate(row.last_sync_at) : 'Never' }}</span></template>
      <template #cell-actions="{row}"><div class="flex justify-end gap-1">
        <button @click="testDevice(row.id)" class="size-8 grid place-items-center rounded-md hover:bg-blue-500/10 hover:text-blue-600" title="Test connection"><Play class="size-4"/></button>
        <Link :href="route('hrm.devices.edit',row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent"><Pencil class="size-4"/></Link>
        <button @click="askDelete(row)" class="size-8 grid place-items-center rounded-md hover:bg-destructive/10 hover:text-destructive"><Trash2 class="size-4"/></button>
      </div></template>
    </DataTable>
    <ConfirmDialog v-model:open="confirmOpen" :processing="processing" title="Delete device?" :description="`Delete ${pending?.name}?`" confirm-label="Delete" @confirm="confirmDelete"/>
      </PageHeader>
  </AdminLayout>
</template>
<script lang="ts">import { Fingerprint } from 'lucide-vue-next';</script>
