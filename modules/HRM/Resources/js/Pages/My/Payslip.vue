<script setup lang="ts">
import PageHeader from '@core/Components/PageHeader.vue';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import { Button } from '@/components/ui/button';
import { printDocument } from '@core/Composables/usePrint';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, FileText, Printer } from 'lucide-vue-next';
import { computed } from 'vue';

interface BreakdownItem { name: string; amount: number | string }
interface Slip {
    id: number;
    employee_name: string;
    department: string | null;
    designation: string | null;
    pay_year: number;
    pay_month: number;
    basic_salary: string;
    gross_salary: string;
    total_benefits: string;
    total_deductions: string;
    net_salary: string;
    payment_status: string;
    paid_at: string | null;
    breakdown_json?: {
        benefits?: BreakdownItem[];
        recurring_deductions?: BreakdownItem[];
        tax?: { amount?: number | string } | number | string | null;
        provident_fund?: { amount?: number | string } | number | string | null;
        loan_installments?: BreakdownItem[];
    } | null;
}
interface Props {
    slip: Slip;
}
const props = defineProps<Props>();

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const money = (v: number | string | null | undefined): string => Number(v ?? 0).toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const period = computed(() => `${MONTHS[props.slip.pay_month - 1]} ${props.slip.pay_year}`);

const benefits = computed<BreakdownItem[]>(() => props.slip.breakdown_json?.benefits ?? []);
const deductions = computed<BreakdownItem[]>(() => {
    const b = props.slip.breakdown_json;
    if (!b) return [];
    const items: BreakdownItem[] = [...(b.recurring_deductions ?? []), ...(b.loan_installments ?? [])];
    const taxAmt = typeof b.tax === 'object' && b.tax !== null ? b.tax.amount : b.tax;
    if (Number(taxAmt) > 0) items.push({ name: 'Income tax', amount: taxAmt as number });
    const pfAmt = typeof b.provident_fund === 'object' && b.provident_fund !== null ? b.provident_fund.amount : b.provident_fund;
    if (Number(pfAmt) > 0) items.push({ name: 'Provident fund', amount: pfAmt as number });
    return items;
});

const STATUS_TONE: Record<string, string> = {
    paid: 'bg-emerald-500/10 text-emerald-600',
    hold: 'bg-amber-500/10 text-amber-600',
    unpaid: 'bg-sky-500/10 text-sky-600',
};

function print(): void {
    printDocument({ portrait: true });
}
</script>

<template>
    <Head :title="`Payslip — ${period}`" />
    <AdminLayout>
        <PageHeader :title="`Payslip · ${period}`" :description="`${props.slip.employee_name} · ${props.slip.designation ?? 'Employee'}`" :icon="FileText">
            <template #actions>
                <Link :href="route('hrm.my.payslips')"><Button variant="outline" size="sm" class="gap-1.5"><ArrowLeft class="size-4" /> All payslips</Button></Link>
                <Button variant="outline" size="sm" class="gap-1.5" @click="print"><Printer class="size-4" /> Print</Button>
            </template>
        </PageHeader>

        <div class="print-portrait mt-6 mx-auto max-w-3xl rounded-xl border bg-card shadow-sm print:border-0 print:shadow-none" data-core-print>
            <!-- Header -->
            <div class="flex items-start justify-between border-b p-6">
                <div>
                    <h2 class="text-lg font-bold">{{ props.slip.employee_name }}</h2>
                    <p class="text-[13px] text-muted-foreground">{{ props.slip.designation ?? 'Employee' }}<span v-if="props.slip.department"> · {{ props.slip.department }}</span></p>
                    <p class="mt-1 text-[12px] text-muted-foreground/70">Pay period: {{ period }}</p>
                </div>
                <span class="rounded-full px-3 py-1 text-[12px] font-bold capitalize" :class="STATUS_TONE[props.slip.payment_status] ?? 'bg-muted text-muted-foreground'">{{ props.slip.payment_status }}</span>
            </div>

            <!-- Earnings -->
            <div class="grid gap-0 sm:grid-cols-2">
                <div class="border-b p-6 sm:border-b-0 sm:border-r">
                    <h3 class="mb-3 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Earnings</h3>
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between"><span class="text-muted-foreground">Basic salary</span><span class="font-medium tabular-nums">{{ money(props.slip.basic_salary) }}</span></div>
                        <div v-for="(b, i) in benefits" :key="i" class="flex justify-between"><span class="text-muted-foreground">{{ b.name }}</span><span class="font-medium tabular-nums">{{ money(b.amount) }}</span></div>
                    </div>
                    <div class="mt-3 flex justify-between border-t pt-3 text-sm font-semibold"><span>Gross</span><span class="tabular-nums">{{ money(props.slip.gross_salary) }}</span></div>
                </div>
                <div class="p-6">
                    <h3 class="mb-3 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Deductions</h3>
                    <div class="space-y-2 text-sm">
                        <div v-if="deductions.length === 0" class="text-[13px] text-muted-foreground">No deductions</div>
                        <div v-for="(d, i) in deductions" :key="i" class="flex justify-between"><span class="text-muted-foreground">{{ d.name }}</span><span class="font-medium tabular-nums text-rose-600">{{ money(d.amount) }}</span></div>
                    </div>
                    <div class="mt-3 flex justify-between border-t pt-3 text-sm font-semibold"><span>Total deductions</span><span class="tabular-nums text-rose-600">{{ money(props.slip.total_deductions) }}</span></div>
                </div>
            </div>

            <!-- Net -->
            <div class="flex items-center justify-between rounded-b-xl bg-gradient-to-br from-hero-from via-hero-via to-hero-to p-6 text-hero-ink">
                <div>
                    <p class="text-[12px] font-medium uppercase tracking-wider opacity-80">Net pay</p>
                    <p v-if="props.slip.paid_at" class="mt-0.5 text-[12px] opacity-70">Paid {{ new Date(props.slip.paid_at).toLocaleDateString() }}</p>
                </div>
                <p class="text-3xl font-bold tabular-nums">{{ money(props.slip.net_salary) }} <span class="text-base font-medium opacity-80">BDT</span></p>
            </div>
        </div>
    </AdminLayout>
</template>
