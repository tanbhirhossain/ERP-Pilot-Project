<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Plus, Trash2, Building2, Phone, MapPin, Landmark } from 'lucide-vue-next';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import SearchableSelect from '../../../../../CORE/Resources/js/Components/SearchableSelect.vue';

const props = defineProps<{ groupOptions:any[]; priceListOptions:any[]; userOptions:any[]; defaultType:string }>();

const form = useForm({
  type: props.defaultType || 'vendor',
  name: '',
  legal_name: '',
  partner_code: '',
  group_id: null as number|null,
  price_list_id: null as number|null,
  trade_license: '',
  tin: '',
  bin: '',
  vat_reg_no: '',
  nid: '',
  phone: '',
  email: '',
  website: '',
  credit_limit: null as number|null,
  payment_terms_days: 30 as number|null,
  payment_terms: 'Net 30',
  opening_balance: 0 as number|null,
  currency: 'BDT',
  assigned_to: null as number|null,
  notes: '',
  internal_notes: '',
  contacts: [{ name:'', designation:'', phone:'', email:'', is_primary:true, can_receive_statement:false }],
  addresses: [{ type:'billing', label:'Head Office', address_line:'', area:'', city:'', district:'', division:'', country:'Bangladesh', postal_code:'', is_default:true }],
  banks: [{ bank_name:'', branch:'', account_name:'', account_no:'', routing_no:'', swift_code:'', is_primary:true }],
});

const activeTab = ref('basic');
const tabs = [
  { id:'basic', label:'Basic Info' },
  { id:'contacts', label:'Contacts' },
  { id:'addresses', label:'Addresses' },
  { id:'banks', label:'Banks' },
  { id:'commercial', label:'Commercial' },
];

function addContact(){ form.contacts.push({ name:'', designation:'', phone:'', email:'', is_primary:false, can_receive_statement:false }); }
function removeContact(i:number){ form.contacts.splice(i,1); if(!form.contacts.length) addContact(); }
function addAddress(){ form.addresses.push({ type:'shipping', label:'', address_line:'', area:'', city:'', district:'', division:'', country:'Bangladesh', postal_code:'', is_default:false }); }
function removeAddress(i:number){ form.addresses.splice(i,1); if(!form.addresses.length) addAddress(); }
function addBank(){ form.banks.push({ bank_name:'', branch:'', account_name:'', account_no:'', routing_no:'', swift_code:'', is_primary:false }); }
function removeBank(i:number){ form.banks.splice(i,1); if(!form.banks.length) addBank(); }

function submit(){
  form.post(route('partner.vendors.store'), {
    onError:(e)=>{ const k=Object.keys(e)[0]; if(k?.startsWith('contacts')) activeTab.value='contacts'; else if(k?.startsWith('addresses')) activeTab.value='addresses'; else if(k?.startsWith('banks')) activeTab.value='banks'; else activeTab.value='basic'; }
  });
}
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Vendors',href:route('partner.vendors.index')},{title:'Create'}]">
    <Head :title="typeLabel + ' — Create'" />
    <div class="max-w-5xl mx-auto space-y-4">
      <div class="flex items-center justify-between">
        <div>
          <h1 class="text-xl font-bold flex items-center gap-2"><Building2 class="size-5"/> Add {{ form.type === 'customer' ? 'Customer' : form.type==='both' ? 'Partner' : 'Vendor' }}</h1>
          <p class="text-sm text-muted-foreground">Type can be vendor, customer, or both. Code auto-generates if blank.</p>
        </div>
        <div class="text-xs text-muted-foreground hidden sm:block">Tabs guide you through sections</div>
      </div>

      <form @submit.prevent="submit" class="space-y-4">
        <nav class="flex gap-2 overflow-x-auto rounded-xl border bg-card p-2">
          <button v-for="t in tabs" :key="t.id" type="button" @click="activeTab=t.id" class="shrink-0 rounded-lg px-3 py-2 text-sm font-medium transition" :class="activeTab===t.id?'bg-primary text-primary-foreground shadow-sm':'text-muted-foreground hover:bg-muted hover:text-foreground'">{{ t.label }}</button>
        </nav>
        <p v-if="Object.keys(form.errors).length" class="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">{{ Object.values(form.errors)[0] }}</p>

        <!-- Basic -->
        <section v-if="activeTab==='basic'" class="bg-card border rounded-xl p-4 space-y-4">
          <h2 class="text-base font-semibold flex items-center gap-2"><Building2 class="size-4"/> Basic Information</h2>
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="space-y-2"><Label>Type *</Label><select v-model="form.type" class="w-full h-9 rounded-md border bg-background px-3 text-sm"><option value="vendor">Vendor</option><option value="customer">Customer</option><option value="both">Both</option></select><p v-if="form.errors.type" class="text-xs text-destructive">{{ form.errors.type }}</p></div>
            <div class="space-y-2"><Label>Name *</Label><Input v-model="form.name" placeholder="Bangladesh Fabric Mills Ltd."/><p v-if="form.errors.name" class="text-xs text-destructive">{{ form.errors.name }}</p></div>
            <div class="space-y-2"><Label>Code (auto)</Label><Input v-model="form.partner_code" placeholder="VEN-0001"/><p class="text-xs text-muted-foreground">Leave blank to auto-generate</p></div>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="space-y-2"><Label>Legal Name</Label><Input v-model="form.legal_name" placeholder="As per trade license"/></div>
            <div class="space-y-2"><Label>Group</Label><SearchableSelect v-model="form.group_id" :options="groupOptions" placeholder="Select group" clearable /></div>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="space-y-2"><Label>Phone</Label><Input v-model="form.phone" placeholder="017..."/></div>
            <div class="space-y-2"><Label>Email</Label><Input v-model="form.email" type="email" placeholder="info@company.com"/></div>
            <div class="space-y-2"><Label>Website</Label><Input v-model="form.website" placeholder="https://..."/></div>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
            <div class="space-y-2"><Label>Trade License</Label><Input v-model="form.trade_license"/></div>
            <div class="space-y-2"><Label>TIN</Label><Input v-model="form.tin"/></div>
            <div class="space-y-2"><Label>BIN</Label><Input v-model="form.bin"/></div>
            <div class="space-y-2"><Label>VAT Reg</Label><Input v-model="form.vat_reg_no"/></div>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="space-y-2"><Label>NID</Label><Input v-model="form.nid"/></div>
            <div class="space-y-2"><Label>Assigned To</Label><SearchableSelect v-model="form.assigned_to" :options="userOptions" placeholder="Select owner" clearable /></div>
          </div>
        </section>

        <!-- Contacts -->
        <section v-if="activeTab==='contacts'" class="bg-card border rounded-xl p-4 space-y-4">
          <h2 class="text-base font-semibold flex items-center gap-2"><Phone class="size-4"/> Contacts</h2>
          <div v-for="(c, i) in form.contacts" :key="i" class="border rounded-lg p-3 space-y-3 relative">
            <Button type="button" variant="ghost" size="icon" class="absolute top-2 right-2 size-6" @click="removeContact(i)"><Trash2 class="size-3"/></Button>
            <p class="text-xs font-medium text-muted-foreground">#{{ i+1 }} <span v-if="c.is_primary" class="ml-2 px-1.5 py-0.5 bg-emerald-500/10 text-emerald-600 rounded text-xs">Primary</span></p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div class="space-y-1"><Label>Name *</Label><Input v-model="c.name" placeholder="Mr. Rahman"/></div>
              <div class="space-y-1"><Label>Designation</Label><Input v-model="c.designation" placeholder="Sales Manager"/></div>
              <div class="space-y-1"><Label>Phone</Label><Input v-model="c.phone"/></div>
              <div class="space-y-1"><Label>Email</Label><Input v-model="c.email" type="email"/></div>
            </div>
            <div class="flex gap-4">
              <label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="c.is_primary" class="rounded"/> Primary</label>
              <label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="c.can_receive_statement" class="rounded"/> Can receive statement</label>
            </div>
          </div>
          <Button type="button" variant="outline" size="sm" @click="addContact"><Plus class="size-4"/> Add Contact</Button>
        </section>

        <!-- Addresses -->
        <section v-if="activeTab==='addresses'" class="bg-card border rounded-xl p-4 space-y-4">
          <h2 class="text-base font-semibold flex items-center gap-2"><MapPin class="size-4"/> Addresses</h2>
          <div v-for="(a, i) in form.addresses" :key="i" class="border rounded-lg p-3 space-y-3 relative">
            <Button type="button" variant="ghost" size="icon" class="absolute top-2 right-2 size-6" @click="removeAddress(i)"><Trash2 class="size-3"/></Button>
            <p class="text-xs font-medium text-muted-foreground">#{{ i+1 }} — {{ a.type }}</p>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
              <div class="space-y-1"><Label>Type</Label><select v-model="a.type" class="w-full h-9 rounded-md border bg-background px-3 text-sm"><option value="billing">Billing</option><option value="shipping">Shipping</option><option value="factory">Factory</option><option value="warehouse">Warehouse</option><option value="branch">Branch</option><option value="other">Other</option></select></div>
              <div class="space-y-1"><Label>Label</Label><Input v-model="a.label" placeholder="Head Office"/></div>
              <div class="space-y-1"><Label>Postal Code</Label><Input v-model="a.postal_code"/></div>
            </div>
            <div class="space-y-1"><Label>Address Line *</Label><Input v-model="a.address_line" placeholder="House 12, Road 5, Dhanmondi"/></div>
            <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
              <div class="space-y-1"><Label>Area</Label><Input v-model="a.area" placeholder="Dhanmondi"/></div>
              <div class="space-y-1"><Label>City</Label><Input v-model="a.city" placeholder="Dhaka"/></div>
              <div class="space-y-1"><Label>District</Label><Input v-model="a.district" placeholder="Dhaka"/></div>
              <div class="space-y-1"><Label>Division</Label><Input v-model="a.division" placeholder="Dhaka"/></div>
            </div>
            <div class="flex gap-4">
              <label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="a.is_default" class="rounded"/> Default</label>
            </div>
          </div>
          <Button type="button" variant="outline" size="sm" @click="addAddress"><Plus class="size-4"/> Add Address</Button>
        </section>

        <!-- Banks -->
        <section v-if="activeTab==='banks'" class="bg-card border rounded-xl p-4 space-y-4">
          <h2 class="text-base font-semibold flex items-center gap-2"><Landmark class="size-4"/> Banks</h2>
          <div v-for="(b, i) in form.banks" :key="i" class="border rounded-lg p-3 space-y-3 relative">
            <Button type="button" variant="ghost" size="icon" class="absolute top-2 right-2 size-6" @click="removeBank(i)"><Trash2 class="size-3"/></Button>
            <p class="text-xs font-medium text-muted-foreground">#{{ i+1 }}</p>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
              <div class="space-y-1"><Label>Bank Name *</Label><Input v-model="b.bank_name" placeholder="DBBL"/></div>
              <div class="space-y-1"><Label>Branch</Label><Input v-model="b.branch" placeholder="Gulshan Branch"/></div>
              <div class="space-y-1"><Label>Account Name</Label><Input v-model="b.account_name"/></div>
              <div class="space-y-1"><Label>Account No *</Label><Input v-model="b.account_no" placeholder="1234567890"/></div>
              <div class="space-y-1"><Label>Routing No</Label><Input v-model="b.routing_no"/></div>
              <div class="space-y-1"><Label>Swift</Label><Input v-model="b.swift_code"/></div>
            </div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="b.is_primary" class="rounded"/> Primary</label>
          </div>
          <Button type="button" variant="outline" size="sm" @click="addBank"><Plus class="size-4"/> Add Bank</Button>
        </section>

        <!-- Commercial -->
        <section v-if="activeTab==='commercial'" class="bg-card border rounded-xl p-4 space-y-4">
          <h2 class="text-base font-semibold">Commercial</h2>
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="space-y-2"><Label>Credit Limit (BDT)</Label><Input v-model.number="form.credit_limit" type="number" step="0.01" placeholder="0 = unlimited"/></div>
            <div class="space-y-2"><Label>Payment Terms Days</Label><Input v-model.number="form.payment_terms_days" type="number" min="0" max="365"/></div>
            <div class="space-y-2"><Label>Payment Terms Label</Label><Input v-model="form.payment_terms" placeholder="Net 30"/></div>
            <div class="space-y-2"><Label>Opening Balance</Label><Input v-model.number="form.opening_balance" type="number" step="0.01"/></div>
            <div class="space-y-2"><Label>Currency</Label><select v-model="form.currency" class="w-full h-9 rounded-md border bg-background px-3 text-sm"><option>BDT</option><option>USD</option><option>EUR</option></select></div>
            <div class="space-y-2"><Label>Price List</Label><SearchableSelect v-model="form.price_list_id" :options="priceListOptions" placeholder="Default price" clearable /></div>
          </div>
          <div class="space-y-2"><Label>Notes</Label><Textarea v-model="form.notes" rows="3" placeholder="Public notes"/></div>
          <div class="space-y-2"><Label>Internal Notes</Label><Textarea v-model="form.internal_notes" rows="3" placeholder="Private — not on statements"/></div>
          <label class="flex items-center gap-2 text-sm"><input type="checkbox" :checked="true" disabled class="rounded"/> Active (new partners are active by default)</label>
        </section>

        <Button type="submit" :disabled="form.processing" class="w-full sm:w-auto">Create {{ form.type==='customer'?'Customer':'Vendor' }}</Button>
      </form>
    </div>
  </AdminLayout>
</template>
