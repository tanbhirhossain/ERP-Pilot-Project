<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { User, Shield, DollarSign, GraduationCap, Briefcase, Wrench, Plus, Trash2 } from 'lucide-vue-next';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import SearchableSelect from '../../../../../CORE/Resources/js/Components/SearchableSelect.vue';

defineProps<{ departmentOptions:any[]; designationOptions:any[]; organizationOptions:any[]; userOptions:any[] }>();

const form=useForm({
  employee_id:'', biometric_id:'', first_name:'', last_name:'', email:'', phone:'', nid:'', gender:'', date_of_birth:'', joining_date:'', organization_id:null, department_id:null, designation_id:null, user_id:null,
  employment_type:'Permanent', status:'Active', blood_group:'', marital_status:'', photo:null as File|null, address:'',
  file_no:'', card_issue_date:'', job_type:'', joining_salary:null as number|null, allow_overtime:false, allow_bonus:false, payment_criteria:'general', service_year:null as number|null,
  profile:{father_name:'',mother_name:'',present_address:'',permanent_address:'',emergency_contact_name:'',emergency_contact_phone:'',emergency_contact_relation:'',nationality:'Bangladeshi',religion:'',bio:''},
  policy:{shift_type:'Fixed',grace_time_in:15,grace_time_out:15,is_punishable:true,duty_hours:8,auto_shift:true,buffer_minutes:90,allow_fallback:true,fallback_off_day:null},
  salary:{basic_salary:0,gross_salary:0,house_rent:0,medical_allowance:0,conveyance_allowance:0,other_allowance:0,net_salary:0,pay_grade:'',effective_from:'',effective_to:'',is_current:true,payment_method:'cash',bank_name:'',bank_account_no:'',remarks:''},
  educations:[{degree:'',institute:'',board:'',passing_year:'',grade:'',gpa:'',description:''}],
  experiences:[{company_name:'',designation:'',department:'',from_date:'',to_date:'',is_current:false,responsibilities:'',description:''}],
  skills:[{skill_name:'',proficiency:'Intermediate',years_of_experience:null as number|null,description:''}],
});

const activeTab = ref('basic');
const tabs = [
  { id: 'basic', label: 'Basic Information' },
  { id: 'employment', label: 'Employment Details' },
  { id: 'profile', label: 'Employee Profile' },
  { id: 'policy', label: 'Attendance Policy' },
  { id: 'salary', label: 'Salary Details' },
  { id: 'education', label: 'Education' },
  { id: 'experience', label: 'Experience' },
  { id: 'skills', label: 'Skills' },
];

function addEducation(){ form.educations.push({degree:'',institute:'',board:'',passing_year:'',grade:'',gpa:'',description:''}); }
function removeEducation(i:number){ form.educations.splice(i,1); if(form.educations.length===0) addEducation(); }
function addExperience(){ form.experiences.push({company_name:'',designation:'',department:'',from_date:'',to_date:'',is_current:false,responsibilities:'',description:''}); }
function removeExperience(i:number){ form.experiences.splice(i,1); if(form.experiences.length===0) addExperience(); }
function addSkill(){ form.skills.push({skill_name:'',proficiency:'Intermediate',years_of_experience:null,description:''}); }
function removeSkill(i:number){ form.skills.splice(i,1); if(form.skills.length===0) addSkill(); }

function tabForError(field: string) {
  if (field.startsWith('profile.')) return 'profile';
  if (field.startsWith('policy.')) return 'policy';
  if (field.startsWith('salary.')) return 'salary';
  if (field.startsWith('educations.')) return 'education';
  if (field.startsWith('experiences.')) return 'experience';
  if (field.startsWith('skills.')) return 'skills';
  if (['file_no', 'card_issue_date', 'job_type', 'joining_salary', 'allow_overtime', 'allow_bonus', 'payment_criteria', 'service_year'].some((key) => field.startsWith(key))) return 'employment';
  return 'basic';
}
function submit(){
  form.post(route('hrm.employees.store'), {
    forceFormData: true,
    onError: (errors) => { const first = Object.keys(errors)[0]; if (first) activeTab.value = tabForError(first); },
  });
}
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'HRM'},{title:'Employees',href:route('hrm.employees.index')},{title:'Create'}]">
    <Head title="Create Employee"/>
    <div class="max-w-4xl mx-auto space-y-4">
      <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold">Add Employee</h1>
        <div class="text-xs text-muted-foreground">Use the tabs to move between employee details.</div>
      </div>

      <form @submit.prevent="submit" class="space-y-4">
        <nav class="-mx-1 flex gap-2 overflow-x-auto rounded-xl border bg-card p-2" role="tablist" aria-label="Employee form sections">
          <button v-for="tab in tabs" :key="tab.id" type="button" role="tab" :aria-selected="activeTab === tab.id" @click="activeTab = tab.id"
            class="shrink-0 rounded-lg px-3 py-2 text-sm font-medium transition"
            :class="activeTab === tab.id ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:bg-muted hover:text-foreground'">
            {{ tab.label }}
          </button>
        </nav>
        <p v-if="Object.keys(form.errors).length" role="alert" class="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">
          {{ Object.values(form.errors)[0] }}
        </p>
        <!-- Basic Information - Always Open -->
        <section v-if="activeTab === 'basic'" role="tabpanel" class="bg-card border rounded-xl p-4">
          <h2 class="mb-4 text-base font-semibold">Basic Information</h2>

          <div class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div class="space-y-2"><Label>Employee ID (auto if blank)</Label><Input v-model="form.employee_id" placeholder="EMP-0001"/><p v-if="form.errors.employee_id" class="text-sm text-destructive">{{ form.errors.employee_id }}</p></div>
              <div class="space-y-2"><Label>Biometric ID <span class="text-xs text-emerald-600">(ZK UserID - direct mapping)</span></Label><Input v-model="form.biometric_id" placeholder="e.g. 19080"/><p v-if="form.errors.biometric_id" class="text-sm text-destructive">{{ form.errors.biometric_id }}</p><p class="text-[11px] text-muted-foreground">Direct ZK enrollment ID. Leave blank if no device. Must match device device_user_id for pairing.</p></div>
              <div class="space-y-2"><Label>Linked User (optional)</Label><SearchableSelect v-model="form.user_id" :options="userOptions" placeholder="Select user account"/></div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div class="space-y-2"><Label>First Name *</Label><Input v-model="form.first_name"/><p v-if="form.errors.first_name" class="text-sm text-destructive">{{ form.errors.first_name }}</p></div>
              <div class="space-y-2"><Label>Last Name</Label><Input v-model="form.last_name"/></div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div class="space-y-2"><Label>Email</Label><Input v-model="form.email" type="email"/></div>
              <div class="space-y-2"><Label>Phone</Label><Input v-model="form.phone"/></div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div class="space-y-2"><Label>NID</Label><Input v-model="form.nid"/></div>
              <div class="space-y-2"><Label>Photo</Label><Input type="file" @change="e => form.photo = (e.target as HTMLInputElement).files?.[0] || null"/></div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div class="space-y-2"><Label>Department</Label><SearchableSelect v-model="form.department_id" :options="departmentOptions" placeholder="Select department"/></div>
              <div class="space-y-2"><Label>Designation</Label><SearchableSelect v-model="form.designation_id" :options="designationOptions" placeholder="Select designation"/></div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div class="space-y-2"><Label>Employment Type</Label><select v-model="form.employment_type" class="w-full h-9 rounded-md border bg-background px-3 text-sm"><option>Permanent</option><option>Contract</option><option>Probation</option><option>Intern</option><option>Part-time</option></select></div>
              <div class="space-y-2"><Label>Status</Label><select v-model="form.status" class="w-full h-9 rounded-md border bg-background px-3 text-sm"><option>Active</option><option>Inactive</option><option>On Leave</option><option>Suspended</option><option>Terminated</option><option>Resigned</option></select></div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div class="space-y-2"><Label>Joining Date</Label><Input v-model="form.joining_date" type="date"/></div>
              <div class="space-y-2"><Label>Date of Birth</Label><Input v-model="form.date_of_birth" type="date"/></div>
              <div class="space-y-2"><Label>Gender</Label><select v-model="form.gender" class="w-full h-9 rounded-md border bg-background px-3 text-sm"><option value="">Select</option><option>Male</option><option>Female</option><option>Other</option></select></div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div class="space-y-2"><Label>Blood Group</Label><Input v-model="form.blood_group" placeholder="A+"/></div>
              <div class="space-y-2"><Label>Marital Status</Label><select v-model="form.marital_status" class="w-full h-9 rounded-md border bg-background px-3 text-sm"><option value="">Select</option><option>Single</option><option>Married</option><option>Divorced</option></select></div>
            </div>
            <div class="space-y-2"><Label>Present Address</Label><Input v-model="form.address" placeholder="Full address"/></div>
          </div>
        </section>


        <!-- Employment Details (Excel import fields) -->
        <section v-if="activeTab === 'employment'" role="tabpanel" class="bg-card border rounded-xl p-4">
          <h2 class="mb-4 text-base font-semibold">Employment Details</h2>

          <div class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div class="space-y-2"><Label>File No</Label><Input v-model="form.file_no" placeholder="File-001"/></div>
              <div class="space-y-2"><Label>Card Issue Date</Label><Input v-model="form.card_issue_date" type="date"/></div>
              <div class="space-y-2"><Label>Job Type</Label><select v-model="form.job_type" class="w-full h-9 rounded-md border bg-background px-3 text-sm"><option value="">Select</option><option>provisional</option><option>permanent</option><option>contract</option></select></div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div class="space-y-2"><Label>Joining Salary</Label><Input v-model.number="form.joining_salary" type="number" step="0.01"/></div>
              <div class="space-y-2"><Label>Service Year</Label><Input v-model.number="form.service_year" type="number" min="0"/></div>
              <div class="space-y-2"><Label>Payment Criteria</Label><select v-model="form.payment_criteria" class="w-full h-9 rounded-md border bg-background px-3 text-sm"><option>general</option><option>special</option></select></div>
            </div>
            <div class="flex gap-6">
              <label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.allow_overtime" class="rounded"/> Allow Overtime</label>
              <label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.allow_bonus" class="rounded"/> Allow Bonus</label>
            </div>
          </div>
        </section>

<!-- Profile -->
        <section v-if="activeTab === 'profile'" role="tabpanel" class="bg-card border rounded-xl p-4">
          <h2 class="mb-4 text-base font-semibold">Employee Profile</h2>

          <div class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div class="space-y-2"><Label>Father's Name</Label><Input v-model="form.profile.father_name"/></div>
              <div class="space-y-2"><Label>Mother's Name</Label><Input v-model="form.profile.mother_name"/></div>
            </div>
            <div class="space-y-2"><Label>Present Address</Label><Input v-model="form.profile.present_address"/></div>
            <div class="space-y-2"><Label>Permanent Address</Label><Input v-model="form.profile.permanent_address"/></div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div class="space-y-2"><Label>Emergency Contact Name</Label><Input v-model="form.profile.emergency_contact_name"/></div>
              <div class="space-y-2"><Label>Emergency Phone</Label><Input v-model="form.profile.emergency_contact_phone"/></div>
              <div class="space-y-2"><Label>Relation</Label><Input v-model="form.profile.emergency_contact_relation" placeholder="Father/Spouse"/></div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div class="space-y-2"><Label>Nationality</Label><Input v-model="form.profile.nationality"/></div>
              <div class="space-y-2"><Label>Religion</Label><Input v-model="form.profile.religion"/></div>
              <div class="space-y-2"><Label>Bio</Label><Input v-model="form.profile.bio"/></div>
            </div>
          </div>
        </section>

        <!-- Policy -->
        <section v-if="activeTab === 'policy'" role="tabpanel" class="bg-card border rounded-xl p-4">
          <h2 class="mb-4 text-base font-semibold">Attendance Policy</h2>

          <div class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div class="space-y-2"><Label>Shift Type</Label><select v-model="form.policy.shift_type" class="w-full h-9 rounded-md border bg-background px-3 text-sm"><option>Fixed</option><option>Flexible</option><option>Custom</option></select><p class="text-xs text-muted-foreground">Custom=72h window for doctors</p></div>
              <div class="space-y-2"><Label>Grace In (min)</Label><Input v-model.number="form.policy.grace_time_in" type="number"/></div>
              <div class="space-y-2"><Label>Grace Out (min)</Label><Input v-model.number="form.policy.grace_time_out" type="number"/></div>
            </div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.policy.is_punishable" class="rounded"/> Punishable (Late/Early affects salary)</label>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div class="space-y-2"><Label>Duty Hours (per day)</Label><select v-model="form.policy.duty_hours" class="w-full h-9 rounded-md border bg-background px-3 text-sm"><option :value="6">6 hours</option><option :value="7">7 hours</option><option :value="8">8 hours</option></select><p class="text-xs text-muted-foreground">Used for auto-shift & OT calc</p></div>
              <div class="space-y-2 flex items-center gap-2 pt-6"><label class="flex items-center gap-2 text-sm font-medium"><input type="checkbox" v-model="form.policy.auto_shift" class="rounded"/> Auto Shift (detect if no roster)</label></div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div class="space-y-2"><Label>Default fallback off day</Label><select v-model="form.policy.fallback_off_day" class="w-full h-9 rounded-md border bg-background px-3 text-sm"><option :value="null">None — use roster only</option><option :value="0">Sunday</option><option :value="1">Monday</option><option :value="2">Tuesday</option><option :value="3">Wednesday</option><option :value="4">Thursday</option><option :value="5">Friday</option><option :value="6">Saturday</option></select><p class="text-xs text-muted-foreground">Used only when no roster duty exists for the date; it does not alter automatic shift detection.</p></div>
              <div class="space-y-2"><Label>Buffer Window (minutes)</Label><Input v-model.number="form.policy.buffer_minutes" type="number" min="0" max="300" placeholder="90"/><p class="text-xs text-muted-foreground">± window for nearest shift match</p></div>
              <div class="space-y-2 flex items-center gap-2 pt-6"><label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.policy.allow_fallback" class="rounded"/> Allow Flexible fallback (if no shift matches)</label></div>
            </div>
          </div>
        </section>

        <!-- Salary Full -->
        <section v-if="activeTab === 'salary'" role="tabpanel" class="bg-card border rounded-xl p-4">
          <h2 class="mb-4 text-base font-semibold">Salary Details</h2>

          <div class="space-y-4">
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
              <div class="space-y-2"><Label>Basic Salary</Label><Input v-model.number="form.salary.basic_salary" type="number" step="0.01"/></div>
              <div class="space-y-2"><Label>Gross Salary</Label><Input v-model.number="form.salary.gross_salary" type="number" step="0.01"/></div>
              <div class="space-y-2"><Label>House Rent</Label><Input v-model.number="form.salary.house_rent" type="number" step="0.01"/></div>
              <div class="space-y-2"><Label>Medical Allowance</Label><Input v-model.number="form.salary.medical_allowance" type="number" step="0.01"/></div>
              <div class="space-y-2"><Label>Conveyance</Label><Input v-model.number="form.salary.conveyance_allowance" type="number" step="0.01"/></div>
              <div class="space-y-2"><Label>Other Allowance</Label><Input v-model.number="form.salary.other_allowance" type="number" step="0.01"/></div>
              <div class="space-y-2"><Label>Net Salary</Label><Input v-model.number="form.salary.net_salary" type="number" step="0.01"/></div>
              <div class="space-y-2"><Label>Payment Method</Label><select v-model="form.salary.payment_method" class="w-full h-9 rounded-md border bg-background px-3 text-sm"><option>cash</option><option>bank</option></select></div>
              <div class="space-y-2"><Label>Bank Name</Label><Input v-model="form.salary.bank_name" placeholder="DBBL" v-if="form.salary.payment_method==='bank'"/><Input v-model="form.salary.bank_name" placeholder="Cash - N/A" v-else disabled/></div>
              <div class="space-y-2"><Label>Bank A/C No</Label><Input v-model="form.salary.bank_account_no" placeholder="Account number"/></div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div class="space-y-2"><Label>Pay Grade</Label><Input v-model="form.salary.pay_grade" placeholder="G-7"/></div>
              <div class="space-y-2"><Label>Effective From</Label><Input v-model="form.salary.effective_from" type="date"/></div>
              <div class="space-y-2"><Label>Effective To</Label><Input v-model="form.salary.effective_to" type="date"/></div>
            </div>
            <div class="space-y-2"><Label>Remarks</Label><Input v-model="form.salary.remarks" placeholder="Initial salary"/></div>
          </div>
        </section>

        <!-- Education -->
        <section v-if="activeTab === 'education'" role="tabpanel" class="bg-card border rounded-xl p-4">
          <h2 class="mb-4 text-base font-semibold">Education</h2>

          <div class="space-y-4">
            <div v-for="(ed, idx) in form.educations" :key="idx" class="border rounded-lg p-3 space-y-3 relative">
              <Button type="button" variant="ghost" size="icon" class="absolute top-2 right-2 size-6" @click="removeEducation(idx)"><Trash2 class="size-3"/></Button>
              <p class="text-xs font-medium text-muted-foreground">#{{ idx+1 }}</p>
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div class="space-y-1"><Label>Degree</Label><Input v-model="ed.degree" placeholder="BSc in CSE"/></div>
                <div class="space-y-1"><Label>Institute</Label><Input v-model="ed.institute" placeholder="DU"/></div>
                <div class="space-y-1"><Label>Board</Label><Input v-model="ed.board" placeholder="Dhaka"/></div>
                <div class="space-y-1"><Label>Passing Year</Label><Input v-model="ed.passing_year" placeholder="2020"/></div>
                <div class="space-y-1"><Label>Grade</Label><Input v-model="ed.grade" placeholder="First Class"/></div>
                <div class="space-y-1"><Label>GPA</Label><Input v-model="ed.gpa" placeholder="3.50"/></div>
              </div>
              <div class="space-y-1"><Label>Description</Label><Input v-model="ed.description" placeholder="Honors, thesis..."/></div>
            </div>
            <Button type="button" variant="outline" size="sm" @click="addEducation"><Plus class="size-4"/> Add Education</Button>
          </div>
        </section>

        <!-- Experience -->
        <section v-if="activeTab === 'experience'" role="tabpanel" class="bg-card border rounded-xl p-4">
          <h2 class="mb-4 text-base font-semibold">Experience</h2>

          <div class="space-y-4">
            <div v-for="(ex, idx) in form.experiences" :key="idx" class="border rounded-lg p-3 space-y-3 relative">
              <Button type="button" variant="ghost" size="icon" class="absolute top-2 right-2 size-6" @click="removeExperience(idx)"><Trash2 class="size-3"/></Button>
              <p class="text-xs font-medium text-muted-foreground">#{{ idx+1 }}</p>
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div class="space-y-1"><Label>Company</Label><Input v-model="ex.company_name" placeholder="AMZ Hospital"/></div>
                <div class="space-y-1"><Label>Designation</Label><Input v-model="ex.designation" placeholder="Developer"/></div>
                <div class="space-y-1"><Label>Department</Label><Input v-model="ex.department" placeholder="IT"/></div>
                <div class="space-y-1 flex items-center gap-2 pt-6"><label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="ex.is_current" class="rounded"/> Current Job</label></div>
              </div>
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div class="space-y-1"><Label>From Date</Label><Input v-model="ex.from_date" type="date"/></div>
                <div class="space-y-1"><Label>To Date</Label><Input v-model="ex.to_date" type="date" :disabled="ex.is_current"/></div>
              </div>
              <div class="space-y-1"><Label>Responsibilities</Label><Input v-model="ex.responsibilities" placeholder="Team lead, backend..."/></div>
            </div>
            <Button type="button" variant="outline" size="sm" @click="addExperience"><Plus class="size-4"/> Add Experience</Button>
          </div>
        </section>

        <!-- Skills -->
        <section v-if="activeTab === 'skills'" role="tabpanel" class="bg-card border rounded-xl p-4">
          <h2 class="mb-4 text-base font-semibold">Skills</h2>

          <div class="space-y-4">
            <div v-for="(sk, idx) in form.skills" :key="idx" class="border rounded-lg p-3 space-y-3 relative">
              <Button type="button" variant="ghost" size="icon" class="absolute top-2 right-2 size-6" @click="removeSkill(idx)"><Trash2 class="size-3"/></Button>
              <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div class="space-y-1"><Label>Skill Name *</Label><Input v-model="sk.skill_name" placeholder="Laravel"/></div>
                <div class="space-y-1"><Label>Proficiency</Label><select v-model="sk.proficiency" class="w-full h-9 rounded-md border bg-background px-3 text-sm"><option>Beginner</option><option>Intermediate</option><option>Advanced</option><option>Expert</option></select></div>
                <div class="space-y-1"><Label>Years</Label><Input v-model.number="sk.years_of_experience" type="number" min="0"/></div>
              </div>
            </div>
            <Button type="button" variant="outline" size="sm" @click="addSkill"><Plus class="size-4"/> Add Skill</Button>
          </div>
        </section>

        <Button type="submit" :disabled="form.processing" class="w-full sm:w-auto">Create Employee</Button>
      </form>
    </div>
  </AdminLayout>
</template>
