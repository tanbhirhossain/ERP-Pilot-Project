<script setup lang="ts">
import { Head, useForm, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const p = defineProps<{
    preview: {
        total_rows: number;
        bank_rows: number;
        cash_rows: number;
        blank_rows: number;
        will_update: Array<Record<string, any>>;
        unchanged: number;
        problems: Array<Record<string, any>>;
        columns_found: Record<string, string | null>;
    } | null;
    uploadedName: string | null;
    applied: { updated: number; skipped: number } | null;
}>();

const file = ref<File | null>(null);
const upload = useForm({ file: null as File | null });
const applying = ref(false);

function onPick(e: Event) {
    const f = (e.target as HTMLInputElement).files?.[0] ?? null;
    file.value = f;
    upload.file = f;
}

function runPreview() {
    if (!file.value) return;
    upload.post(route('payroll.bank-accounts.preview'), { forceFormData: true });
}

function apply() {
    applying.value = true;
    router.post(route('payroll.bank-accounts.apply'), {}, {
        onFinish: () => { applying.value = false; },
    });
}

const bankUpdates = computed(() => (p.preview?.will_update ?? []).filter((r) => r.payment_method === 'bank'));
const cashUpdates = computed(() => (p.preview?.will_update ?? []).filter((r) => r.payment_method === 'cash'));
const cashTotal = computed(() => p.preview?.cash_rows ?? 0);
</script>

<template>
    <AdminLayout :breadcrumbs="[{ title: 'Payroll' }, { title: 'Settings' }, { title: 'Bank accounts' }]">
        <Head title="Bank accounts" />
        <div class="space-y-6">
            <div class="rounded-xl border bg-card p-6">
                <PageHeader title="Bank accounts from the staff spreadsheet" />
                <p class="mt-1 text-sm text-muted-foreground">
                    The salary record decides how each employee is paid: an account number means the
                    BEFTN advice, no account number means the cash sheet. Upload the staff sheet and
                    this reads its three bank columns. Nothing is written until you press the second
                    button, and the counts below are exactly what that second button will do.
                </p>

                <div class="mt-5 grid max-w-xl gap-3">
                    <div>
                        <Label>Staff spreadsheet (.xlsx, .xls, .csv)</Label>
                        <Input type="file" accept=".xlsx,.xls,.csv" class="mt-1" @change="onPick" />
                        <p class="mt-1 text-xs text-muted-foreground">
                            Needed columns: an employee ID, and a bank account number. The payment method
                            and bank name columns are read when present.
                        </p>
                    </div>
                    <Button :disabled="!file || upload.processing" @click="runPreview">
                        {{ upload.processing ? 'Reading…' : 'Read the file and show what would change' }}
                    </Button>
                </div>
            </div>

            <div v-if="p.applied" class="rounded-xl border bg-card p-6">
                <h2 class="font-semibold">Last import</h2>
                <p class="mt-1 text-sm">
                    <strong>{{ p.applied.updated }}</strong> salary records updated,
                    <strong>{{ p.applied.skipped }}</strong> rows left alone.
                </p>
            </div>

            <template v-if="p.preview">
                <div class="rounded-xl border bg-card p-6">
                    <h2 class="font-semibold">{{ p.uploadedName }}</h2>

                    <div class="mt-4 grid grid-cols-2 gap-4 md:grid-cols-4">
                        <div class="rounded-lg border p-4">
                            <div class="text-xs text-muted-foreground">Rows read</div>
                            <div class="text-2xl font-semibold">{{ p.preview.total_rows }}</div>
                        </div>
                        <div class="rounded-lg border p-4">
                            <div class="text-xs text-muted-foreground">Paid by bank</div>
                            <div class="text-2xl font-semibold">{{ p.preview.bank_rows }}</div>
                            <div class="text-xs text-muted-foreground">{{ bankUpdates.length }} to import</div>
                        </div>
                        <div class="rounded-lg border p-4">
                            <div class="text-xs text-muted-foreground">Paid in cash</div>
                            <div class="text-2xl font-semibold">{{ cashTotal }}</div>
                            <div class="text-xs text-muted-foreground">{{ cashUpdates.length }} to record</div>
                        </div>
                        <div class="rounded-lg border p-4">
                            <div class="text-xs text-muted-foreground">Need a decision</div>
                            <div class="text-2xl font-semibold">{{ p.preview.problems.length }}</div>
                            <div class="text-xs text-muted-foreground">listed below, not imported</div>
                        </div>
                    </div>

                    <div class="mt-5 rounded-lg border p-4">
                        <h3 class="text-sm font-semibold">Columns found</h3>
                        <dl class="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-sm">
                            <template v-for="(v, k) in p.preview.columns_found" :key="k">
                                <dt class="text-muted-foreground">{{ k.replace('_', ' ') }}</dt>
                                <dd :class="v ? '' : 'text-muted-foreground'">{{ v ?? '— not in this file' }}</dd>
                            </template>
                        </dl>
                    </div>

                    <div v-if="p.preview.problems.length" class="mt-5">
                        <h3 class="text-sm font-semibold">
                            Rows that will not be imported ({{ p.preview.problems.length }})
                        </h3>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Each is left exactly as it is. Reading this list is the point of the preview.
                        </p>
                        <div class="mt-2 overflow-x-auto rounded-lg border">
                            <table class="w-full text-sm">
                                <thead class="bg-muted/50 text-left">
                                    <tr>
                                        <th class="px-3 py-2">Employee</th>
                                        <th class="px-3 py-2">Name</th>
                                        <th class="px-3 py-2">Value</th>
                                        <th class="px-3 py-2">Why</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(x, i) in p.preview.problems" :key="i" class="border-t">
                                        <td class="px-3 py-2 font-mono text-xs">{{ x.employee_id }}</td>
                                        <td class="px-3 py-2">{{ x.name ?? '—' }}</td>
                                        <td class="px-3 py-2 font-mono text-xs">{{ x.value }}</td>
                                        <td class="px-3 py-2">{{ x.reason }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div v-if="p.preview.will_update.length" class="mt-5">
                        <h3 class="text-sm font-semibold">
                            Will be written ({{ p.preview.will_update.length }})
                        </h3>
                        <div class="mt-2 max-h-96 overflow-auto rounded-lg border">
                            <table class="w-full text-sm">
                                <thead class="sticky top-0 bg-muted text-left">
                                    <tr>
                                        <th class="px-3 py-2">Employee</th>
                                        <th class="px-3 py-2">Name</th>
                                        <th class="px-3 py-2">Paid by</th>
                                        <th class="px-3 py-2">Bank</th>
                                        <th class="px-3 py-2">Account</th>
                                        <th class="px-3 py-2">Change</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(r, i) in p.preview.will_update" :key="i" class="border-t">
                                        <td class="px-3 py-2 font-mono text-xs">{{ r.employee_id }}</td>
                                        <td class="px-3 py-2">{{ r.name ?? '—' }}</td>
                                        <td class="px-3 py-2">{{ r.payment_method === 'bank' ? 'Bank' : 'Cash' }}</td>
                                        <td class="px-3 py-2">{{ r.bank_name ?? '—' }}</td>
                                        <td class="px-3 py-2 font-mono text-xs">{{ r.bank_account_no ?? '—' }}</td>
                                        <td class="px-3 py-2 text-xs text-muted-foreground">{{ r.change }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="mt-5 flex items-center gap-3">
                        <Button :disabled="applying || p.preview.will_update.length === 0" @click="apply">
                            {{ applying ? 'Writing…' : `Write ${p.preview.will_update.length} salary records` }}
                        </Button>
                        <span class="text-xs text-muted-foreground">
                            This changes only payment method, bank name and account number. No salary
                            amount is touched.
                        </span>
                    </div>
                </div>
            </template>
        </div>
    </AdminLayout>
</template>
