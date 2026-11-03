<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Landmark, CreditCard, FileBarChart, CheckCircle2, Clock3, AlertTriangle, Building2, ArrowRight, Download, ShieldCheck, Wallet } from 'lucide-vue-next';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import { Button } from '@/components/ui/button';
const p = defineProps<{ sheet:any; posts:any[]; accrualPost:any; bankAdvices:any[]; banks:any[]; accountsReady:Record<string,boolean> }>();
function money(v:any){ return Number(v||0).toLocaleString('en-BD',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function postAccrual(){ if(confirm(`Post accrual JV for ${p.sheet.pay_year}-${String(p.sheet.pay_month).padStart(2,'0')}? Dr Expense by dept → Cr Payables. This creates the official accounting entry.`)) router.post(route('payroll.finance.sheets.post-accrual', p.sheet.id), {}, {preserveScroll:true}); }
function voidPost(post:any){ const r = prompt('Void reason (audit trail):'); if(!r) return; router.post(route('payroll.finance.posts.void', post.id), {reason:r}, {preserveScroll:true}); }
function generateAdvice(bankId?:number){ if(confirm('Generate BEFTN bank advice Excel/CSV for this period?')) router.post(route('payroll.bank-advices.generate', p.sheet.id), {bank_id:bankId||null}, {preserveScroll:true}); }
function confirmAdvice(a:any){ router.post(route('payroll.bank-advices.confirm', a.id), {}, {preserveScroll:true}); }
function markPaid(a:any){ if(confirm('Mark this advice as PAID? This will also update slip payment statuses.')) router.post(route('payroll.bank-advices.paid', a.id), {}, {preserveScroll:true}); }
const isReleased = p.sheet.status==='released';
const isReady = p.sheet.status==='ready';
</script>
<template>
 <AdminLayout :breadcrumbs="[{title:'Payroll', href:route('payroll.sheets.index')},{title:`${sheet.pay_year}-${String(sheet.pay_month).padStart(2,'0')}` , href: route('payroll.sheets.show', sheet.id)},{title:'Finance Bridge'}]">
  <Head title="Payroll Finance Bridge"/>
  <!-- Header -->
  <div class="overflow-hidden rounded-2xl border bg-gradient-to-br from-slate-900 via-indigo-950 to-slate-900 p-6 text-white">
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="flex items-center gap-2 text-xs uppercase tracking-widest text-indigo-200"><Landmark class="size-4"/> Finance Bridge — Accounts Posting & Bank Advice</p>
        <h1 class="mt-1 text-2xl font-bold">Payroll {{sheet.pay_year}}-{{String(sheet.pay_month).padStart(2,'0')}} <span class="ml-2 inline-flex items-center rounded-full bg-white/15 px-2.5 py-0.5 text-xs font-medium">{{sheet.status}}</span></h1>
        <p class="mt-1 text-sm text-indigo-100/80">{{sheet.employee_count}} employees · Gross {{money(sheet.gross_total)}} · Deductions {{money(sheet.deduction_total)}} · <span class="font-semibold text-white">Net {{money(sheet.net_total)}} BDT</span></p>
      </div>
      <div class="flex gap-2">
        <Link :href="route('payroll.sheets.show', sheet.id)"><Button variant="secondary" size="sm"><ArrowRight class="size-4 rotate-180"/> Back to Sheet</Button></Link>
        <Link :href="route('payroll.bank-advices.index')"><Button variant="outline" class="bg-white/10 border-white/20 text-white hover:bg-white/20" size="sm">All Advices</Button></Link>
      </div>
    </div>
    <div class="mt-4 grid grid-cols-2 gap-3 md:grid-cols-4">
      <div class="rounded-xl bg-white/10 p-3 backdrop-blur"><p class="text-xs text-indigo-200">Gross Total</p><p class="text-lg font-bold">{{money(sheet.gross_total)}} <span class="text-xs font-normal">BDT</span></p></div>
      <div class="rounded-xl bg-white/10 p-3 backdrop-blur"><p class="text-xs text-indigo-200">Net Payable</p><p class="text-lg font-bold text-emerald-200">{{money(sheet.net_total)}} BDT</p></div>
      <div class="rounded-xl bg-white/10 p-3 backdrop-blur"><p class="text-xs text-indigo-200">Journal Status</p><p class="text-sm font-semibold flex items-center gap-1"><ShieldCheck class="size-4" :class="accrualPost?'text-emerald-300':'text-amber-300'"/>{{accrualPost? 'Accrual Posted' : isReleased? 'Ready to Post' : 'Awaiting Release'}}</p></div>
      <div class="rounded-xl bg-white/10 p-3 backdrop-blur"><p class="text-xs text-indigo-200">Bank Advices</p><p class="text-sm font-semibold">{{bankAdvices.length}} advice(s) · {{bankAdvices.reduce((s,a)=>s+(a.items_count||0),0)}} payments</p></div>
    </div>
  </div>

  <!-- Accounts Health -->
  <div class="mt-4 flex flex-wrap gap-2 text-xs">
    <span class="inline-flex items-center gap-1 rounded-full border px-2.5 py-1" :class="accountsReady['5210']?'bg-emerald-50 text-emerald-700 border-emerald-200':'bg-amber-50 text-amber-700 border-amber-200'"><CheckCircle2 class="size-3"/> 5210 Salary Expense {{accountsReady['5210']?'✓':'missing'}}</span>
    <span class="inline-flex items-center gap-1 rounded-full border px-2.5 py-1" :class="accountsReady['2121']?'bg-emerald-50 text-emerald-700 border-emerald-200':'bg-amber-50 text-amber-700 border-amber-200'"><CheckCircle2 class="size-3"/> 2121 Salary Payable {{accountsReady['2121']?'✓':'missing'}}</span>
    <span class="inline-flex items-center gap-1 rounded-full border px-2.5 py-1" :class="accountsReady['2311']?'bg-emerald-50 text-emerald-700 border-emerald-200':'bg-amber-50 text-amber-700 border-amber-200'"><CheckCircle2 class="size-3"/> 2311 Tax Payable {{accountsReady['2311']?'✓':'missing'}}</span>
    <span class="inline-flex items-center gap-1 rounded-full border px-2.5 py-1" :class="accountsReady['2122']?'bg-emerald-50 text-emerald-700 border-emerald-200':'bg-amber-50 text-amber-700 border-amber-200'"><CheckCircle2 class="size-3"/> 2122 PF Payable {{accountsReady['2122']?'✓':'missing'}}</span>
  </div>

  <!-- Accrual Section -->
  <section class="mt-6 rounded-2xl border bg-card p-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h2 class="flex items-center gap-2 text-base font-bold"><FileBarChart class="size-5 text-primary"/> Accrual Journal (JV)</h2>
      <span class="text-xs text-muted-foreground">Source: <code>payroll_sheet #{{sheet.id}}</code> · Type JV · Cost-center by Department</span>
    </div>
    <p class="mt-2 text-sm text-muted-foreground">On <strong>Release</strong> the sheet posts: <span class="font-mono text-xs">Dr Salary Expense (by dept)</span> + <span class="font-mono text-xs">Dr Employer PF</span> → <span class="font-mono text-xs">Cr Salary Payable / Tax / PF / Loan</span>. Balanced, idempotent, and auditable.</p>

    <div v-if="accrualPost" class="mt-4 rounded-xl border bg-emerald-50/60 p-4">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
          <p class="flex items-center gap-2 font-semibold text-emerald-800"><CheckCircle2 class="size-5"/> {{accrualPost.reference_no}} · Journal #{{accrualPost.journal_id}} <span class="ml-2 inline-flex rounded-full bg-emerald-600 px-2 py-0.5 text-xs text-white">{{accrualPost.status}}</span></p>
          <p class="text-xs text-muted-foreground">Dr {{money(accrualPost.total_debit)}} · Cr {{money(accrualPost.total_credit)}} · {{accrualPost.journal?.journal_no || 'JV-'+accrualPost.journal_id}} · {{new Date(accrualPost.created_at).toLocaleString()}}</p>
        </div>
        <div class="flex gap-2">
          <a v-if="accrualPost.journal_id" :href="`/accounts/journals/${accrualPost.journal_id}`" class="inline-flex items-center gap-1 rounded-md border bg-white px-3 py-1.5 text-sm">View in Accounts →</a>
          <Button v-if="accrualPost.status!=='void'" variant="outline" size="sm" @click="voidPost(accrualPost)">Void</Button>
        </div>
      </div>
      <!-- Cost center breakdown -->
      <div v-if="accrualPost.cost_center_breakdown" class="mt-3 grid gap-2 md:grid-cols-3">
        <div v-for="(v, dept) in accrualPost.cost_center_breakdown" :key="String(dept)" class="rounded-lg border bg-white p-3">
          <p class="flex items-center gap-1 text-xs font-semibold"><Building2 class="size-3"/> {{dept}}</p>
          <p class="text-xs text-muted-foreground">{{v.count}} emps · Gross {{money(v.gross)}} · Tax {{money(v.tax)}} · PF {{money(v.pf)}} · Net {{money(v.net)}}</p>
        </div>
      </div>
    </div>

    <div v-else class="mt-4 rounded-xl border border-dashed p-4">
      <p v-if="!isReleased && !isReady" class="flex items-center gap-2 text-sm text-amber-700"><Clock3 class="size-4"/> Sheet not released yet — accrual will be available after release.</p>
      <p v-else-if="isReady" class="flex items-center gap-2 text-sm text-amber-700"><AlertTriangle class="size-4"/> Sheet is <strong>ready</strong> but not yet released. Release it first, then post accrual.</p>
      <div v-else class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm">No accrual posted yet. Finance can review cost-centers, then post.</p>
        <Button @click="postAccrual" class="bg-indigo-600 hover:bg-indigo-700"><Landmark class="size-4"/> Post Accrual JV</Button>
      </div>
      <p class="mt-2 text-xs text-muted-foreground">Idempotent: clicking twice will not duplicate. Employer PF, OT, and deductions are auto-split from slip breakdowns.</p>
    </div>

    <!-- All posts table -->
    <div v-if="posts.length>1" class="mt-4 overflow-x-auto rounded-lg border">
      <table class="w-full text-sm">
        <thead class="bg-muted/50 text-xs"><tr><th class="px-3 py-2 text-left">Reference</th><th class="px-3 py-2">Type</th><th class="px-3 py-2 text-right">Debit</th><th class="px-3 py-2 text-right">Credit</th><th class="px-3 py-2">Status</th><th class="px-3 py-2 text-right">Journal</th></tr></thead>
        <tbody><tr v-for="post in posts" :key="post.id" class="border-t"><td class="px-3 py-2 font-mono text-xs">{{post.reference_no}}</td><td class="px-3 py-2 text-center"><span class="rounded-full bg-muted px-2 py-0.5 text-xs">{{post.posting_type}}</span></td><td class="px-3 py-2 text-right">{{money(post.total_debit)}}</td><td class="px-3 py-2 text-right">{{money(post.total_credit)}}</td><td class="px-3 py-2"><span class="rounded-full px-2 py-0.5 text-xs" :class="post.status==='posted'?'bg-emerald-100 text-emerald-700':'bg-slate-100'">{{post.status}}</span></td><td class="px-3 py-2 text-right">#{{post.journal_id}}</td></tr></tbody>
      </table>
    </div>
  </section>

  <!-- Bank Advice Section -->
  <section class="mt-6 rounded-2xl border bg-card p-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h2 class="flex items-center gap-2 text-base font-bold"><CreditCard class="size-5 text-primary"/> Bank Advice — BEFTN / Salary Disbursement</h2>
      <div class="flex gap-2">
        <select v-if="banks.length>1" id="bankSel" class="rounded-md border px-3 py-1.5 text-sm">
          <option :value="''">Default company bank</option>
          <option v-for="b in banks" :key="b.id" :value="String(b.id)">{{b.bank_name}} — {{b.branch_name}} ({{b.account_no}})</option>
        </select>
        <Button variant="outline" size="sm" @click="generateAdvice()"><Wallet class="size-4"/> Generate Advice</Button>
      </div>
    </div>
    <p class="mt-2 text-sm text-muted-foreground">Groups net pay by employee bank, exports <strong>Excel (BEFTN)</strong> with Account No, Amount, Routing, Reference. Idempotent per sheet+bank. Supports multiple banks.</p>

    <div v-if="bankAdvices.length===0" class="mt-4 rounded-xl border border-dashed p-6 text-center">
      <p class="text-sm text-muted-foreground">No bank advice yet. Generate one to download BEFTN file for bank upload.</p>
      <Button class="mt-3" @click="generateAdvice()">Generate BEFTN Advice</Button>
      <p class="mt-2 text-xs text-muted-foreground">File includes SL, Employee, A/C No, Amount, Routing — ready for Prime Bank / DBBL upload.</p>
    </div>
    <div v-else class="mt-4 grid gap-3">
      <div v-for="adv in bankAdvices" :key="adv.id" class="rounded-xl border p-4" :class="adv.status==='paid'?'bg-emerald-50/50 border-emerald-200': adv.status==='confirmed'?'bg-amber-50/50 border-amber-200':'bg-white'">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div>
            <p class="font-semibold">{{adv.advice_no}} <span class="ml-2 inline-flex rounded-full px-2 py-0.5 text-xs capitalize" :class="adv.status==='paid'?'bg-emerald-600 text-white': adv.status==='confirmed'?'bg-amber-500 text-white':'bg-slate-200'">{{adv.status}}</span></p>
            <p class="text-xs text-muted-foreground">{{adv.bank_name}} · {{adv.branch_name}} · {{adv.account_no}} · {{adv.employee_count}} employees · {{money(adv.total_amount)}} BDT</p>
          </div>
          <div class="flex flex-wrap gap-2">
            <Link :href="route('payroll.bank-advices.show', adv.id)"><Button variant="outline" size="sm">View</Button></Link>
            <a :href="route('payroll.bank-advices.download', adv.id)" class="inline-flex items-center gap-1 rounded-md bg-primary px-3 py-1.5 text-sm text-primary-foreground"><Download class="size-4"/> Excel/CSV</a>
            <Button v-if="adv.status==='draft'" size="sm" variant="outline" @click="confirmAdvice(adv)">Confirm</Button>
            <Button v-if="adv.status==='confirmed'" size="sm" @click="markPaid(adv)">Mark Paid</Button>
          </div>
        </div>
        <p v-if="adv.file_path" class="mt-1 text-xs text-muted-foreground">File: {{adv.file_path}} · {{adv.file_format.toUpperCase()}}</p>
      </div>
    </div>
  </section>

  <p class="mt-4 text-center text-xs text-muted-foreground">Finance Bridge v1 · Accrual JV + Payment PV + BEFTN · All journals reversible; cost-centers drive P&L by department.</p>
</AdminLayout>
</template>
