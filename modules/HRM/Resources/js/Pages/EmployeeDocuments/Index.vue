<script setup lang="ts">
import { Head, router, Link } from '@inertiajs/vue3';
import { ref, reactive } from 'vue';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import SearchableSelect from '../../../../../CORE/Resources/js/Components/SearchableSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

import { FolderOpen, ShieldCheck, AlertTriangle, Clock, Upload, Download, Trash2, Eye } from 'lucide-vue-next';
import Pagination from '../../../../../CORE/Resources/js/Components/Pagination.vue';
import StaticTable from '../../../../../CORE/Resources/js/Components/StaticTable.vue';

defineProps<{ documents: any; stats: any }>();

const showUpload = ref(false);
const form = reactive({
  employee_id: null as any,
  document_type: 'NID',
  title: '',
  issued_date: '',
  expiry_date: '',
  remarks: '',
  file: null as File | null,
});

const types = ['NID','Passport','Birth Certificate','Photo','Appointment Letter','Contract','Experience Certificate','Education Certificate','Nominee Document','TIN','Bank Statement','Police Verification','Medical Certificate','Other'];

function onFile(e: Event) {
  const t = e.target as HTMLInputElement;
  form.file = t.files?.[0] ?? null;
}

function submit() {
  const fd = new FormData();
  Object.entries(form).forEach(([k, v]) => {
    if (v !== null && v !== '' && k !== 'file') fd.append(k, String(v));
  });
  if (form.file) fd.append('file', form.file);
  router.post(route('hrm.documents.store'), fd, {
    forceFormData: true,
    onSuccess: () => { showUpload.value = false; Object.assign(form, { employee_id: null, document_type: 'NID', title: '', issued_date: '', expiry_date: '', remarks: '', file: null }); },
  });
}

function verify(id: number, status: string) {
  const remarks = prompt(`Remarks for ${status} (optional):`) || '';
  router.post(route('hrm.documents.verify', id), { status, remarks });
}
function del(id: number) { if (confirm('Delete document? File will be removed from storage.')) router.delete(route('hrm.documents.destroy', id)); }

const badgeTone = (s: string) => ({ Verified: 'bg-emerald-500 text-white', Pending: 'bg-amber-500 text-white', Rejected: 'bg-red-500 text-white', Expired: 'bg-slate-600 text-white' }[s] || 'bg-muted');
const expiryTone = (doc: any) => {
  if (!doc.expiry_date) return 'text-muted-foreground';
  const d = new Date(doc.expiry_date);
  const diff = (d.getTime() - Date.now()) / 86400000;
  if (diff < 0) return 'text-red-600 font-bold';
  if (diff <= 30) return 'text-amber-600 font-semibold';
  return 'text-emerald-600';
};
</script>

<template>
  <AdminLayout :breadcrumbs="[{ title: 'HRM' }, { title: 'Documents Vault' }]">
    <Head title="Documents Vault — HRM" />
    <div class="max-w-7xl mx-auto space-y-6">
      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
          <h1 class="text-2xl font-bold tracking-tight flex items-center gap-2"><FolderOpen class="size-6 text-primary" /> Documents Vault</h1>
          <p class="text-sm text-muted-foreground">Enterprise-grade employee document lifecycle: upload → verify → expiry monitoring → audit. Storage: <code class="bg-muted px-1 rounded">storage/app/private/hrm-documents</code> (10MB, pdf/jpg/png/docx).</p>
        </div>
        <Button @click="showUpload = !showUpload" class="bg-primary"><Upload class="size-4 mr-2" /> {{ showUpload ? 'Close' : 'Upload Document' }}</Button>
      </div>

      <!-- Stats -->
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-card border rounded-xl p-4 flex items-center gap-3">
          <div class="size-10 rounded-lg bg-primary/10 flex items-center justify-center"><FolderOpen class="size-5 text-primary" /></div>
          <div><p class="text-xs text-muted-foreground">Total Documents</p><p class="text-xl font-bold">{{ documents.total }}</p></div>
        </div>
        <div class="bg-card border rounded-xl p-4 flex items-center gap-3">
          <div class="size-10 rounded-lg bg-emerald-500/10 flex items-center justify-center"><ShieldCheck class="size-5 text-emerald-600" /></div>
          <div><p class="text-xs text-muted-foreground">Verified</p><p class="text-xl font-bold">{{ documents.data.filter((d:any)=>d.verification_status==='Verified').length }}</p></div>
        </div>
        <div class="bg-card border rounded-xl p-4 flex items-center gap-3">
          <div class="size-10 rounded-lg bg-amber-500/10 flex items-center justify-center"><Clock class="size-5 text-amber-600" /></div>
          <div><p class="text-xs text-muted-foreground">Expiring ≤30d</p><p class="text-xl font-bold text-amber-600">{{ stats.expiring_soon }}</p></div>
        </div>
        <div class="bg-card border rounded-xl p-4 flex items-center gap-3">
          <div class="size-10 rounded-lg bg-red-500/10 flex items-center justify-center"><AlertTriangle class="size-5 text-red-600" /></div>
          <div><p class="text-xs text-muted-foreground">Expired</p><p class="text-xl font-bold text-red-600">{{ stats.expired }}</p></div>
        </div>
      </div>

      <!-- Upload Panel -->
      <div v-if="showUpload" class="bg-card border rounded-xl p-5 shadow-sm space-y-4">
        <h3 class="font-semibold flex items-center gap-2"><Upload class="size-4" /> Upload Document</h3>
        <div class="grid md:grid-cols-3 gap-4">
          <div class="space-y-1"><Label>Employee *</Label><SearchableSelect v-model="form.employee_id" endpoint="/hrm/api/options/employees" placeholder="Select employee" /></div>
          <div class="space-y-1"><Label>Document Type *</Label><select v-model="form.document_type" class="h-9 w-full rounded-md border bg-background px-3 text-sm"><option v-for="t in types" :key="t" :value="t">{{ t }}</option></select></div>
          <div class="space-y-1"><Label>Title *</Label><Input v-model="form.title" placeholder="e.g., NID Front — Rahman" /></div>
          <div class="space-y-1"><Label>Issued Date</Label><Input type="date" v-model="form.issued_date" /></div>
          <div class="space-y-1"><Label>Expiry Date (alerts)</Label><Input type="date" v-model="form.expiry_date" /></div>
          <div class="space-y-1"><Label>File * (10MB)</Label><Input type="file" @change="onFile" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx" /></div>
          <div class="md:col-span-3 space-y-1"><Label>Remarks</Label><Input v-model="form.remarks" placeholder="Optional notes" /></div>
        </div>
        <div class="flex justify-end gap-2"><Button variant="outline" @click="showUpload=false">Cancel</Button><Button @click="submit" class="bg-emerald-600 hover:bg-emerald-700">Upload & Save</Button></div>
      </div>

      <!-- Table -->
      <div class="bg-card border rounded-xl overflow-hidden">
        <div class="p-4 flex items-center justify-between border-b">
          <h3 class="font-semibold text-sm">All Documents — {{ documents.total }} records</h3>
          <span class="text-xs text-muted-foreground">Expiry badges: <span class="text-red-600 font-bold">overdue</span> • <span class="text-amber-600 font-semibold">≤30 days</span> • <span class="text-emerald-600">valid</span></span>
        </div>
        <StaticTable flush>
            <thead class="bg-muted/50 text-xs text-muted-foreground">
              <tr><th class="text-left p-3">Employee</th><th class="text-left p-3">Document</th><th class="text-left p-3">Type</th><th class="p-3">Expiry</th><th class="p-3">Status</th><th class="p-3">File</th><th class="p-3 text-right">Actions</th></tr>
            </thead>
            <tbody>
              <tr v-for="doc in documents.data" :key="doc.id" class="border-t hover:bg-muted/30">
                <td class="p-3"><div class="font-medium">{{ doc.employee?.full_name || doc.employee_id }}</div><div class="text-xs text-muted-foreground font-mono">{{ doc.employee_id }}</div></td>
                <td class="p-3"><div class="font-medium truncate max-w-[220px]">{{ doc.title }}</div><div class="text-xs text-muted-foreground">{{ doc.file_name }} • {{ (doc.file_size/1024).toFixed(0) }} KB</div></td>
                <td class="p-3"><span class="px-2 py-1 rounded bg-muted text-xs font-medium">{{ doc.document_type }}</span></td>
                <td class="p-3 text-center text-sm" :class="expiryTone(doc)">{{ doc.expiry_date ? new Date(doc.expiry_date).toLocaleDateString() : '—' }}</td>
                <td class="p-3 text-center"><span :class="['px-2 py-1 rounded-full text-xs font-medium', badgeTone(doc.verification_status)]">{{ doc.verification_status }}</span></td>
                <td class="p-3 text-center"><a :href="route('hrm.documents.download', doc.id)" class="inline-flex items-center gap-1 text-primary hover:underline text-xs"><Download class="size-3" /> Download</a></td>
                <td class="p-3">
                  <div class="flex justify-end gap-1">
                    <Button v-if="doc.verification_status==='Pending'" size="sm" variant="outline" class="h-7 text-xs" @click="verify(doc.id,'Verified')">✓ Verify</Button>
                    <Button v-if="doc.verification_status==='Pending'" size="sm" variant="ghost" class="h-7 text-xs text-red-600" @click="verify(doc.id,'Rejected')">✕ Reject</Button>
                    <Link :href="route('hrm.employees.show', doc.employee?.id)" v-if="doc.employee?.id"><Button size="sm" variant="ghost" class="h-7"><Eye class="size-3" /></Button></Link>
                    <Button size="sm" variant="ghost" class="h-7 text-red-600" @click="del(doc.id)"><Trash2 class="size-3" /></Button>
                  </div>
                </td>
              </tr>
              <tr v-if="!documents.data.length"><td colspan="7" class="p-8 text-center text-sm text-muted-foreground">No documents yet — upload the first NID / appointment letter to start the vault.</td></tr>
            </tbody>
          </StaticTable>
        <Pagination :links="documents.links" :total="documents.total" />
      </div>

    </div>
  </AdminLayout>
</template>
