<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FileClock } from 'lucide-vue-next';
import Pagination from '@core/Components/Pagination.vue';
import StaticTable from '@core/Components/StaticTable.vue';
import { confirmDialog, promptDialog } from '@core/Composables/useConfirm';

const props = defineProps<{
    regularizations: any;
    /** The server's own list, so the picker can never offer a type the validator rejects. */
    types: string[];
    canManage: boolean;
    canApprove: boolean;
}>();

const showCreate = ref(false);
const page = usePage<{ flash?: { error?: string | null } }>();

// useForm, not reactive(): the request form is validated server-side (the reason alone must be 10
// characters) and a `reactive` object threw the errors away. The click looked dead.
const form = useForm({
    employee_id: null as number | string | null,
    duty_date: '',
    regularization_type: props.types[0] ?? 'Missing In',
    assigned_shift_id: null as number | string | null,
    requested_in_time: '',
    requested_out_time: '',
    reason: '',
});

function submit() {
    form.post(route('hrm.regularizations.store'), {
        preserveScroll: true,
        onSuccess: () => {
            if (! page.props.flash?.error) {
                form.reset();
                showCreate.value = false;
            }
        },
    });
}

/** A review without a reason is the same audit hole the request form guards against. */
async function review(r: any, decision: 'Approved' | 'Rejected') {
    const verb = decision === 'Approved' ? 'Approve' : 'Reject';

    const remarks = await promptDialog({
        title: `${verb} ${r.regularization_no}?`,
        description: `${r.employee?.full_name || r.employee_id} · ${r.duty_date?.slice(0, 10)} · ${r.regularization_type}`,
        inputLabel: 'Remarks (recommended)',
        inputType: 'textarea',
        required: false,
        confirmLabel: verb,
        danger: decision === 'Rejected',
    });

    if (remarks === null) {
        return;
    }

    router.post(route('hrm.regularizations.review', r.id), { decision, review_remarks: remarks }, { preserveScroll: true });
}

async function cancel(r: any) {
    if (! await confirmDialog({ title: `Withdraw ${r.regularization_no}?`, description: 'The day keeps whatever the punches recorded.' })) {
        return;
    }

    router.post(route('hrm.regularizations.cancel', r.id), {}, { preserveScroll: true });
}

const tone = (s: string) => ({ Pending: 'bg-amber-500 text-white', Approved: 'bg-emerald-500 text-white', Rejected: 'bg-red-500 text-white', Cancelled: 'bg-zinc-500 text-white' }[s] || 'bg-muted');

// Approve/Reject render only for someone who holds `hrm.regularizations.approve`. The Manager role does
// not — it can file and view, not adjudicate — so showing the buttons meant a row of instant 403s.
const pending = (r: any) => r.status === 'Pending';
const canReview = computed(() => props.canApprove);
const canWithdraw = computed(() => props.canManage);
</script>

<template>
  <AdminLayout :breadcrumbs="[{ title: 'Attendance' }, { title: 'Regularizations' }]">
    <Head title="Attendance Regularizations — HRM"/>
    <div class="max-w-7xl mx-auto space-y-6">
      <PageHeader title="Attendance Regularization" description="Employee disputes Missing In/Out, Wrong Shift → Manager/HR approves → daily record patched + marked regularized (no more manual DB edits)." :icon="FileClock">
          <template #actions>
              <Button v-if="canManage" @click="showCreate=!showCreate" class="bg-primary">{{ showCreate?'Close':'+ Request Correction' }}</Button>
          </template>
      </PageHeader>
      <div v-if="showCreate" class="bg-card border rounded-xl p-5 space-y-4">
        <h3 class="font-semibold">New Regularization Request</h3>
        <div class="grid md:grid-cols-3 gap-4">
          <div class="space-y-1"><Label>Employee *</Label><SearchableSelect v-model="form.employee_id" endpoint="/hrm/api/options/employees" placeholder="Employee"/><p v-if="form.errors.employee_id" class="text-xs text-red-600">{{ form.errors.employee_id }}</p></div>
          <div class="space-y-1"><Label>Duty Date *</Label><Input type="date" v-model="form.duty_date"/><p v-if="form.errors.duty_date" class="text-xs text-red-600">{{ form.errors.duty_date }}</p></div>
          <div class="space-y-1"><Label>Type *</Label><select v-model="form.regularization_type" class="h-9 w-full rounded-md border bg-background px-3 text-sm"><option v-for="t in types" :key="t" :value="t">{{ t }}</option></select><p v-if="form.errors.regularization_type" class="text-xs text-red-600">{{ form.errors.regularization_type }}</p></div>
          <div class="space-y-1"><Label>Shift (if Wrong Shift)</Label><SearchableSelect v-model="form.assigned_shift_id" endpoint="/hrm/api/options/shifts" placeholder="Roster shift"/></div>
          <div class="space-y-1"><Label>Requested In (HH:mm)</Label><Input v-model="form.requested_in_time" placeholder="09:00"/><p v-if="form.errors.requested_in_time" class="text-xs text-red-600">{{ form.errors.requested_in_time }}</p></div>
          <div class="space-y-1"><Label>Requested Out (HH:mm)</Label><Input v-model="form.requested_out_time" placeholder="18:00"/><p v-if="form.errors.requested_out_time" class="text-xs text-red-600">{{ form.errors.requested_out_time }}</p></div>
          <div class="md:col-span-3 space-y-1">
            <Label>Reason *</Label>
            <Input v-model="form.reason" placeholder="Biometric malfunction / on-duty visit"/>
            <p v-if="form.errors.reason" class="text-xs text-red-600">{{ form.errors.reason }}</p>
            <p v-else class="text-xs text-muted-foreground">At least 10 characters — it is the audit trail for a change payroll can see.</p>
          </div>
        </div>
        <div class="flex justify-end"><Button @click="submit" class="bg-emerald-600 hover:bg-emerald-700">Submit for Approval</Button></div>
      </div>
      <div class="bg-card border rounded-xl overflow-hidden">
        <div class="p-4 border-b"><h3 class="font-semibold text-sm">Requests — {{ regularizations.total }}</h3></div>
        <StaticTable flush>
            <thead class="bg-muted/50 text-xs text-muted-foreground"><tr><th class="text-left p-3">Reg No</th><th class="text-left p-3">Employee</th><th class="text-left p-3">Date/Type</th><th class="p-3">Requested</th><th class="p-3">Status</th><th class="p-3 text-right">Action</th></tr></thead>
            <tbody>
              <tr v-for="r in regularizations.data" :key="r.id" class="border-t hover:bg-muted/30">
                <td class="p-3 font-mono font-bold text-xs">{{ r.regularization_no }}</td>
                <td class="p-3"><div class="font-medium text-sm">{{ r.employee?.full_name||r.employee_id }}</div><div class="text-xs text-muted-foreground font-mono">{{ r.employee_id }}</div></td>
                <td class="p-3"><div class="text-xs font-medium">{{ r.duty_date?.slice(0,10) }}</div><div class="text-xs"><span class="px-2 py-0.5 rounded bg-muted">{{ r.regularization_type }}</span></div></td>
                <td class="p-3 text-center text-xs">{{ r.requested_in_time||'—' }} → {{ r.requested_out_time||'—' }}</td>
                <td class="p-3 text-center"><span :class="['px-2 py-1 rounded-full text-xs font-medium', tone(r.status)]">{{ r.status }}</span></td>
                <td class="p-3"><div class="flex justify-end gap-1">
                  <Button v-if="pending(r) && canReview" size="sm" class="h-7 bg-emerald-600 hover:bg-emerald-700 text-xs" @click="review(r,'Approved')">Approve</Button>
                  <Button v-if="pending(r) && canReview" size="sm" variant="ghost" class="h-7 text-xs text-red-600" @click="review(r,'Rejected')">Reject</Button>
                  <Button v-if="pending(r) && canWithdraw && !canReview" size="sm" variant="outline" class="h-7 text-xs" @click="cancel(r)">Withdraw</Button>
                  <span v-if="pending(r) && !canReview && !canWithdraw" class="text-xs text-muted-foreground">Awaiting a reviewer</span>
                </div></td>
              </tr>
              <tr v-if="!regularizations.data.length"><td colspan="6" class="p-8 text-center text-sm text-muted-foreground">No regularization requests — employee disputes go here instead of DB manual fix.</td></tr>
            </tbody>
          </StaticTable>
        <Pagination :links="regularizations.links" :total="regularizations.total" />
      </div>
      <p class="text-xs text-muted-foreground">On <b>Approved</b>, <code>attendance_dailies</code> is patched: the requested times are written, hours recalculated, <code>is_regularized=true</code> + audit trail. A day that already had punches keeps the status the engine gave it. Prevents payroll disputes and keeps the cutlist clean.</p>
    </div>
  </AdminLayout>
</template>
