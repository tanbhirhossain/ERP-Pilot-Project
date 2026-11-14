<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import type { Employee } from '@hrm/types';
import { computed } from 'vue';
import {
    Archive,
    ArrowLeft,
    ArrowLeftRight,
    BadgeCent,
    Briefcase,
    Calendar,
    ClipboardCheck,
    DollarSign,
    Fingerprint,
    FolderOpen,
    GraduationCap,
    Mail,
    Pencil,
    Phone,
    Shield,
    ShieldCheck,
    User,
    UsersRound,
    Wrench,
} from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import FormSection from '@core/Components/FormSection.vue';
import DetailItem from '@core/Components/DetailItem.vue';
import StatusBadge from '@core/Components/StatusBadge.vue';

const props = defineProps<{ employee: Employee; subordinates?: any[] }>();

const emp = computed(() => props.employee ?? {});

/** ISO timestamps come down whole — the UI only ever wants the calendar day. */
const day = (v: unknown) => (v ? String(v).slice(0, 10) : null);

const money = (v: unknown) => {
    if (v === null || v === undefined || v === '') return null;
    const n = Number(v);

    return Number.isFinite(n) ? n.toFixed(2) : null;
};

const statusTone = computed(() => {
    const map: Record<string, 'success' | 'neutral' | 'info' | 'warning' | 'danger'> = {
        Active: 'success',
        Inactive: 'neutral',
        'On Leave': 'info',
        Suspended: 'warning',
        Terminated: 'danger',
        Resigned: 'danger',
    };

    return map[String(emp.value.status ?? '')] ?? 'neutral';
});

const roleLine = computed(() =>
    [emp.value.designation?.name, emp.value.department?.name].filter(Boolean).join(' · ') || 'No role assigned',
);

const currentSalary = computed(
    () => emp.value.salaries?.find((s: any) => s.is_current) ?? emp.value.salaries?.[0] ?? null,
);

const links = [
    {
        label: 'Documents vault',
        desc: 'NID, contracts and certificates — verified with expiry tracking',
        icon: FolderOpen,
        tint: 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
        href: () => route('hrm.employees.documents', emp.value.id),
        cta: 'Open',
    },
    {
        label: 'Asset inventory',
        desc: 'Laptop, ID card, uniform — recovery feeds the final settlement',
        icon: Archive,
        tint: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
        href: () => route('hrm.employees.assets', emp.value.id),
        cta: 'Open',
    },
    {
        label: 'Probation',
        desc: 'Active or extended — confirm to permanent when the term ends',
        icon: BadgeCent,
        tint: 'bg-violet-500/10 text-violet-600 dark:text-violet-400',
        href: () => route('hrm.probations.index'),
        cta: 'Manage',
    },
    {
        label: 'Transfers & promotions',
        desc: 'Draft to approved updates the master record and salary',
        icon: ArrowLeftRight,
        tint: 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400',
        href: () => route('hrm.movements.index'),
        cta: 'View',
    },
    {
        label: 'Exit clearance',
        desc: 'HR, IT, Admin, Finance and Payroll sign-off gates',
        icon: ShieldCheck,
        tint: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
        href: () => route('hrm.clearance.index'),
        cta: 'Clear',
    },
    {
        label: 'On/Offboarding checklists',
        desc: 'Pick a template, assign it, track percentage progress',
        icon: ClipboardCheck,
        tint: 'bg-primary/10 text-primary',
        href: () => route('hrm.checklists.index'),
        cta: 'Manage',
    },
];
</script>

<template>
    <AdminLayout
        :breadcrumbs="[{ title: 'HRM' }, { title: 'Employees', href: route('hrm.employees.index') }, { title: emp.full_name }]"
    >
        <Head :title="emp.full_name" />

        <div class="space-y-5">
            <PageHeader
                :title="emp.full_name"
                :description="`${emp.employee_id ?? ''} · ${roleLine}`"
                :icon="User"
                :actions="[
                    { label: 'Edit', href: route('hrm.employees.edit', emp.id), icon: Pencil },
                    {
                        label: 'Attendance',
                        href: route('hrm.attendance.logs.index', { employee_id: emp.employee_id }),
                        variant: 'outline',
                        icon: Calendar,
                    },
                    { label: 'Back', href: route('hrm.employees.index'), variant: 'outline', icon: ArrowLeft },
                ]"
            >
                <template #badge>
                    <StatusBadge v-if="emp.status" :label="String(emp.status)" :tone="statusTone" dot />
                    <StatusBadge
                        v-if="emp.employment_type"
                        :label="String(emp.employment_type)"
                        tone="primary"
                    />
                </template>
            </PageHeader>

            <!-- ── Identity hero ─────────────────────────────────────────── -->
            <section class="rounded-xl border bg-card p-5 shadow-sm">
                <div class="flex flex-wrap items-start gap-5">
                    <div class="relative shrink-0">
                        <img
                            v-if="emp.photo_url"
                            :src="emp.photo_url"
                            :alt="`Photograph of ${emp.full_name}`"
                            class="size-20 rounded-2xl border object-cover shadow-sm"
                        />
                        <div
                            v-else
                            class="grid size-20 place-items-center rounded-2xl border border-dashed bg-muted/40 text-2xl font-bold text-muted-foreground"
                            aria-hidden="true"
                        >
                            {{ String(emp.first_name ?? emp.full_name ?? '?').charAt(0).toUpperCase() }}
                        </div>
                    </div>

                    <div class="min-w-0 flex-1">
                        <h2 class="text-lg font-bold tracking-tight">{{ emp.full_name }}</h2>
                        <p class="mt-0.5 text-sm text-muted-foreground">{{ roleLine }}</p>

                        <div class="mt-3 flex flex-wrap gap-2">
                            <a
                                v-if="emp.phone"
                                :href="`tel:${emp.phone}`"
                                class="inline-flex items-center gap-1.5 rounded-full border bg-background px-3 py-1.5 text-xs font-medium transition hover:border-primary/40 hover:bg-muted/50"
                            >
                                <Phone class="size-3.5 text-muted-foreground" /> {{ emp.phone }}
                            </a>
                            <a
                                v-if="emp.email"
                                :href="`mailto:${emp.email}`"
                                class="inline-flex items-center gap-1.5 rounded-full border bg-background px-3 py-1.5 text-xs font-medium transition hover:border-primary/40 hover:bg-muted/50"
                            >
                                <Mail class="size-3.5 text-muted-foreground" /> {{ emp.email }}
                            </a>
                            <span
                                class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-medium"
                                :class="
                                    emp.biometric_id
                                        ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400'
                                        : 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-400'
                                "
                            >
                                <Fingerprint class="size-3.5" />
                                {{ emp.biometric_id ? `Biometric ${emp.biometric_id}` : 'Biometric not mapped' }}
                            </span>
                        </div>
                    </div>

                    <div
                        v-if="currentSalary"
                        class="shrink-0 rounded-lg border bg-muted/30 px-4 py-3 text-right"
                    >
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                            {{ currentSalary.is_current ? 'Current net salary' : 'Latest net salary' }}
                        </p>
                        <p class="mt-0.5 font-mono text-xl font-bold">
                            ৳ {{ money(currentSalary.net_salary ?? currentSalary.gross_salary) ?? '0.00' }}
                        </p>
                        <p v-if="currentSalary.pay_grade" class="text-xs text-muted-foreground">
                            Grade {{ currentSalary.pay_grade }}
                        </p>
                    </div>
                </div>
            </section>

            <!-- ── Detail sections ───────────────────────────────────────── -->
            <div class="grid items-start gap-5 lg:grid-cols-2">
                <FormSection title="Employee details" :icon="User" :cols="3" collapsible default-open>
                    <DetailItem label="Employee ID" :value="emp.employee_id" mono />
                    <DetailItem
                        label="Biometric ID"
                        :value="emp.biometric_id || 'Not mapped'"
                        mono
                        :tone="emp.biometric_id ? 'success' : 'warning'"
                    />
                    <DetailItem label="Linked user" :value="emp.user?.name ?? emp.user_id ?? null" />
                    <DetailItem label="Gender" :value="emp.gender" />
                    <DetailItem label="Date of birth" :value="day(emp.date_of_birth)" />
                    <DetailItem label="Joining date" :value="day(emp.joining_date)" />
                    <DetailItem label="Blood group" :value="emp.blood_group" />
                    <DetailItem label="Marital status" :value="emp.marital_status" />
                    <DetailItem label="NID" :value="emp.nid" mono />
                    <DetailItem label="Address" :value="emp.address" span="full" />
                    <DetailItem
                        v-if="emp.photo_url"
                        label="Photo"
                        value="View full size"
                        :href="emp.photo_url"
                        external
                        tone="primary"
                    />
                </FormSection>

                <FormSection
                    title="Employment details"
                    description="Fields carried over from the Excel import."
                    :icon="Briefcase"
                    :cols="3"
                    collapsible
                    default-open
                    :badge="emp.file_no || undefined"
                >
                    <DetailItem label="File no" :value="emp.file_no" mono />
                    <DetailItem label="Card issued" :value="day(emp.card_issue_date)" />
                    <DetailItem label="Job type" :value="emp.job_type" />
                    <DetailItem label="Joining salary" :value="money(emp.joining_salary)" prefix="৳ " mono />
                    <DetailItem label="Service year" :value="emp.service_year" />
                    <DetailItem label="Payment criteria" :value="emp.payment_criteria" />
                    <DetailItem label="Overtime allowed" :value="!!emp.allow_overtime" :tone="emp.allow_overtime ? 'success' : 'muted'" />
                    <DetailItem label="Bonus allowed" :value="!!emp.allow_bonus" :tone="emp.allow_bonus ? 'success' : 'muted'" />
                </FormSection>

                <FormSection
                    title="Personal profile"
                    :icon="User"
                    :cols="2"
                    collapsible
                    :default-open="!!emp.profile"
                    :badge="emp.profile ? 'On file' : 'Not filled'"
                >
                    <template v-if="emp.profile">
                        <DetailItem label="Father's name" :value="emp.profile.father_name" />
                        <DetailItem label="Mother's name" :value="emp.profile.mother_name" />
                        <DetailItem label="Present address" :value="emp.profile.present_address" span="full" />
                        <DetailItem label="Permanent address" :value="emp.profile.permanent_address" span="full" />
                        <DetailItem label="Emergency contact" :value="emp.profile.emergency_contact_name" />
                        <DetailItem label="Relationship" :value="emp.profile.emergency_contact_relation" />
                        <DetailItem label="Emergency phone" :value="emp.profile.emergency_contact_phone" mono />
                        <DetailItem label="Nationality" :value="emp.profile.nationality" />
                        <DetailItem label="Religion" :value="emp.profile.religion" />
                        <DetailItem label="Bio" :value="emp.profile.bio" span="full" tone="muted" />
                    </template>
                    <p v-else class="col-span-full py-2 text-sm text-muted-foreground">No profile information added.</p>
                </FormSection>

                <FormSection
                    title="Attendance policy"
                    description="Applied when reconciling device punches."
                    :icon="Shield"
                    :cols="2"
                    collapsible
                    :default-open="!!emp.policy"
                    :badge="emp.policy?.shift_type || 'Default'"
                >
                    <template v-if="emp.policy">
                        <DetailItem label="Shift type" :value="emp.policy.shift_type" />
                        <DetailItem label="Grace in" :value="emp.policy.grace_time_in != null ? `${emp.policy.grace_time_in} min` : null" />
                        <DetailItem label="Grace out" :value="emp.policy.grace_time_out != null ? `${emp.policy.grace_time_out} min` : null" />
                        <DetailItem label="Duty hours" :value="emp.policy.duty_hours != null ? `${emp.policy.duty_hours} h` : null" />
                        <DetailItem label="Buffer window" :value="emp.policy.buffer_minutes != null ? `${emp.policy.buffer_minutes} min` : null" />
                        <DetailItem label="Punishable" :value="!!emp.policy.is_punishable" :tone="emp.policy.is_punishable ? 'warning' : 'muted'" />
                        <DetailItem label="Auto shift" :value="!!emp.policy.auto_shift" />
                        <DetailItem label="Flexible fallback" :value="!!emp.policy.allow_fallback" />
                        <DetailItem
                            label="Fallback off day"
                            :value="
                                emp.policy.fallback_off_day == null
                                    ? 'None — roster only'
                                    : ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][Number(emp.policy.fallback_off_day)] ?? String(emp.policy.fallback_off_day)
                            "
                            span="full"
                        />
                    </template>
                    <p v-else class="col-span-full py-2 text-sm text-muted-foreground">
                        No policy assigned — defaults to a fixed shift with 15 minutes' grace.
                    </p>
                </FormSection>

                <FormSection
                    title="Salary breakdown"
                    description="Every structure on record, newest first."
                    :icon="DollarSign"
                    plain
                    collapsible
                    :default-open="!!emp.salaries?.length"
                    :badge="emp.salaries?.length ? `${emp.salaries.length} record${emp.salaries.length === 1 ? '' : 's'}` : undefined"
                    class="lg:col-span-2"
                >
                    <div v-if="emp.salaries?.length" class="space-y-3">
                        <div
                            v-for="sal in emp.salaries"
                            :key="sal.id"
                            class="rounded-lg border bg-muted/[0.25] p-4 transition hover:border-primary/30 hover:bg-card"
                        >
                            <div class="mb-3 flex flex-wrap items-center gap-2">
                                <p class="font-mono text-xs font-bold uppercase tracking-widest text-muted-foreground">
                                    {{ day(sal.effective_from) ?? '—' }} → {{ sal.effective_to ? day(sal.effective_to) : 'present' }}
                                </p>
                                <StatusBadge v-if="sal.is_current" label="Current" tone="success" dot />
                                <StatusBadge v-if="sal.pay_grade" :label="`Grade ${sal.pay_grade}`" tone="primary" />
                                <StatusBadge :label="String(sal.payment_method ?? 'cash')" tone="neutral" />
                                <p class="ml-auto font-mono text-lg font-bold">
                                    ৳ {{ money(sal.net_salary ?? sal.gross_salary) ?? '0.00' }}
                                    <span class="text-xs font-medium text-muted-foreground">net</span>
                                </p>
                            </div>

                            <div class="grid grid-cols-2 gap-x-5 gap-y-3 sm:grid-cols-3 xl:grid-cols-6">
                                <DetailItem label="Basic" :value="money(sal.basic_salary)" prefix="৳ " mono />
                                <DetailItem label="Gross" :value="money(sal.gross_salary)" prefix="৳ " mono />
                                <DetailItem label="House rent" :value="money(sal.house_rent)" prefix="৳ " mono />
                                <DetailItem label="Medical" :value="money(sal.medical_allowance)" prefix="৳ " mono />
                                <DetailItem label="Conveyance" :value="money(sal.conveyance_allowance)" prefix="৳ " mono />
                                <DetailItem label="Other" :value="money(sal.other_allowance)" prefix="৳ " mono />
                                <DetailItem label="Bank" :value="sal.bank_name" />
                                <DetailItem label="Account no" :value="sal.bank_account_no" mono span="2" />
                                <DetailItem v-if="sal.remarks" label="Remarks" :value="sal.remarks" tone="muted" span="full" />
                            </div>
                        </div>
                    </div>
                    <p v-else class="py-2 text-sm text-muted-foreground">No salary records.</p>
                </FormSection>

                <FormSection
                    title="Education"
                    :icon="GraduationCap"
                    plain
                    collapsible
                    :default-open="!!emp.educations?.length"
                    :badge="String(emp.educations?.length ?? 0)"
                >
                    <div v-if="emp.educations?.length" class="space-y-2">
                        <div v-for="ed in emp.educations" :key="ed.id" class="rounded-lg border bg-muted/[0.25] p-3">
                            <p class="text-sm font-semibold">{{ ed.degree }} — {{ ed.institute }}</p>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                {{ [ed.board, ed.passing_year, ed.grade, ed.gpa ? `GPA ${ed.gpa}` : null].filter(Boolean).join(' · ') || '—' }}
                            </p>
                            <p v-if="ed.description" class="mt-1.5 text-xs text-muted-foreground">{{ ed.description }}</p>
                        </div>
                    </div>
                    <p v-else class="py-2 text-sm text-muted-foreground">No education records.</p>
                </FormSection>

                <FormSection
                    title="Experience"
                    :icon="Briefcase"
                    plain
                    collapsible
                    :default-open="!!emp.experiences?.length"
                    :badge="String(emp.experiences?.length ?? 0)"
                >
                    <div v-if="emp.experiences?.length" class="space-y-2">
                        <div v-for="ex in emp.experiences" :key="ex.id" class="rounded-lg border bg-muted/[0.25] p-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-sm font-semibold">{{ ex.designation }} at {{ ex.company_name }}</p>
                                <StatusBadge v-if="ex.is_current" label="Current" tone="success" dot />
                            </div>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                {{ [ex.department, day(ex.from_date), ex.is_current ? 'Present' : day(ex.to_date)].filter(Boolean).join(' · ') }}
                            </p>
                            <p v-if="ex.responsibilities" class="mt-1.5 text-xs text-muted-foreground">{{ ex.responsibilities }}</p>
                        </div>
                    </div>
                    <p v-else class="py-2 text-sm text-muted-foreground">No experience records.</p>
                </FormSection>

                <FormSection
                    title="Skills"
                    :icon="Wrench"
                    plain
                    collapsible
                    :default-open="!!emp.skills?.length"
                    :badge="String(emp.skills?.length ?? 0)"
                >
                    <div v-if="emp.skills?.length" class="flex flex-wrap gap-2">
                        <span
                            v-for="sk in emp.skills"
                            :key="sk.id"
                            class="inline-flex items-baseline gap-1.5 rounded-full border bg-muted/40 px-3 py-1 text-sm font-medium"
                        >
                            {{ sk.skill_name }}
                            <span class="text-xs text-muted-foreground">
                                {{ sk.proficiency }}<template v-if="sk.years_of_experience"> · {{ sk.years_of_experience }}y</template>
                            </span>
                        </span>
                    </div>
                    <p v-else class="py-2 text-sm text-muted-foreground">No skills added.</p>
                </FormSection>

                <FormSection
                    title="Supervisors"
                    description="Either a system user or another employee can supervise."
                    :icon="UsersRound"
                    plain
                    collapsible
                    :default-open="!!emp.supervisors?.length"
                    :badge="String(emp.supervisors?.length ?? 0)"
                    class="lg:col-span-2"
                >
                    <div v-if="emp.supervisors?.length" class="space-y-2">
                        <div
                            v-for="s in emp.supervisors"
                            :key="s.id"
                            class="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-muted/[0.25] p-3"
                        >
                            <div class="flex min-w-0 items-center gap-2.5">
                                <StatusBadge :label="s.supervisor_employee_id ? 'Employee' : 'User'" :tone="s.supervisor_employee_id ? 'info' : 'warning'" />
                                <div class="min-w-0">
                                    <p class="flex flex-wrap items-center gap-2 text-sm font-medium">
                                        <span v-if="s.supervisor">
                                            {{ s.supervisor.name }}
                                            <span class="text-xs text-muted-foreground">({{ s.supervisor.email }})</span>
                                        </span>
                                        <span v-else-if="s.supervisor_employee_id">{{ s.supervisor_employee_id }}</span>
                                        <span v-else>{{ s.supervisor_display?.name || '—' }}</span>
                                        <StatusBadge v-if="s.is_primary" label="Primary" tone="warning" dot />
                                    </p>
                                    <p class="mt-0.5 text-xs text-muted-foreground">
                                        {{ s.supervisor_type }} · {{ s.is_active ? 'Active' : 'Inactive' }}
                                        <template v-if="s.effective_from">
                                            · {{ day(s.effective_from) }} → {{ s.effective_to ? day(s.effective_to) : 'ongoing' }}
                                        </template>
                                    </p>
                                    <p v-if="s.remarks" class="mt-0.5 text-xs italic text-muted-foreground">“{{ s.remarks }}”</p>
                                </div>
                            </div>
                            <a
                                :href="route('hrm.supervisors.show', s.id)"
                                class="shrink-0 text-xs font-medium text-primary underline-offset-2 hover:underline"
                            >
                                View →
                            </a>
                        </div>
                        <a
                            :href="route('hrm.supervisors.create', { employee_id: emp.employee_id })"
                            class="inline-flex text-xs font-medium text-primary underline-offset-2 hover:underline"
                        >
                            + Assign another supervisor
                        </a>
                    </div>
                    <div v-else class="py-1">
                        <p class="text-sm text-muted-foreground">No supervisor assigned.</p>
                        <a
                            :href="route('hrm.supervisors.create', { employee_id: emp.employee_id })"
                            class="mt-2 inline-flex text-xs font-medium text-primary underline-offset-2 hover:underline"
                        >
                            + Assign a supervisor (user or employee)
                        </a>
                    </div>

                    <div v-if="subordinates?.length" class="mt-4 border-t pt-3">
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                            Supervises {{ subordinates.length }} subordinate{{ subordinates.length === 1 ? '' : 's' }}
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <a
                                v-for="sub in subordinates"
                                :key="sub.id"
                                :href="route('hrm.employees.show', sub.employee_id)"
                                class="rounded-full border bg-background px-3 py-1 text-xs font-medium transition hover:border-primary/40 hover:bg-muted/50"
                            >
                                {{ sub.employee }} <span class="text-muted-foreground">({{ sub.employee_id }} · {{ sub.supervisor_type }})</span>
                            </a>
                        </div>
                    </div>
                </FormSection>
            </div>

            <!-- ── Related records ───────────────────────────────────────── -->
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <a
                    v-for="l in links"
                    :key="l.label"
                    :href="l.href()"
                    class="group flex items-center justify-between gap-3 rounded-xl border bg-card p-4 shadow-sm transition hover:border-primary/40 hover:bg-muted/30 hover:shadow-md"
                >
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="grid size-10 shrink-0 place-items-center rounded-xl" :class="l.tint">
                            <component :is="l.icon" class="size-5" />
                        </span>
                        <div class="min-w-0">
                            <h3 class="text-sm font-semibold">{{ l.label }}</h3>
                            <p class="truncate text-xs text-muted-foreground">{{ l.desc }}</p>
                        </div>
                    </div>
                    <span class="shrink-0 text-xs font-semibold text-primary opacity-0 transition group-hover:opacity-100">
                        {{ l.cta }} →
                    </span>
                </a>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-card p-4 shadow-sm">
                <div class="flex items-center gap-2.5">
                    <span class="grid size-9 place-items-center rounded-lg bg-primary/10 text-primary">
                        <Fingerprint class="size-4" />
                    </span>
                    <div>
                        <h3 class="text-sm font-semibold">Attendance logs</h3>
                        <p class="text-xs text-muted-foreground">Raw device punches and the reconciled daily ledger</p>
                    </div>
                </div>
                <a
                    :href="route('hrm.attendance.logs.index', { employee_id: emp.employee_id })"
                    class="inline-flex items-center gap-1.5 text-sm font-medium text-primary underline-offset-2 hover:underline"
                >
                    <Calendar class="size-3.5" /> View logs →
                </a>
            </div>
        </div>
    </AdminLayout>
</template>
