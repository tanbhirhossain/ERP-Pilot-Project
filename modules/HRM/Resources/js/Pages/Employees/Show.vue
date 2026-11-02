<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { ChevronDown, User, Shield, DollarSign, GraduationCap, Briefcase, Wrench, Calendar, Fingerprint, UsersRound } from 'lucide-vue-next';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';

defineProps<{ employee:any; subordinates?: any[] }>();
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'HRM'},{title:'Employees',href:route('hrm.employees.index')},{title:employee.full_name}]">
    <Head :title="employee.full_name"/>
    <div class="max-w-4xl mx-auto space-y-4">
      <div class="flex items-start gap-4">
        <img v-if="employee.photo_url" :src="employee.photo_url" class="size-16 rounded-full object-cover border" :alt="employee.full_name"/>
        <div>
          <h1 class="text-xl font-bold">{{ employee.full_name }}</h1>
          <p class="text-sm text-muted-foreground">{{ employee.employee_id }} • {{ employee.designation?.name }} • {{ employee.department?.name }}</p>
          <p class="text-xs text-muted-foreground">{{ employee.status }} • {{ employee.employment_type }} • Joined {{ employee.joining_date }}</p>
        </div>
        <a :href="route('hrm.employees.edit',employee.id)" class="ml-auto text-sm text-primary hover:underline">Edit →</a>
      </div>

      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div class="bg-card border rounded-lg p-3"><p class="text-xs text-muted-foreground">Phone</p><p class="text-sm font-medium">{{ employee.phone||'—' }}</p></div>
        <div class="bg-card border rounded-lg p-3"><p class="text-xs text-muted-foreground">Email</p><p class="text-sm font-medium truncate">{{ employee.email||'—' }}</p></div>
        <div class="bg-card border rounded-lg p-3"><p class="text-xs text-muted-foreground">NID</p><p class="text-sm font-medium">{{ employee.nid||'—' }}</p></div>
        <div class="bg-card border rounded-lg p-3"><p class="text-xs text-muted-foreground">Blood • Marital</p><p class="text-sm font-medium">{{ employee.blood_group||'—' }} / {{ employee.marital_status||'—' }}</p></div>
      </div>

      <!-- Basic Details collapsible -->
      <Collapsible defaultOpen class="bg-card border rounded-xl">
        <CollapsibleTrigger class="w-full flex items-center justify-between p-4 hover:bg-muted/50">
          <div class="flex items-center gap-2"><User class="size-4 text-primary"/><h3 class="font-semibold text-sm">Employee Details</h3></div><ChevronDown class="size-4"/>
        </CollapsibleTrigger>
        <CollapsibleContent class="p-4 pt-0">
          <div class="grid grid-cols-2 gap-4 text-sm">
            <div><span class="text-muted-foreground">Biometric ID:</span> <span class="font-mono font-semibold" :class="employee.biometric_id ? 'text-emerald-600' : 'text-amber-600'">{{ employee.biometric_id||'— (not mapped)' }}</span></div>
            <div><span class="text-muted-foreground">Gender:</span> {{ employee.gender||'—' }}</div>
            <div><span class="text-muted-foreground">DOB:</span> {{ employee.date_of_birth||'—' }}</div>
            <div><span class="text-muted-foreground">Department:</span> {{ employee.department?.name||'—' }}</div>
            <div><span class="text-muted-foreground">Designation:</span> {{ employee.designation?.name||'—' }}</div>
            <div class="col-span-2"><span class="text-muted-foreground">Address:</span> {{ employee.address||'—' }}</div>
            <div class="col-span-2"><span class="text-muted-foreground">Photo:</span> <span v-if="employee.photo_url"><a :href="employee.photo_url" target="_blank" class="text-primary underline">View</a></span><span v-else>—</span></div>
          </div>
        </CollapsibleContent>
      </Collapsible>

      
      <!-- Employment Details (Excel) -->
      <Collapsible class="bg-card border rounded-xl">
        <CollapsibleTrigger class="w-full flex items-center justify-between p-4 hover:bg-muted/50">
          <div class="flex items-center gap-2"><Briefcase class="size-4 text-slate-500"/><h3 class="font-semibold text-sm">Employment Details</h3><span v-if="employee.file_no" class="text-xs bg-slate-500 text-white px-2 py-0.5 rounded-full">{{ employee.file_no }}</span></div><ChevronDown class="size-4"/>
        </CollapsibleTrigger>
        <CollapsibleContent class="p-4 pt-0">
          <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
            <div><span class="text-muted-foreground">File No:</span> {{ employee.file_no||'—' }}</div>
            <div><span class="text-muted-foreground">Card Issue:</span> {{ employee.card_issue_date||'—' }}</div>
            <div><span class="text-muted-foreground">Job Type:</span> {{ employee.job_type||'—' }}</div>
            <div><span class="text-muted-foreground">Joining Salary:</span> {{ employee.joining_salary||'—' }}</div>
            <div><span class="text-muted-foreground">Service Year:</span> {{ employee.service_year||'—' }}</div>
            <div><span class="text-muted-foreground">Payment Criteria:</span> {{ employee.payment_criteria||'—' }}</div>
            <div><span class="text-muted-foreground">Overtime:</span> {{ employee.allow_overtime ? 'Yes' : 'No' }}</div>
            <div><span class="text-muted-foreground">Bonus:</span> {{ employee.allow_bonus ? 'Yes' : 'No' }}</div>
          </div>
        </CollapsibleContent>
      </Collapsible>

<!-- Profile -->
      <Collapsible class="bg-card border rounded-xl" :defaultOpen="!!employee.profile">
        <CollapsibleTrigger class="w-full flex items-center justify-between p-4 hover:bg-muted/50">
          <div class="flex items-center gap-2"><User class="size-4 text-blue-500"/><h3 class="font-semibold text-sm">Employee Profile</h3><span v-if="employee.profile" class="text-xs bg-blue-500 text-white px-2 py-0.5 rounded-full">Present</span><span v-else class="text-xs text-muted-foreground">Not filled</span></div><ChevronDown class="size-4"/>
        </CollapsibleTrigger>
        <CollapsibleContent class="p-4 pt-0">
          <div v-if="employee.profile" class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
            <div><span class="text-muted-foreground">Father:</span> {{ employee.profile.father_name||'—' }}</div>
            <div><span class="text-muted-foreground">Mother:</span> {{ employee.profile.mother_name||'—' }}</div>
            <div class="col-span-2"><span class="text-muted-foreground">Present Address:</span> {{ employee.profile.present_address||'—' }}</div>
            <div class="col-span-2"><span class="text-muted-foreground">Permanent Address:</span> {{ employee.profile.permanent_address||'—' }}</div>
            <div><span class="text-muted-foreground">Emergency:</span> {{ employee.profile.emergency_contact_name||'—' }} ({{ employee.profile.emergency_contact_relation||'—' }})</div>
            <div><span class="text-muted-foreground">Emergency Phone:</span> {{ employee.profile.emergency_contact_phone||'—' }}</div>
            <div><span class="text-muted-foreground">Nationality:</span> {{ employee.profile.nationality||'—' }}</div>
            <div><span class="text-muted-foreground">Religion:</span> {{ employee.profile.religion||'—' }}</div>
            <div class="col-span-2"><span class="text-muted-foreground">Bio:</span> {{ employee.profile.bio||'—' }}</div>
          </div>
          <p v-else class="text-sm text-muted-foreground py-2">No profile information added.</p>
        </CollapsibleContent>
      </Collapsible>

      <!-- Policy -->
      <Collapsible class="bg-card border rounded-xl" :defaultOpen="!!employee.policy">
        <CollapsibleTrigger class="w-full flex items-center justify-between p-4 hover:bg-muted/50">
          <div class="flex items-center gap-2"><Shield class="size-4 text-amber-500"/><h3 class="font-semibold text-sm">Attendance Policy</h3><span v-if="employee.policy" class="text-xs bg-amber-500/10 text-amber-600 px-2 py-0.5 rounded-full">{{ employee.policy.shift_type }}</span></div><ChevronDown class="size-4"/>
        </CollapsibleTrigger>
        <CollapsibleContent class="p-4 pt-0">
          <div v-if="employee.policy" class="grid grid-cols-3 gap-4 text-sm">
            <div><span class="text-muted-foreground">Shift:</span> {{ employee.policy.shift_type }}</div>
            <div><span class="text-muted-foreground">Grace In:</span> {{ employee.policy.grace_time_in }}m</div>
            <div><span class="text-muted-foreground">Grace Out:</span> {{ employee.policy.grace_time_out }}m</div>
            <div><span class="text-muted-foreground">Punishable:</span> {{ employee.policy.is_punishable ? 'Yes' : 'No' }}</div>
          </div>
          <p v-else class="text-sm text-muted-foreground py-2">No policy assigned (defaults to Fixed 15m grace).</p>
        </CollapsibleContent>
      </Collapsible>

      <!-- Salary Full -->
      <Collapsible class="bg-card border rounded-xl" :defaultOpen="!!(employee.salaries && employee.salaries.length)">
        <CollapsibleTrigger class="w-full flex items-center justify-between p-4 hover:bg-muted/50">
          <div class="flex items-center gap-2"><DollarSign class="size-4 text-emerald-500"/><h3 class="font-semibold text-sm">Salary Breakdown</h3><span v-if="employee.salaries?.length" class="text-xs bg-emerald-500/10 text-emerald-600 px-2 py-0.5 rounded-full">{{ employee.salaries.length }} record(s)</span></div><ChevronDown class="size-4"/>
        </CollapsibleTrigger>
        <CollapsibleContent class="p-4 pt-0">
          <div v-if="employee.salaries && employee.salaries.length" class="space-y-3">
            <div v-for="sal in employee.salaries" :key="sal.id" class="border rounded-lg p-3 grid grid-cols-2 sm:grid-cols-3 gap-3 text-sm">
              <div><span class="text-muted-foreground">Basic:</span> {{ sal.basic_salary }}</div>
              <div><span class="text-muted-foreground">Gross:</span> {{ sal.gross_salary }}</div>
              <div><span class="text-muted-foreground">House Rent:</span> {{ sal.house_rent||0 }}</div>
              <div><span class="text-muted-foreground">Medical:</span> {{ sal.medical_allowance||0 }}</div>
              <div><span class="text-muted-foreground">Conveyance:</span> {{ sal.conveyance_allowance||0 }}</div>
              <div><span class="text-muted-foreground">Other:</span> {{ sal.other_allowance||0 }}</div>
              <div><span class="text-muted-foreground">Net:</span> {{ sal.net_salary||sal.gross_salary }}</div>
              <div><span class="text-muted-foreground">Pay Grade:</span> {{ sal.pay_grade||'—' }}</div>
              <div><span class="text-muted-foreground">Effective:</span> {{ sal.effective_from||'—' }} → {{ sal.effective_to||'present' }}</div>
              <div><span class="text-muted-foreground">Current:</span> {{ sal.is_current ? 'Yes' : 'No' }}</div>
              <div><span class="text-muted-foreground">Payment:</span> {{ sal.payment_method||'—' }}</div>
              <div><span class="text-muted-foreground">Bank:</span> {{ sal.bank_name||'—' }}</div>
              <div><span class="text-muted-foreground">A/C No:</span> {{ sal.bank_account_no||'—' }}</div>
              <div class="col-span-2 sm:col-span-3"><span class="text-muted-foreground">Remarks:</span> {{ sal.remarks||'—' }}</div>
            </div>
          </div>
          <p v-else class="text-sm text-muted-foreground py-2">No salary records.</p>
        </CollapsibleContent>
      </Collapsible>

      <!-- Education -->
      <Collapsible class="bg-card border rounded-xl" :defaultOpen="!!(employee.educations && employee.educations.length)">
        <CollapsibleTrigger class="w-full flex items-center justify-between p-4 hover:bg-muted/50">
          <div class="flex items-center gap-2"><GraduationCap class="size-4 text-violet-500"/><h3 class="font-semibold text-sm">Education</h3><span class="text-xs bg-violet-500/10 text-violet-600 px-2 py-0.5 rounded-full">{{ employee.educations?.length||0 }}</span></div><ChevronDown class="size-4"/>
        </CollapsibleTrigger>
        <CollapsibleContent class="p-4 pt-0">
          <div v-if="employee.educations && employee.educations.length" class="space-y-2">
            <div v-for="ed in employee.educations" :key="ed.id" class="border rounded-lg p-3 text-sm">
              <p class="font-medium">{{ ed.degree }} — {{ ed.institute }}</p>
              <p class="text-muted-foreground">{{ ed.board }} • {{ ed.passing_year }} • {{ ed.grade }} • GPA {{ ed.gpa||'—' }}</p>
              <p v-if="ed.description" class="text-xs mt-1">{{ ed.description }}</p>
            </div>
          </div>
          <p v-else class="text-sm text-muted-foreground py-2">No education records.</p>
        </CollapsibleContent>
      </Collapsible>

      <!-- Experience -->
      <Collapsible class="bg-card border rounded-xl" :defaultOpen="!!(employee.experiences && employee.experiences.length)">
        <CollapsibleTrigger class="w-full flex items-center justify-between p-4 hover:bg-muted/50">
          <div class="flex items-center gap-2"><Briefcase class="size-4 text-orange-500"/><h3 class="font-semibold text-sm">Experience</h3><span class="text-xs bg-orange-500/10 text-orange-600 px-2 py-0.5 rounded-full">{{ employee.experiences?.length||0 }}</span></div><ChevronDown class="size-4"/>
        </CollapsibleTrigger>
        <CollapsibleContent class="p-4 pt-0">
          <div v-if="employee.experiences && employee.experiences.length" class="space-y-2">
            <div v-for="ex in employee.experiences" :key="ex.id" class="border rounded-lg p-3 text-sm">
              <p class="font-medium">{{ ex.designation }} at {{ ex.company_name }} <span v-if="ex.is_current" class="text-xs bg-green-500 text-white px-2 py-0.5 rounded-full">Current</span></p>
              <p class="text-muted-foreground">{{ ex.department }} • {{ ex.from_date?.substring(0,10) }} → {{ ex.is_current ? 'Present' : ex.to_date?.substring(0,10) }}</p>
              <p v-if="ex.responsibilities" class="text-xs mt-1">{{ ex.responsibilities }}</p>
            </div>
          </div>
          <p v-else class="text-sm text-muted-foreground py-2">No experience records.</p>
        </CollapsibleContent>
      </Collapsible>

      <!-- Skills -->
      <Collapsible class="bg-card border rounded-xl" :defaultOpen="!!(employee.skills && employee.skills.length)">
        <CollapsibleTrigger class="w-full flex items-center justify-between p-4 hover:bg-muted/50">
          <div class="flex items-center gap-2"><Wrench class="size-4 text-pink-500"/><h3 class="font-semibold text-sm">Skills</h3><span class="text-xs bg-pink-500/10 text-pink-600 px-2 py-0.5 rounded-full">{{ employee.skills?.length||0 }}</span></div><ChevronDown class="size-4"/>
        </CollapsibleTrigger>
        <CollapsibleContent class="p-4 pt-0">
          <div v-if="employee.skills && employee.skills.length" class="flex flex-wrap gap-2">
            <span v-for="sk in employee.skills" :key="sk.id" class="bg-muted border rounded-full px-3 py-1 text-sm">{{ sk.skill_name }} <span class="text-xs text-muted-foreground">({{ sk.proficiency }} • {{ sk.years_of_experience??0 }}y)</span></span>
          </div>
          <p v-else class="text-sm text-muted-foreground py-2">No skills added.</p>
        </CollapsibleContent>
      </Collapsible>

      <!-- Supervisors (flexible User/Employee) -->
      <Collapsible class="bg-card border rounded-xl" :defaultOpen="!!(employee.supervisors && employee.supervisors.length)">
        <CollapsibleTrigger class="w-full flex items-center justify-between p-4 hover:bg-muted/50">
          <div class="flex items-center gap-2"><UsersRound class="size-4 text-indigo-600"/><h3 class="font-semibold text-sm">Supervisors</h3><span class="text-xs bg-indigo-500 text-white px-2 py-0.5 rounded-full">{{ employee.supervisors?.length||0 }}</span><span class="hidden sm:inline text-[11px] text-muted-foreground ml-1">User or Employee can supervise</span></div><ChevronDown class="size-4"/>
        </CollapsibleTrigger>
        <CollapsibleContent class="p-4 pt-0">
          <div v-if="employee.supervisors && employee.supervisors.length" class="space-y-2">
            <div v-for="s in employee.supervisors" :key="s.id" class="border rounded-lg p-3 text-sm flex items-center justify-between gap-3">
              <div class="flex items-center gap-2">
                <span class="inline-flex px-2 py-0.5 rounded-full text-[10px] font-bold" :style="s.supervisor_employee_id ? 'background:#e0f2fe;color:#0369a1' : 'background:#fef3c7;color:#92400e'">{{ s.supervisor_employee_id ? 'EMP' : 'USER' }}</span>
                <div>
                  <p class="font-medium">
                    <span v-if="s.supervisor">{{ s.supervisor.name }}<span class="text-xs text-muted-foreground"> ({{ s.supervisor.email }})</span></span>
                    <span v-else-if="s.supervisor_employee_id">{{ s.supervisor_employee_id }}</span>
                    <span v-else>{{ s.supervisor_display?.name||'—' }}</span>
                    <span v-if="s.is_primary" class="ml-2 text-[10px] font-bold bg-amber-400 text-white px-2 py-0.5 rounded-full">★ PRIMARY</span>
                  </p>
                  <p class="text-xs text-muted-foreground">{{ s.supervisor_type }} • {{ s.is_active ? 'Active':'Inactive' }}<span v-if="s.effective_from"> • {{ s.effective_from?.substring(0,10) }} → {{ s.effective_to?.substring(0,10) || 'ongoing' }}</span></p>
                  <p v-if="s.remarks" class="text-xs text-muted-foreground">“{{ s.remarks }}”</p>
                </div>
              </div>
              <a :href="route('hrm.supervisors.show',s.id)" class="text-xs text-primary hover:underline shrink-0">View →</a>
            </div>
            <a :href="route('hrm.supervisors.create',{employee_id:employee.employee_id})" class="inline-flex mt-2 text-xs text-primary hover:underline">+ Assign another supervisor</a>
          </div>
          <div v-else class="py-2">
            <p class="text-sm text-muted-foreground">No supervisor assigned.</p>
            <a :href="route('hrm.supervisors.create',{employee_id:employee.employee_id})" class="inline-flex mt-2 text-xs text-primary hover:underline">+ Assign supervisor (User or Employee)</a>
          </div>
          <!-- Subordinates when this employee supervises others -->
          <div v-if="subordinates && subordinates.length" class="mt-4 border-t pt-3">
            <p class="text-xs font-semibold text-muted-foreground mb-2">This employee supervises {{ subordinates.length }} subordinate(s):</p>
            <div class="flex flex-wrap gap-2">
              <a v-for="sub in subordinates" :key="sub.id" :href="route('hrm.employees.show',sub.employee_id)" class="text-xs border rounded-full px-3 py-1 hover:bg-accent">{{ sub.employee }} ({{ sub.employee_id }}) — {{ sub.supervisor_type }}</a>
            </div>
          </div>
        </CollapsibleContent>
      </Collapsible>

      <!-- Attendance quick link -->
      <div class="bg-card border rounded-xl p-4 flex items-center justify-between">
        <div class="flex items-center gap-2"><Fingerprint class="size-4 text-primary"/><h3 class="font-semibold text-sm">Attendance</h3></div>
        <a :href="route('hrm.attendance.index',{employee_id:employee.employee_id})" class="text-sm text-primary hover:underline flex items-center gap-1"><Calendar class="size-3"/> View Logs →</a>
      </div>
    </div>
  </AdminLayout>
</template>
