<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ListTree, Wallet, History, Pencil, Layers } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import StatusBadge from '../../../../../CORE/Resources/js/Components/StatusBadge.vue';
import AcctSection from '../../Components/AcctSection.vue';
import AcctEmpty from '../../Components/AcctEmpty.vue';
import { formatCurrency } from '../../../../../CORE/Resources/js/Utils/format';
defineProps<{ account:any; balance:number; ancestors:any[] }>();
function tone(t:string){ if(t==='asset') return 'info'; if(t==='liability') return 'warning'; if(t==='equity') return 'primary'; if(t==='income') return 'success'; return 'danger'; }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Chart of Accounts',href:route('accounts.coa.index')},{title:account.code}]">
    <Head :title="`${account.code} — ${account.name}`" />
    <PageHeader :title="`${account.code} — ${account.name}`" :description="`${account.account_type} · ${account.nature} · Level ${account.level} · ${account.is_group ? 'Group (no postings)' : 'Posting account'} · ${account.is_system ? 'System' : 'User'}`" :icon="ListTree"
      :actions="[{ label:'Edit', route:'accounts.coa.edit', routeParams:{ account: account.id }, icon: Pencil }]">
      <div class="max-w-5xl mx-auto w-full space-y-4">
        <div class="rounded-2xl border p-4 shadow-sm flex flex-wrap items-start justify-between gap-4" :class="account.is_group?'bg-violet-500/[0.06] border-violet-500/20':'bg-card'">
          <div>
            <h1 class="text-lg font-bold flex flex-wrap items-center gap-2">{{ account.code }} — {{ account.name }} <StatusBadge :label="account.account_type" :tone="tone(account.account_type)" /> <span class="text-xs font-mono px-2 py-0.5 rounded-full border font-bold" :class="account.nature==='debit'?'bg-emerald-500/10 text-emerald-700 border-emerald-500/20':'bg-rose-500/10 text-rose-700 border-rose-500/20'">{{ account.nature.toUpperCase() }}</span></h1>
            <p class="text-xs text-muted-foreground mt-1"><span v-for="a in ancestors" :key="a.id" class="font-mono">{{ a.code }} › </span>{{ account.code }} · {{ account.is_group?'Group container':'Posting leaf' }} · {{ account.is_system?'Protected system':'User-managed' }}</p>
          </div>
          <Link :href="route('accounts.reports.ledger', {account_id: account.id})"><Button variant="outline" size="sm"><History class="size-4" /> View ledger</Button></Link>
        </div>

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
          <div class="bg-card border rounded-2xl p-4 shadow-sm">
            <p class="text-xs text-muted-foreground">Current balance</p>
            <p class="text-xl font-bold font-mono mt-1" :class="balance>=0?'text-emerald-600':'text-rose-600'">{{ formatCurrency(balance) }}</p>
            <p class="text-xs text-muted-foreground">{{ account.nature }} nature · posted only</p>
          </div>
          <div class="bg-card border rounded-2xl p-4 shadow-sm">
            <p class="text-xs text-muted-foreground">Children</p>
            <p class="text-xl font-bold">{{ account.children?.length ?? 0 }}</p>
            <p class="text-xs text-muted-foreground">Direct descendants</p>
          </div>
          <div class="bg-card border rounded-2xl p-4 shadow-sm">
            <p class="text-xs text-muted-foreground">Posting?</p>
            <div class="mt-2"><StatusBadge :label="account.is_posting?'Yes — accepts postings':'No (Group)'" :tone="account.is_posting?'success':'warning'" /></div>
          </div>
          <div class="bg-card border rounded-2xl p-4 shadow-sm">
            <p class="text-xs text-muted-foreground">Status</p>
            <div class="mt-2 flex items-center gap-2"><StatusBadge :label="account.is_active?'Active':'Inactive'" :tone="account.is_active?'success':'neutral'" /> <span v-if="account.is_system" class="text-xs px-2 py-0.5 rounded-full bg-amber-500/10 text-amber-700 border border-amber-500/20">SYSTEM</span></div>
          </div>
        </div>

        <AcctSection title="Children" :description="`${account.children?.length ?? 0} direct · deep tree via Quick tree on Index`">
          <div v-if="!account.children?.length" class="p-6"><AcctEmpty title="No children" :hint="account.is_group ? 'Groups hold sub-accounts. Add children to organise the COA.' : 'Leaf posting account — postings land here, not on the group above.'" :icon="Layers" /></div>
          <div v-else class="divide-y">
            <div v-for="ch in account.children" :key="ch.id" class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 hover:bg-muted/30 transition">
              <div class="min-w-0"><p class="font-mono text-xs font-bold">{{ ch.code }}</p><p class="text-sm font-medium truncate">{{ ch.name }}</p></div>
              <Link :href="route('accounts.coa.show', ch.id)" class="text-sm text-primary hover:underline">View →</Link>
            </div>
          </div>
        </AcctSection>

        <AcctSection title="Recent ledger (20)" description="Last postings · full history in General Ledger">
          <div v-if="!account.ledger_entries?.length" class="p-6"><AcctEmpty title="No postings yet" hint="This account has no ledger rows. Create a journal that targets this account and post it." :icon="Wallet" /></div>
          <div v-else class="divide-y">
            <div v-for="le in account.ledger_entries" :key="le.id" class="flex flex-wrap justify-between gap-2 px-4 py-2.5 text-sm hover:bg-muted/20">
              <span class="font-mono text-xs">{{ le.posting_date }} · {{ le.reference_no || le.journal_no || '—' }}</span>
              <span class="font-mono font-medium">{{ Number(le.debit)>0?`Dr ${formatCurrency(Number(le.debit))}`:`Cr ${formatCurrency(Number(le.credit))}` }} <span class="text-muted-foreground">→ Bal {{ formatCurrency(Number(le.balance_after)) }}</span></span>
            </div>
          </div>
          <div v-if="account.ledger_entries?.length" class="p-3 border-t bg-muted/20 text-center"><Link :href="route('accounts.reports.ledger', {account_id: account.id})" class="text-sm font-medium text-primary hover:underline">View full ledger →</Link></div>
        </AcctSection>
      </div>
    </PageHeader>
  </AdminLayout>
</template>
