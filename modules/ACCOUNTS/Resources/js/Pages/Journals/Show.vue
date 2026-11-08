<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

import { BookOpen, ShieldCheck, AlertTriangle, Pencil, Ban, CheckCircle2 } from 'lucide-vue-next';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import StatusBadge from '../../../../../CORE/Resources/js/Components/StatusBadge.vue';
import AcctSection from '../../Components/AcctSection.vue';
import { formatCurrency, formatDate } from '../../../../../CORE/Resources/js/Utils/format';
import { ref } from 'vue';
import StaticTable from '../../../../../CORE/Resources/js/Components/StaticTable.vue';
defineProps<{ journal:any }>();
const voidReason=ref('');
function post(id:number){ if(confirm('Post this journal? Ledger will be updated. Fiscal must be open.')) router.post(route('accounts.journals.post', id)); }
function voidJ(id:number){ if(!voidReason.value) return alert('Provide void reason'); if(!confirm('Void — a reversal journal will be created?')) return; router.post(route('accounts.journals.void', id), {reason: voidReason.value}); }
function tone(s:string){ if(s==='posted') return 'success'; if(s==='draft') return 'warning'; return 'danger'; }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Accounts' }, { title: 'Journals', href: route('accounts.journals.index') }, { title: journal.journal_no }]">
    <Head :title="journal.journal_no" />
    <PageHeader :title="journal.journal_no" :description="`${formatDate(journal.journal_date)} · Ref ${journal.reference_no || '—'} · ${journal.fiscal_year?.name || 'No FY'} · ${journal.type}`" :icon="BookOpen"
      :actions="[
        { label: journal.status==='draft' ? 'Post to ledger' : 'Posted', icon: journal.status==='draft' ? CheckCircle2 : ShieldCheck, variant: journal.status==='draft' ? 'default' : 'outline', disabled: journal.status!=='draft', onClick: ()=> post(journal.id) },
        { label:'Edit', route:'accounts.journals.edit', routeParams:{ journal: journal.id }, icon: Pencil, variant:'outline' },
      ]">
      <div class="w-full space-y-4">
        <div class="rounded-xl border p-4 shadow-sm" :class="journal.status==='posted'?'bg-emerald-500/[0.06] border-emerald-500/20':journal.status==='draft'?'bg-amber-500/[0.06] border-amber-500/20':'bg-rose-500/[0.06] border-rose-500/20'">
          <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
              <h1 class="text-xl font-bold flex flex-wrap items-center gap-2">{{ journal.journal_no }} <StatusBadge :label="journal.status" :tone="tone(journal.status)" /> <span class="text-xs px-2 py-0.5 rounded-full bg-primary text-primary-foreground font-mono font-bold">{{ journal.type }}</span></h1>
              <p class="text-sm text-muted-foreground mt-1">{{ journal.narration || 'No narration' }}</p>
            </div>
            <div class="text-right text-sm"><p class="font-mono font-bold">{{ formatCurrency(Number(journal.total_debit)) }}</p><p class="text-xs text-muted-foreground">Dr = Cr · {{ journal.lines.length }} lines</p></div>
          </div>
          <div v-if="journal.status==='posted'" class="mt-4 rounded-lg border bg-card p-3 flex flex-wrap gap-2 items-center">
            <div class="flex items-center gap-2 text-sm font-medium"><AlertTriangle class="size-4 text-amber-600" /> Void this journal?</div>
            <Input v-model="voidReason" placeholder="Void reason (audit trail) — required" class="flex-1 min-w-[12rem] h-9" />
            <Button variant="destructive" size="sm" @click="voidJ(journal.id)" class="shrink-0"><Ban class="size-4" /> Void (Reverse)</Button>
          </div>
        </div>

        <AcctSection :title="`Lines — ${journal.lines.length} · ${formatCurrency(Number(journal.total_debit))}`" description="Debit and credit per COA account. Totals must match.">
          <StaticTable
              flush
              :empty="!journal?.lines?.length"
              empty-title="This journal has no lines"
              empty-description="Debit and credit lines posted to this journal appear here."
          >
              <thead class="bg-muted/40 text-xs uppercase tracking-widest"><tr><th class="px-3 py-2.5 text-left">#</th><th class="px-3 py-2.5 text-left">Account</th><th class="px-3 py-2.5 text-right">Debit</th><th class="px-3 py-2.5 text-right">Credit</th><th class="px-3 py-2.5 text-left">Memo</th></tr></thead>
              <tbody class="divide-y"><tr v-for="(l,i) in journal.lines" :key="l.id" class="hover:bg-muted/30"><td class="px-3 py-2.5 text-muted-foreground">{{ i+1 }}</td><td class="px-3 py-2.5"><span class="font-mono text-xs px-1.5 py-0.5 rounded bg-muted border mr-1">{{ l.account?.code }}</span> <span class="font-medium">{{ l.account?.name }}</span></td><td class="px-3 py-2.5 text-right font-mono font-semibold" :class="Number(l.debit)>0?'text-emerald-600':''">{{ Number(l.debit)>0?formatCurrency(Number(l.debit)):'—' }}</td><td class="px-3 py-2.5 text-right font-mono font-semibold" :class="Number(l.credit)>0?'text-rose-600':''">{{ Number(l.credit)>0?formatCurrency(Number(l.credit)):'—' }}</td><td class="px-3 py-2.5 text-xs">{{ l.description || '—' }}</td></tr></tbody>
              <tfoot class="bg-primary text-primary-foreground font-bold"><tr><td colspan="2" class="px-3 py-2.5 text-right">Total</td><td class="px-3 py-2.5 text-right font-mono">{{ formatCurrency(Number(journal.total_debit)) }}</td><td class="px-3 py-2.5 text-right font-mono">{{ formatCurrency(Number(journal.total_credit)) }}</td><td class="px-3 py-2.5 text-xs opacity-80">✓ Balanced</td></tr></tfoot>
            </StaticTable>
        </AcctSection>
      </div>
    </PageHeader>
  </AdminLayout>
</template>
