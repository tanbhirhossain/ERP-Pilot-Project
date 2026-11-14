<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import type { Partner } from '@partner/types';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Building2, Phone, MapPin, Landmark, FileText, Clock, ShieldCheck, Ban, CheckCircle, AlertTriangle, CreditCard, Send } from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import StatusBadge from '@core/Components/StatusBadge.vue';
import { formatCurrency, formatDate } from '@core/Utils/format';

const props = defineProps<{ partner:Partner; creditCheck?:any }>();

const noteForm = useForm({ title:'', content:'', is_private:false, is_pinned:false });
function submitNote(){ noteForm.post(route('partner.partners.notes.store', props.partner.id), { preserveScroll:true, onSuccess:()=>noteForm.reset() }); }

const docForm = useForm({ type:'other', title:'', file:null as File|null, expiry_date:'', notes:'' });
function submitDoc(){ docForm.post(route('partner.partners.documents.store', props.partner.id), { preserveScroll:true, forceFormData:true, onSuccess:()=>docForm.reset() }); }

function toggleActive(){ router.post(route('partner.partners.toggle-active', props.partner.id), {}, { preserveScroll:true }); }
function verify(action:'approve'|'reject'){ const remarks = prompt(action==='reject' ? 'Rejection reason:' : 'Approval remarks (optional):'); if(action==='reject' && !remarks) return; router.post(route('partner.partners.verify', props.partner.id), { action, remarks }, { preserveScroll:true }); }

const activeTab = ref('overview');
const tabs = ['overview','contacts','addresses','banks','documents','notes','activity'];

function toneForStatus(s:string){ if(s==='approved') return 'success'; if(s==='rejected') return 'danger'; return 'warning'; }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Ven & Cust' }, { title: partner.type==='customer'?'Customers':'Vendors', href: partner.type==='customer'?route('partner.customers.index'):route('partner.vendors.index') }, { title: 'Vendors', href: route('partner.vendors.index') }, { title: partner.partner_code }]">
    <Head :title="`${partner.partner_code} — ${partner.name}`" />
    <div class="space-y-4">
      <!-- Header -->
      <div class="bg-card border rounded-xl p-4">
        <div class="flex flex-col lg:flex-row lg:items-start justify-between gap-4">
          <div class="flex gap-4">
            <div class="size-14 rounded-xl bg-gradient-to-br from-indigo-500 to-violet-500 grid place-items-center text-white font-bold text-lg">{{ partner.name.substring(0,2).toUpperCase() }}</div>
            <div>
              <h1 class="text-xl font-bold flex items-center gap-2">{{ partner.name }} <span class="font-mono text-sm px-2 py-0.5 rounded bg-primary/10 text-primary">{{ partner.partner_code }}</span> <StatusBadge :label="partner.verification_status" :tone="toneForStatus(partner.verification_status)" dot /></h1>
              <p class="text-sm text-muted-foreground">{{ partner.legal_name || 'No legal name' }} · {{ partner.type }} · {{ partner.group?.name || 'No group' }} · {{ partner.phone || 'No phone' }}</p>
              <div class="flex gap-2 mt-2">
                <StatusBadge :label="partner.is_active?'Active':'Inactive'" :tone="partner.is_active?'success':'neutral'" />
                <StatusBadge :label="partner.is_verified?'Verified':'Unverified'" :tone="partner.is_verified?'success':'warning'" />
                <span class="text-xs text-muted-foreground ml-2">Created {{ formatDate(partner.created_at) }}</span>
              </div>
            </div>
          </div>
          <div class="flex flex-wrap gap-2">
            <Button variant="outline" size="sm" @click="toggleActive"><Ban class="size-4"/>{{ partner.is_active?'Deactivate':'Activate' }}</Button>
            <Button v-if="partner.verification_status==='pending'" size="sm" class="bg-emerald-600 hover:bg-emerald-700" @click="verify('approve')"><CheckCircle class="size-4"/> Approve</Button>
            <Button v-if="partner.verification_status==='pending'" variant="destructive" size="sm" @click="verify('reject')"><AlertTriangle class="size-4"/> Reject</Button>
            <Link :href="route(partner.type==='customer'?'partner.customers.edit':'partner.vendors.edit', partner.id)"><Button variant="outline" size="sm">Edit</Button></Link>
          </div>
        </div>
        <!-- KPI Cards -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mt-4">
          <div class="bg-muted/40 rounded-lg p-3 border"><p class="text-xs text-muted-foreground">Credit Limit</p><p class="text-lg font-bold font-mono">{{ formatCurrency(Number(partner.credit_limit)) }}</p></div>
          <div class="bg-muted/40 rounded-lg p-3 border"><p class="text-xs text-muted-foreground">Current Balance</p><p class="text-lg font-bold font-mono" :class="Number(partner.current_balance) > Number(partner.credit_limit) && Number(partner.credit_limit)>0 ? 'text-rose-600':''">{{ formatCurrency(Number(partner.current_balance)) }}</p></div>
          <div class="bg-muted/40 rounded-lg p-3 border"><p class="text-xs text-muted-foreground">Available Credit</p><p class="text-lg font-bold font-mono text-emerald-600">{{ formatCurrency(Number(partner.credit_limit)-Number(partner.current_balance)) }}</p></div>
          <div class="bg-muted/40 rounded-lg p-3 border"><p class="text-xs text-muted-foreground">Opening Balance</p><p class="text-lg font-bold font-mono">{{ formatCurrency(Number(partner.opening_balance)) }}</p></div>
        </div>
        <div v-if="creditCheck" class="mt-3 rounded-lg border p-3 flex items-center gap-3" :class="creditCheck.level==='exceeded'?'bg-rose-500/5 border-rose-500/20':creditCheck.level==='warning'?'bg-amber-500/5 border-amber-500/20':'bg-emerald-500/5 border-emerald-500/20'">
          <CreditCard class="size-5" :class="creditCheck.level==='exceeded'?'text-rose-600':creditCheck.level==='warning'?'text-amber-600':'text-emerald-600'" />
          <div><p class="text-sm font-medium">{{ creditCheck.message }}</p><p class="text-xs text-muted-foreground">Utilization: {{ creditCheck.utilization_pct }}% · Available: {{ formatCurrency(creditCheck.available_credit) }}</p></div>
        </div>
      </div>

      <!-- Tabs -->
      <nav class="flex gap-2 overflow-x-auto rounded-xl border bg-card p-2">
        <button v-for="t in tabs" :key="t" type="button" @click="activeTab=t" class="shrink-0 rounded-lg px-3 py-2 text-sm font-medium capitalize transition" :class="activeTab===t?'bg-primary text-primary-foreground shadow-sm':'text-muted-foreground hover:bg-muted hover:text-foreground'">{{ t }}</button>
      </nav>

      <!-- Overview -->
      <section v-if="activeTab==='overview'" class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="bg-card border rounded-xl p-4 space-y-3">
          <h3 class="font-semibold flex items-center gap-2"><Building2 class="size-4"/> Basic Details</h3>
          <div class="space-y-2 text-sm">
            <div class="flex justify-between"><span class="text-muted-foreground">Type</span><span class="font-medium capitalize">{{ partner.type }}</span></div>
            <div class="flex justify-between"><span class="text-muted-foreground">Phone</span><span class="font-mono">{{ partner.phone || '—' }}</span></div>
            <div class="flex justify-between"><span class="text-muted-foreground">Email</span><span>{{ partner.email || '—' }}</span></div>
            <div class="flex justify-between"><span class="text-muted-foreground">Website</span><span>{{ partner.website || '—' }}</span></div>
            <div class="flex justify-between"><span class="text-muted-foreground">Trade License</span><span>{{ partner.trade_license || '—' }}</span></div>
            <div class="flex justify-between"><span class="text-muted-foreground">TIN</span><span>{{ partner.tin || '—' }}</span></div>
            <div class="flex justify-between"><span class="text-muted-foreground">BIN</span><span>{{ partner.bin || '—' }}</span></div>
            <div class="flex justify-between"><span class="text-muted-foreground">VAT</span><span>{{ partner.vat_reg_no || '—' }}</span></div>
            <div class="flex justify-between"><span class="text-muted-foreground">NID</span><span>{{ partner.nid || '—' }}</span></div>
            <div class="flex justify-between"><span class="text-muted-foreground">Payment Terms</span><span>{{ partner.payment_terms || '—' }} ({{ partner.payment_terms_days }} days)</span></div>
            <div class="flex justify-between"><span class="text-muted-foreground">Price List</span><span>{{ partner.price_list?.name || '—' }}</span></div>
          </div>
          <div v-if="partner.notes" class="pt-3 border-t"><p class="text-xs text-muted-foreground">Notes</p><p class="text-sm">{{ partner.notes }}</p></div>
        </div>
        <div class="bg-card border rounded-xl p-4 space-y-3">
          <h3 class="font-semibold flex items-center gap-2"><ShieldCheck class="size-4"/> Verification & Ownership</h3>
          <div class="space-y-2 text-sm">
            <div class="flex justify-between"><span class="text-muted-foreground">Status</span><StatusBadge :label="partner.verification_status" :tone="toneForStatus(partner.verification_status)" /></div>
            <div class="flex justify-between"><span class="text-muted-foreground">Verified At</span><span>{{ partner.verified_at ? formatDate(partner.verified_at) : '—' }}</span></div>
            <div class="flex justify-between"><span class="text-muted-foreground">Verified By</span><span>{{ partner.verifier?.name || '—' }}</span></div>
            <div class="flex justify-between"><span class="text-muted-foreground">Assigned To</span><span>{{ partner.assigned_to?.name || '—' }}</span></div>
            <div class="flex justify-between"><span class="text-muted-foreground">Created By</span><span>{{ partner.creator?.name || '—' }}</span></div>
          </div>
        </div>
      </section>

      <!-- Contacts -->
      <section v-if="activeTab==='contacts'" class="bg-card border rounded-xl p-4">
        <h3 class="font-semibold flex items-center gap-2 mb-3"><Phone class="size-4"/> Contacts — {{ partner.contacts.length }}</h3>
        <div v-if="!partner.contacts.length" class="text-sm text-muted-foreground py-8 text-center">No contacts added.</div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <div v-for="c in partner.contacts" :key="c.id" class="border rounded-lg p-3">
            <p class="font-semibold flex items-center gap-2">{{ c.name }} <span v-if="c.is_primary" class="text-xs px-1.5 py-0.5 bg-emerald-500/10 text-emerald-600 rounded">Primary</span></p>
            <p class="text-sm text-muted-foreground">{{ c.designation || 'No designation' }}</p>
            <p class="text-sm font-mono">{{ c.phone || '—' }} · {{ c.email || '—' }}</p>
          </div>
        </div>
      </section>

      <!-- Addresses -->
      <section v-if="activeTab==='addresses'" class="bg-card border rounded-xl p-4">
        <h3 class="font-semibold flex items-center gap-2 mb-3"><MapPin class="size-4"/> Addresses — {{ partner.addresses.length }}</h3>
        <div v-if="!partner.addresses.length" class="text-sm text-muted-foreground py-8 text-center">No addresses.</div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <div v-for="a in partner.addresses" :key="a.id" class="border rounded-lg p-3">
            <p class="font-semibold capitalize flex items-center gap-2">{{ a.type }} <span v-if="a.is_default" class="text-xs px-1.5 py-0.5 bg-indigo-500/10 text-indigo-600 rounded">Default</span> <span class="text-xs text-muted-foreground">{{ a.label }}</span></p>
            <p class="text-sm">{{ a.address_line }}</p>
            <p class="text-xs text-muted-foreground">{{ [a.area, a.city, a.district, a.division, a.country].filter(Boolean).join(', ') }} — {{ a.postal_code }}</p>
          </div>
        </div>
      </section>

      <!-- Banks -->
      <section v-if="activeTab==='banks'" class="bg-card border rounded-xl p-4">
        <h3 class="font-semibold flex items-center gap-2 mb-3"><Landmark class="size-4"/> Banks — {{ partner.banks.length }}</h3>
        <div v-if="!partner.banks.length" class="text-sm text-muted-foreground py-8 text-center">No bank accounts.</div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <div v-for="b in partner.banks" :key="b.id" class="border rounded-lg p-3">
            <p class="font-semibold">{{ b.bank_name }} — {{ b.branch }} <span v-if="b.is_primary" class="text-xs px-1.5 py-0.5 bg-emerald-500/10 text-emerald-600 rounded">Primary</span></p>
            <p class="text-sm font-mono">{{ b.account_name }} — {{ b.account_no }}</p>
            <p class="text-xs text-muted-foreground">Routing: {{ b.routing_no || '—' }} · Swift: {{ b.swift_code || '—' }}</p>
          </div>
        </div>
      </section>

      <!-- Documents -->
      <section v-if="activeTab==='documents'" class="bg-card border rounded-xl p-4 space-y-4">
        <h3 class="font-semibold flex items-center gap-2"><FileText class="size-4"/> Documents — {{ partner.documents.length }}</h3>
        <div v-if="!partner.documents.length" class="text-sm text-muted-foreground py-4 text-center">No documents uploaded.</div>
        <div v-for="d in partner.documents" :key="d.id" class="border rounded-lg p-3 flex justify-between items-center">
          <div><p class="font-medium">{{ d.title }} <span class="text-xs px-1.5 py-0.5 bg-muted rounded capitalize">{{ d.type }}</span></p><p class="text-xs text-muted-foreground">{{ d.file_name }} · Expiry: {{ d.expiry_date ? formatDate(d.expiry_date) : 'N/A' }}</p></div>
          <a :href="`/storage/${d.file_path}`" target="_blank" class="text-sm text-primary hover:underline">View</a>
        </div>
        <form @submit.prevent="submitDoc" class="border-t pt-4 space-y-3">
          <p class="text-sm font-medium">Upload Document</p>
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div class="space-y-1"><Label>Type</Label><select v-model="docForm.type" class="w-full h-9 rounded-md border bg-background px-3 text-sm"><option value="nid">NID</option><option value="trade_license">Trade License</option><option value="tin">TIN</option><option value="vat">VAT</option><option value="bin">BIN</option><option value="agreement">Agreement</option><option value="cheque">Cheque</option><option value="other">Other</option></select></div>
            <div class="space-y-1"><Label>Title</Label><Input v-model="docForm.title" placeholder="Trade License 2026"/></div>
            <div class="space-y-1"><Label>Expiry</Label><Input v-model="docForm.expiry_date" type="date"/></div>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div class="space-y-1"><Label>File (PDF/JPG/PNG, max 10MB)</Label><Input type="file" @change="e=>docForm.file=(e.target as HTMLInputElement).files?.[0]||null"/></div>
            <div class="space-y-1"><Label>Notes</Label><Input v-model="docForm.notes"/></div>
          </div>
          <Button type="submit" size="sm" :disabled="docForm.processing">Upload</Button>
        </form>
      </section>

      <!-- Notes -->
      <section v-if="activeTab==='notes'" class="bg-card border rounded-xl p-4 space-y-4">
        <h3 class="font-semibold">Internal Notes</h3>
        <div v-if="!partner.activities.length" class="text-sm text-muted-foreground py-4 text-center">No notes.</div>
        <form @submit.prevent="submitNote" class="border rounded-lg p-3 space-y-3">
          <div class="space-y-1"><Label>Title (optional)</Label><Input v-model="noteForm.title" placeholder="Follow-up call"/></div>
          <div class="space-y-1"><Label>Content *</Label><Textarea v-model="noteForm.content" rows="3" placeholder="Add a note..."/></div>
          <div class="flex gap-4"><label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="noteForm.is_private" class="rounded"/> Private</label><label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="noteForm.is_pinned" class="rounded"/> Pinned</label></div>
          <Button type="submit" size="sm" :disabled="noteForm.processing"><Send class="size-4"/> Add Note</Button>
        </form>
      </section>

      <!-- Activity -->
      <section v-if="activeTab==='activity'" class="bg-card border rounded-xl p-4">
        <h3 class="font-semibold flex items-center gap-2 mb-3"><Clock class="size-4"/> Activity Timeline — {{ partner.activities.length }}</h3>
        <div v-if="!partner.activities.length" class="text-sm text-muted-foreground py-8 text-center">No activity yet.</div>
        <div class="space-y-2">
          <div v-for="a in partner.activities" :key="a.id" class="flex gap-3 border-l-2 border-muted pl-3 py-2">
            <div><p class="text-sm font-medium capitalize">{{ a.action }}</p><p class="text-xs text-muted-foreground">{{ a.description }}</p><p class="text-xs text-muted-foreground">{{ a.user?.name || 'System' }} · {{ formatDate(a.created_at) }}</p></div>
          </div>
        </div>
      </section>
    </div>
  </AdminLayout>
</template>
