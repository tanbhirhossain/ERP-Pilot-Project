<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import type { SelectOption } from '@core/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    AlertCircle,
    ArrowLeft,
    Briefcase,
    Building2,
    Clock,
    Contact,
    GraduationCap,
    Plus,
    Sparkles,
    Trash2,
    Upload,
    User,
    Wallet,
    X,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import FormActions from '@core/Components/FormActions.vue';
import FormCheckbox from '@core/Components/FormCheckbox.vue';
import FormField from '@core/Components/FormField.vue';
import FormSection from '@core/Components/FormSection.vue';
import FormSelect from '@core/Components/FormSelect.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import SearchableSelect from '@core/Components/SearchableSelect.vue';

import { useUnsavedGuard } from '@core/Composables/useUnsavedGuard';
defineProps<{
    departmentOptions: SelectOption[];
    designationOptions: SelectOption[];
    organizationOptions: SelectOption[];
    userOptions: SelectOption[];
}>();

const form = useForm({
    employee_id: '',
    biometric_id: '',
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    nid: '',
    gender: '',
    date_of_birth: '',
    joining_date: '',
    organization_id: null,
    department_id: null,
    designation_id: null,
    user_id: null,
    employment_type: 'Permanent',
    status: 'Active',
    blood_group: '',
    marital_status: '',
    photo: null as File | null,
    address: '',
    file_no: '',
    card_issue_date: '',
    job_type: '',
    joining_salary: null as number | null,
    allow_overtime: false,
    allow_bonus: false,
    payment_criteria: 'general',
    service_year: null as number | null,
    profile: {
        father_name: '',
        mother_name: '',
        present_address: '',
        permanent_address: '',
        emergency_contact_name: '',
        emergency_contact_phone: '',
        emergency_contact_relation: '',
        nationality: 'Bangladeshi',
        religion: '',
        bio: '',
    },
    policy: {
        shift_type: 'Fixed',
        grace_time_in: 15,
        grace_time_out: 15,
        is_punishable: true,
        duty_hours: 8,
        auto_shift: true,
        buffer_minutes: 90,
        allow_fallback: true,
        fallback_off_day: null as number | null,
    },
    salary: {
        basic_salary: 0,
        gross_salary: 0,
        house_rent: 0,
        medical_allowance: 0,
        conveyance_allowance: 0,
        other_allowance: 0,
        net_salary: 0,
        pay_grade: '',
        effective_from: '',
        effective_to: '',
        is_current: true,
        payment_method: 'cash',
        bank_name: '',
        bank_account_no: '',
        remarks: '',
    },
    educations: [{ degree: '', institute: '', board: '', passing_year: '', grade: '', gpa: '', description: '' }],
    experiences: [
        {
            company_name: '',
            designation: '',
            department: '',
            from_date: '',
            to_date: '',
            is_current: false,
            responsibilities: '',
            description: '',
        },
    ],
    skills: [{ skill_name: '', proficiency: 'Intermediate', years_of_experience: null as number | null, description: '' }],
});
useUnsavedGuard(() => form.isDirty);

const tabs = [
    { id: 'basic', label: 'Basic', icon: User },
    { id: 'employment', label: 'Employment', icon: Briefcase },
    { id: 'profile', label: 'Profile', icon: Contact },
    { id: 'policy', label: 'Attendance', icon: Clock },
    { id: 'salary', label: 'Salary', icon: Wallet },
    { id: 'education', label: 'Education', icon: GraduationCap },
    { id: 'experience', label: 'Experience', icon: Building2 },
    { id: 'skills', label: 'Skills', icon: Sparkles },
] as const;

const activeTab = ref<string>('basic');

function tabForError(field: string): string {
    if (field.startsWith('profile.')) return 'profile';
    if (field.startsWith('policy.')) return 'policy';
    if (field.startsWith('salary.')) return 'salary';
    if (field.startsWith('educations.')) return 'education';
    if (field.startsWith('experiences.')) return 'experience';
    if (field.startsWith('skills.')) return 'skills';
    if (
        ['file_no', 'card_issue_date', 'job_type', 'joining_salary', 'allow_overtime', 'allow_bonus', 'payment_criteria', 'service_year'].some(
            (key) => field.startsWith(key),
        )
    ) {
        return 'employment';
    }

    return 'basic';
}

/** Tabs carrying a validation error get a marker, so the fix is findable. */
const errorTabs = computed(() => new Set(Object.keys(form.errors).map(tabForError)));
const errorCount = computed(() => Object.keys(form.errors).length);
const firstError = computed(() => Object.values(form.errors)[0]);

const photoPreview = computed(() => {
    if (!form.photo) return null;

    return URL.createObjectURL(form.photo);
});

function onPhotoChange(event: Event) {
    form.photo = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function clearPhoto() {
    form.photo = null;
}

function addEducation() {
    form.educations.push({ degree: '', institute: '', board: '', passing_year: '', grade: '', gpa: '', description: '' });
}
function removeEducation(i: number) {
    form.educations.splice(i, 1);
    if (form.educations.length === 0) addEducation();
}
function addExperience() {
    form.experiences.push({
        company_name: '',
        designation: '',
        department: '',
        from_date: '',
        to_date: '',
        is_current: false,
        responsibilities: '',
        description: '',
    });
}
function removeExperience(i: number) {
    form.experiences.splice(i, 1);
    if (form.experiences.length === 0) addExperience();
}
function addSkill() {
    form.skills.push({ skill_name: '', proficiency: 'Intermediate', years_of_experience: null, description: '' });
}
function removeSkill(i: number) {
    form.skills.splice(i, 1);
    if (form.skills.length === 0) addSkill();
}

function submit() {
    form.post(route('hrm.employees.store'), {
        forceFormData: true,
        onError: (errors) => {
            const first = Object.keys(errors)[0];
            if (first) activeTab.value = tabForError(first);
        },
    });
}
</script>

<template>
    <AdminLayout
        :breadcrumbs="[{ title: 'HRM' }, { title: 'Employees', href: route('hrm.employees.index') }, { title: 'Create' }]"
    >
        <Head title="Create Employee" />

        <PageHeader
            title="Add Employee"
            description="Create the person record, then attach employment, attendance, salary and profile details."
            :icon="User"
            :actions="[
                {
                    label: 'Back to employees',
                    href: route('hrm.employees.index'),
                    variant: 'outline',
                    icon: ArrowLeft,
                },
            ]"
        />

        <form class="mt-5 space-y-5" @submit.prevent="submit">
            <!-- Section navigation: sticky, so it stays reachable in a long form. -->
            <nav
                class="sticky top-2 z-20 -mx-1 flex gap-1.5 overflow-x-auto rounded-xl border bg-card/95 p-2 shadow-sm backdrop-blur supports-[backdrop-filter]:bg-card/80"
                role="tablist"
                aria-label="Employee form sections"
            >
                <button
                    v-for="tab in tabs"
                    :key="tab.id"
                    type="button"
                    role="tab"
                    :aria-selected="activeTab === tab.id"
                    class="relative flex shrink-0 items-center gap-2 rounded-xl px-3 py-2 text-sm font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    :class="
                        activeTab === tab.id
                            ? 'bg-primary text-primary-foreground shadow-sm'
                            : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                    "
                    @click="activeTab = tab.id"
                >
                    <component :is="tab.icon" class="size-4" aria-hidden="true" />
                    {{ tab.label }}
                    <span
                        v-if="errorTabs.has(tab.id)"
                        class="grid size-4 place-items-center rounded-full bg-destructive text-[10px] font-bold text-white"
                        :aria-label="`${tab.label} has errors`"
                    >!</span>
                </button>
            </nav>

            <div
                v-if="errorCount"
                role="alert"
                class="flex items-start gap-2.5 rounded-xl border border-destructive/30 bg-destructive/5 px-4 py-3"
            >
                <AlertCircle class="mt-0.5 size-4 shrink-0 text-destructive" aria-hidden="true" />
                <div class="min-w-0 text-sm">
                    <p class="font-semibold text-destructive">
                        {{ errorCount }} field{{ errorCount === 1 ? '' : 's' }} need{{ errorCount === 1 ? 's' : '' }} attention
                    </p>
                    <p class="mt-0.5 text-destructive/85">{{ firstError }}</p>
                </div>
            </div>

            <!-- ── Basic ─────────────────────────────────────────────── -->
            <FormSection
                v-if="activeTab === 'basic'"
                title="Basic Information"
                description="Identity and contact details. Only the first name is required."
                :icon="User"
                :cols="3"
            >
                <FormField label="Employee ID" help="Auto-generated if left blank.">
                    <template #default="{ id }">
                        <Input :id="id" v-model="form.employee_id" placeholder="EMP-0001" :class="form.errors.employee_id && 'border-destructive'" />
                    </template>
                </FormField>

                <FormField
                    label="Biometric ID"
                    :error="form.errors.biometric_id"
                    help="ZK enrolment ID — must match the device's device_user_id to pair."
                >
                    <template #default="{ id }">
                        <Input :id="id" v-model="form.biometric_id" placeholder="e.g. 19080" />
                    </template>
                </FormField>

                <FormField label="Linked user account" help="Optional sign-in account for this person.">
                    <SearchableSelect v-model="form.user_id" :options="userOptions" placeholder="Select user account" />
                </FormField>

                <FormField label="First name" required :error="form.errors.first_name">
                    <template #default="{ id }">
                        <Input :id="id" v-model="form.first_name" placeholder="Ayesha" />
                    </template>
                </FormField>

                <FormField label="Last name" :error="form.errors.last_name">
                    <template #default="{ id }">
                        <Input :id="id" v-model="form.last_name" placeholder="Rahman" />
                    </template>
                </FormField>

                <FormField label="Organisation">
                    <SearchableSelect v-model="form.organization_id" :options="organizationOptions" placeholder="Select organisation" />
                </FormField>

                <FormField label="Email" :error="form.errors.email">
                    <template #default="{ id }">
                        <Input :id="id" v-model="form.email" type="email" placeholder="name@company.com" />
                    </template>
                </FormField>

                <FormField label="Phone" :error="form.errors.phone">
                    <template #default="{ id }">
                        <Input :id="id" v-model="form.phone" placeholder="01XXXXXXXXX" />
                    </template>
                </FormField>

                <FormField label="National ID" :error="form.errors.nid">
                    <template #default="{ id }">
                        <Input :id="id" v-model="form.nid" placeholder="NID number" />
                    </template>
                </FormField>

                <FormField label="Department" :error="form.errors.department_id">
                    <SearchableSelect v-model="form.department_id" :options="departmentOptions" placeholder="Select department" />
                </FormField>

                <FormField label="Designation" :error="form.errors.designation_id">
                    <SearchableSelect v-model="form.designation_id" :options="designationOptions" placeholder="Select designation" />
                </FormField>

                <FormField label="Employment type">
                    <FormSelect v-model="form.employment_type">
                        <option>Permanent</option>
                        <option>Contract</option>
                        <option>Probation</option>
                        <option>Intern</option>
                        <option>Part-time</option>
                    </FormSelect>
                </FormField>

                <FormField label="Status">
                    <FormSelect v-model="form.status">
                        <option>Active</option>
                        <option>Inactive</option>
                        <option>On Leave</option>
                        <option>Suspended</option>
                        <option>Terminated</option>
                        <option>Resigned</option>
                    </FormSelect>
                </FormField>

                <FormField label="Joining date" :error="form.errors.joining_date">
                    <template #default="{ id }">
                        <Input :id="id" v-model="form.joining_date" type="date" />
                    </template>
                </FormField>

                <FormField label="Date of birth" :error="form.errors.date_of_birth">
                    <template #default="{ id }">
                        <Input :id="id" v-model="form.date_of_birth" type="date" />
                    </template>
                </FormField>

                <FormField label="Gender">
                    <FormSelect v-model="form.gender" placeholder="Select">
                        <option>Male</option>
                        <option>Female</option>
                        <option>Other</option>
                    </FormSelect>
                </FormField>

                <FormField label="Blood group">
                    <template #default="{ id }">
                        <Input :id="id" v-model="form.blood_group" placeholder="A+" />
                    </template>
                </FormField>

                <FormField label="Marital status">
                    <FormSelect v-model="form.marital_status" placeholder="Select">
                        <option>Single</option>
                        <option>Married</option>
                        <option>Divorced</option>
                    </FormSelect>
                </FormField>

                <FormField label="Present address" :span="2">
                    <template #default="{ id }">
                        <Input :id="id" v-model="form.address" placeholder="House, road, area, city" />
                    </template>
                </FormField>

                <FormField label="Photograph" :span="3" :error="form.errors.photo">
                    <div class="flex flex-wrap items-center gap-4 rounded-lg border border-dashed p-4">
                        <div
                            class="grid size-20 shrink-0 place-items-center overflow-hidden rounded-xl border bg-muted/40 text-muted-foreground"
                        >
                            <img
                                v-if="photoPreview"
                                :src="photoPreview"
                                alt="Selected photograph preview"
                                class="size-full object-cover"
                            />
                            <User v-else class="size-7" aria-hidden="true" />
                        </div>
                        <div class="min-w-0 flex-1 space-y-2">
                            <p class="text-xs text-muted-foreground">
                                JPG or PNG, square works best. Shown on the employee card and profile.
                            </p>
                            <div class="flex flex-wrap items-center gap-2">
                                <label
                                    class="inline-flex h-9 cursor-pointer items-center gap-2 rounded-md border border-input bg-background px-3 text-sm font-medium transition hover:bg-accent"
                                >
                                    <Upload class="size-4" aria-hidden="true" />
                                    {{ form.photo ? 'Replace photo' : 'Choose photo' }}
                                    <input type="file" accept="image/*" class="sr-only" @change="onPhotoChange" />
                                </label>
                                <Button v-if="form.photo" type="button" variant="ghost" size="sm" @click="clearPhoto">
                                    <X class="size-4" />Remove
                                </Button>
                                <span v-if="form.photo" class="truncate text-xs text-muted-foreground">
                                    {{ form.photo.name }}
                                </span>
                            </div>
                        </div>
                    </div>
                </FormField>
            </FormSection>

            <!-- ── Employment ────────────────────────────────────────── -->
            <FormSection
                v-else-if="activeTab === 'employment'"
                title="Employment Details"
                description="Payroll-file fields, including the ones imported from the HR spreadsheet."
                :icon="Briefcase"
                :cols="3"
            >
                <FormField label="File no">
                    <template #default="{ id }">
                        <Input :id="id" v-model="form.file_no" placeholder="File-001" />
                    </template>
                </FormField>

                <FormField label="Card issue date">
                    <template #default="{ id }">
                        <Input :id="id" v-model="form.card_issue_date" type="date" />
                    </template>
                </FormField>

                <FormField label="Job type">
                    <FormSelect v-model="form.job_type" placeholder="Select">
                        <option value="provisional">Provisional</option>
                        <option value="permanent">Permanent</option>
                        <option value="contract">Contract</option>
                    </FormSelect>
                </FormField>

                <FormField label="Joining salary" :error="form.errors.joining_salary">
                    <template #default="{ id }">
                        <Input :id="id" v-model.number="form.joining_salary" type="number" step="0.01" min="0" placeholder="0.00" />
                    </template>
                </FormField>

                <FormField label="Service year">
                    <template #default="{ id }">
                        <Input :id="id" v-model.number="form.service_year" type="number" min="0" placeholder="0" />
                    </template>
                </FormField>

                <FormField label="Payment criteria">
                    <FormSelect v-model="form.payment_criteria">
                        <option value="general">General</option>
                        <option value="special">Special</option>
                    </FormSelect>
                </FormField>

                <FormField :span="3" class="pt-1">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <FormCheckbox
                            v-model="form.allow_overtime"
                            label="Allow overtime"
                            description="Overtime requests can be raised and approved for this employee."
                        />
                        <FormCheckbox
                            v-model="form.allow_bonus"
                            label="Allow bonus"
                            description="Include this employee in festival and performance bonus runs."
                        />
                    </div>
                </FormField>
            </FormSection>

            <!-- ── Profile ───────────────────────────────────────────── -->
            <FormSection
                v-else-if="activeTab === 'profile'"
                title="Employee Profile"
                description="Family, emergency contact and background — used for HR correspondence."
                :icon="Contact"
                :cols="3"
            >
                <FormField label="Father's name">
                    <template #default="{ id }">
                        <Input :id="id" v-model="form.profile.father_name" />
                    </template>
                </FormField>

                <FormField label="Mother's name">
                    <template #default="{ id }">
                        <Input :id="id" v-model="form.profile.mother_name" />
                    </template>
                </FormField>

                <FormField label="Nationality">
                    <template #default="{ id }">
                        <Input :id="id" v-model="form.profile.nationality" />
                    </template>
                </FormField>

                <FormField label="Present address" :span="2">
                    <template #default="{ id }">
                        <Input :id="id" v-model="form.profile.present_address" />
                    </template>
                </FormField>

                <FormField label="Religion">
                    <template #default="{ id }">
                        <Input :id="id" v-model="form.profile.religion" />
                    </template>
                </FormField>

                <FormField label="Permanent address" :span="2">
                    <template #default="{ id }">
                        <Input :id="id" v-model="form.profile.permanent_address" />
                    </template>
                </FormField>

                <FormField label="Emergency contact">
                    <template #default="{ id }">
                        <Input :id="id" v-model="form.profile.emergency_contact_name" placeholder="Full name" />
                    </template>
                </FormField>

                <FormField label="Emergency phone">
                    <template #default="{ id }">
                        <Input :id="id" v-model="form.profile.emergency_contact_phone" placeholder="01XXXXXXXXX" />
                    </template>
                </FormField>

                <FormField label="Relationship">
                    <template #default="{ id }">
                        <Input :id="id" v-model="form.profile.emergency_contact_relation" placeholder="Father / Spouse" />
                    </template>
                </FormField>

                <FormField label="Biography" :span="3">
                    <Textarea v-model="form.profile.bio" rows="3" placeholder="Short professional summary" />
                </FormField>
            </FormSection>

            <!-- ── Attendance policy ─────────────────────────────────── -->
            <FormSection
                v-else-if="activeTab === 'policy'"
                title="Attendance Policy"
                description="How this employee's punches are classified. These settings drive Late, Early Out and Off Day."
                :icon="Clock"
                :cols="3"
            >
                <FormField label="Shift type" help="Custom opens a 72-hour window, used for on-call doctors.">
                    <FormSelect v-model="form.policy.shift_type">
                        <option>Fixed</option>
                        <option>Flexible</option>
                        <option>Custom</option>
                    </FormSelect>
                </FormField>

                <FormField label="Grace in (minutes)" help="Allowed lateness before a Late status.">
                    <template #default="{ id }">
                        <Input :id="id" v-model.number="form.policy.grace_time_in" type="number" min="0" />
                    </template>
                </FormField>

                <FormField label="Grace out (minutes)" help="Allowed early departure before an Early Out status.">
                    <template #default="{ id }">
                        <Input :id="id" v-model.number="form.policy.grace_time_out" type="number" min="0" />
                    </template>
                </FormField>

                <FormField label="Duty hours per day" help="Used for automatic shift matching and overtime calculation.">
                    <FormSelect v-model.number="form.policy.duty_hours">
                        <option :value="6">6 hours</option>
                        <option :value="7">7 hours</option>
                        <option :value="8">8 hours</option>
                    </FormSelect>
                </FormField>

                <FormField label="Shift match buffer (minutes)" help="± window when matching a punch to the nearest shift.">
                    <template #default="{ id }">
                        <Input :id="id" v-model.number="form.policy.buffer_minutes" type="number" min="0" max="300" placeholder="90" />
                    </template>
                </FormField>

                <FormField
                    label="Default fallback off day"
                    help="Contracted weekly day off. Applied when no roster duty exists for the date, and also over a roster duty left at its default “Scheduled” state — bulk rostering writes a duty for every calendar day, so without this the day off would be reported as Absent. An explicit roster status of “Off” always wins. If the employee punches in on this day it is classified Extra Duty, never lost. Does not alter automatic shift detection."
                >
                    <FormSelect v-model="form.policy.fallback_off_day">
                        <option :value="null">None — use roster only</option>
                        <option :value="0">Sunday</option>
                        <option :value="1">Monday</option>
                        <option :value="2">Tuesday</option>
                        <option :value="3">Wednesday</option>
                        <option :value="4">Thursday</option>
                        <option :value="5">Friday</option>
                        <option :value="6">Saturday</option>
                    </FormSelect>
                </FormField>

                <FormField :span="3" class="pt-1">
                    <div class="grid grid-cols-1 gap-3 lg:grid-cols-3">
                        <FormCheckbox
                            v-model="form.policy.is_punishable"
                            label="Punishable"
                            description="Lateness and early departure affect salary deduction."
                        />
                        <FormCheckbox
                            v-model="form.policy.auto_shift"
                            label="Automatic shift detection"
                            description="Infer the shift from punch times when no roster duty exists."
                        />
                        <FormCheckbox
                            v-model="form.policy.allow_fallback"
                            label="Allow flexible fallback"
                            description="Fall back to a flexible shift when no fixed shift matches."
                        />
                    </div>
                </FormField>
            </FormSection>

            <!-- ── Salary ────────────────────────────────────────────── -->
            <FormSection
                v-else-if="activeTab === 'salary'"
                title="Salary Structure"
                description="Monthly components in BDT. Net salary is what payroll uses as the base."
                :icon="Wallet"
                :cols="4"
            >
                <FormField label="Basic salary">
                    <template #default="{ id }">
                        <Input :id="id" v-model.number="form.salary.basic_salary" type="number" step="0.01" min="0" />
                    </template>
                </FormField>

                <FormField label="Gross salary">
                    <template #default="{ id }">
                        <Input :id="id" v-model.number="form.salary.gross_salary" type="number" step="0.01" min="0" />
                    </template>
                </FormField>

                <FormField label="Net salary">
                    <template #default="{ id }">
                        <Input :id="id" v-model.number="form.salary.net_salary" type="number" step="0.01" min="0" />
                    </template>
                </FormField>

                <FormField label="Pay grade">
                    <template #default="{ id }">
                        <Input :id="id" v-model="form.salary.pay_grade" placeholder="G-7" />
                    </template>
                </FormField>

                <FormField label="House rent">
                    <template #default="{ id }">
                        <Input :id="id" v-model.number="form.salary.house_rent" type="number" step="0.01" min="0" />
                    </template>
                </FormField>

                <FormField label="Medical allowance">
                    <template #default="{ id }">
                        <Input :id="id" v-model.number="form.salary.medical_allowance" type="number" step="0.01" min="0" />
                    </template>
                </FormField>

                <FormField label="Conveyance">
                    <template #default="{ id }">
                        <Input :id="id" v-model.number="form.salary.conveyance_allowance" type="number" step="0.01" min="0" />
                    </template>
                </FormField>

                <FormField label="Other allowance">
                    <template #default="{ id }">
                        <Input :id="id" v-model.number="form.salary.other_allowance" type="number" step="0.01" min="0" />
                    </template>
                </FormField>

                <FormField label="Payment method">
                    <FormSelect v-model="form.salary.payment_method">
                        <option value="cash">Cash</option>
                        <option value="bank">Bank transfer</option>
                    </FormSelect>
                </FormField>

                <FormField
                    label="Bank name"
                    :help="form.salary.payment_method === 'bank' ? undefined : 'Not used while paying by cash.'"
                >
                    <template #default="{ id }">
                        <Input
                            :id="id"
                            v-model="form.salary.bank_name"
                            :placeholder="form.salary.payment_method === 'bank' ? 'e.g. DBBL' : 'Cash — N/A'"
                            :disabled="form.salary.payment_method !== 'bank'"
                        />
                    </template>
                </FormField>

                <FormField label="Bank account no">
                    <template #default="{ id }">
                        <Input
                            :id="id"
                            v-model="form.salary.bank_account_no"
                            placeholder="Account number"
                            :disabled="form.salary.payment_method !== 'bank'"
                        />
                    </template>
                </FormField>

                <FormField label="Effective from">
                    <template #default="{ id }">
                        <Input :id="id" v-model="form.salary.effective_from" type="date" />
                    </template>
                </FormField>

                <FormField label="Effective to" help="Leave blank for an open-ended structure.">
                    <template #default="{ id }">
                        <Input :id="id" v-model="form.salary.effective_to" type="date" />
                    </template>
                </FormField>

                <FormField label="Remarks" :span="3">
                    <template #default="{ id }">
                        <Input :id="id" v-model="form.salary.remarks" placeholder="Initial salary structure" />
                    </template>
                </FormField>
            </FormSection>

            <!-- ── Education ─────────────────────────────────────────── -->
            <FormSection
                v-else-if="activeTab === 'education'"
                title="Education"
                :description="`${form.educations.length} qualification${form.educations.length === 1 ? '' : 's'}`"
                :icon="GraduationCap"
                plain
            >
                <template #actions>
                    <Button type="button" variant="outline" size="sm" @click="addEducation">
                        <Plus class="size-4" />Add
                    </Button>
                </template>

                <div class="space-y-3">
                    <div
                        v-for="(ed, idx) in form.educations"
                        :key="idx"
                        class="relative rounded-lg border bg-muted/20 p-4"
                    >
                        <div class="mb-3 flex items-center justify-between gap-2">
                            <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                Qualification {{ idx + 1 }}
                            </p>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="size-7 text-muted-foreground hover:text-destructive"
                                :aria-label="`Remove qualification ${idx + 1}`"
                                @click="removeEducation(idx)"
                            >
                                <Trash2 class="size-3.5" />
                            </Button>
                        </div>

                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                            <FormField label="Degree">
                                <Input v-model="ed.degree" placeholder="BSc in CSE" />
                            </FormField>
                            <FormField label="Institute">
                                <Input v-model="ed.institute" placeholder="University of Dhaka" />
                            </FormField>
                            <FormField label="Board">
                                <Input v-model="ed.board" placeholder="Dhaka" />
                            </FormField>
                            <FormField label="Passing year">
                                <Input v-model="ed.passing_year" placeholder="2020" />
                            </FormField>
                            <FormField label="Grade">
                                <Input v-model="ed.grade" placeholder="First Class" />
                            </FormField>
                            <FormField label="GPA">
                                <Input v-model="ed.gpa" placeholder="3.50" />
                            </FormField>
                        </div>

                        <FormField label="Notes" class="mt-4">
                            <Input v-model="ed.description" placeholder="Honours, thesis, distinction…" />
                        </FormField>
                    </div>
                </div>
            </FormSection>

            <!-- ── Experience ────────────────────────────────────────── -->
            <FormSection
                v-else-if="activeTab === 'experience'"
                title="Experience"
                :description="`${form.experiences.length} role${form.experiences.length === 1 ? '' : 's'}`"
                :icon="Building2"
                plain
            >
                <template #actions>
                    <Button type="button" variant="outline" size="sm" @click="addExperience">
                        <Plus class="size-4" />Add
                    </Button>
                </template>

                <div class="space-y-3">
                    <div
                        v-for="(ex, idx) in form.experiences"
                        :key="idx"
                        class="relative rounded-lg border bg-muted/20 p-4"
                    >
                        <div class="mb-3 flex items-center justify-between gap-2">
                            <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                Role {{ idx + 1 }}
                            </p>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="size-7 text-muted-foreground hover:text-destructive"
                                :aria-label="`Remove role ${idx + 1}`"
                                @click="removeExperience(idx)"
                            >
                                <Trash2 class="size-3.5" />
                            </Button>
                        </div>

                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                            <FormField label="Company">
                                <Input v-model="ex.company_name" placeholder="Company name" />
                            </FormField>
                            <FormField label="Designation">
                                <Input v-model="ex.designation" placeholder="Senior Developer" />
                            </FormField>
                            <FormField label="Department">
                                <Input v-model="ex.department" placeholder="Engineering" />
                            </FormField>
                            <FormField label="From">
                                <Input v-model="ex.from_date" type="date" />
                            </FormField>
                            <FormField label="To" :help="ex.is_current ? 'Omitted — this is the current role.' : undefined">
                                <Input v-model="ex.to_date" type="date" :disabled="ex.is_current" />
                            </FormField>
                            <FormField label="Responsibilities">
                                <Input v-model="ex.responsibilities" placeholder="Team lead, backend…" />
                            </FormField>
                        </div>

                        <div class="mt-4 max-w-md">
                            <FormCheckbox v-model="ex.is_current" label="Currently working here" />
                        </div>
                    </div>
                </div>
            </FormSection>

            <!-- ── Skills ────────────────────────────────────────────── -->
            <FormSection
                v-else-if="activeTab === 'skills'"
                title="Skills"
                :description="`${form.skills.length} skill${form.skills.length === 1 ? '' : 's'}`"
                :icon="Sparkles"
                plain
            >
                <template #actions>
                    <Button type="button" variant="outline" size="sm" @click="addSkill">
                        <Plus class="size-4" />Add
                    </Button>
                </template>

                <div class="space-y-3">
                    <div
                        v-for="(sk, idx) in form.skills"
                        :key="idx"
                        class="rounded-lg border bg-muted/20 p-4"
                    >
                        <div class="mb-3 flex items-center justify-between gap-2">
                            <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                Skill {{ idx + 1 }}
                            </p>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="size-7 text-muted-foreground hover:text-destructive"
                                :aria-label="`Remove skill ${idx + 1}`"
                                @click="removeSkill(idx)"
                            >
                                <Trash2 class="size-3.5" />
                            </Button>
                        </div>

                        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                            <FormField label="Skill" required>
                                <Input v-model="sk.skill_name" placeholder="Laravel" />
                            </FormField>
                            <FormField label="Proficiency">
                                <FormSelect v-model="sk.proficiency">
                                    <option>Beginner</option>
                                    <option>Intermediate</option>
                                    <option>Advanced</option>
                                    <option>Expert</option>
                                </FormSelect>
                            </FormField>
                            <FormField label="Years of experience">
                                <Input v-model.number="sk.years_of_experience" type="number" min="0" step="0.5" />
                            </FormField>
                        </div>
                    </div>
                </div>
            </FormSection>

            <FormActions
                submit-label="Create Employee"
                :cancel-href="route('hrm.employees.index')"
                :processing="form.processing"
                :dirty="form.isDirty"
            >
                <template #summary>
                    <span class="text-muted-foreground">
                        Section {{ tabs.findIndex((t) => t.id === activeTab) + 1 }} of {{ tabs.length }} ·
                        {{ tabs.find((t) => t.id === activeTab)?.label }}
                    </span>
                </template>
            </FormActions>
        </form>
    </AdminLayout>
</template>
