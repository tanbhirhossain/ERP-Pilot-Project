<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { HardDrive, Plus, Play, Trash2, X } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
import { formatCurrency } from '@core/Utils/format';
import { useLocale } from '@core/Composables/useLocale';
import type { SelectOption } from '@core/types';
import type { AssetCategoryOption, FixedAsset } from '@accounts/types';

const props = defineProps<{
    assets: FixedAsset[];
    status?: string | null;
    summary: { count: number; cost: number; accumulated: number; nbv: number };
    categories: AssetCategoryOption[];
    canManage: boolean;
}>();

const { t: tr } = useLocale();

const categoryOptions = computed<SelectOption[]>(() =>
    props.categories.map((c) => ({ value: c.id, label: `${c.name} · ${c.code}`, description: `${c.default_useful_life_years}y · ${c.default_method.replace('_', ' ')}` })),
);

const filters = [
    { id: '', label: 'All' },
    { id: 'draft', label: 'Draft' },
    { id: 'in_use', label: 'In use' },
    { id: 'fully_depreciated', label: 'Fully dep.' },
    { id: 'disposed', label: 'Disposed' },
];
function setFilter(id: string) {
    router.get(route('accounts.assets.index'), id ? { status: id } : {}, { preserveState: true, preserveScroll: true });
}

const showCreate = ref(false);
const create = useForm({
    name: '', category_id: '' as number | string, purchase_date: new Date().toISOString().slice(0, 10),
    in_service_date: '', cost: '', salvage_value: '', method: '', useful_life_years: '', db_factor: '',
    invoice_ref: '', location: '', notes: '',
});
const chosenCategory = computed(() => props.categories.find((c) => String(c.id) === String(create.category_id)));
function submitCreate() {
    create.post(route('accounts.assets.store'), {
        onSuccess: () => { create.reset(); showCreate.value = false; },
    });
}

const disposeTarget = ref<FixedAsset | null>(null);
const dispose = useForm({ disposed_date: new Date().toISOString().slice(0, 10), disposal_proceeds: '', notes: '' });
function openDispose(a: FixedAsset) { disposeTarget.value = a; dispose.clearErrors(); }
function submitDispose() {
    if (!disposeTarget.value) return;
    dispose.post(route('accounts.assets.dispose', disposeTarget.value.id), { onSuccess: () => { disposeTarget.value = null; dispose.reset(); } });
}
function capitalize(a: FixedAsset) { useForm({}).post(route('accounts.assets.capitalize', a.id)); }

function statusTone(s: string) {
    return s === 'in_use' ? 'bg-emerald-500/10 text-emerald-700 border-emerald-500/20'
        : s === 'fully_depreciated' ? 'bg-sky-500/10 text-sky-700 border-sky-500/20'
        : s === 'disposed' ? 'bg-muted text-muted-foreground border-border'
        : 'bg-amber-500/10 text-amber-700 border-amber-500/20';
}
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Accounts' }, { title: 'Fixed Assets' }]">
    <Head :title="tr('assets.register')" />
    <PageHeader
      :title="tr('assets.register')"
      :description="tr('assets.registerSubtitle')"
      :icon="HardDrive"
      :stats="[
        { label: tr('assets.cost'), value: formatCurrency(summary.cost) },
        { label: tr('assets.accumulated'), value: formatCurrency(summary.accumulated) },
        { label: tr('assets.nbv'), value: formatCurrency(summary.nbv) },
        { label: 'Assets', value: String(summary.count), sub: 'on the register' },
      ]"
    >
      <template #actions>
        <div class="flex items-center gap-2">
          <Link :href="route('accounts.assets.depreciation')">
            <Button variant="outline" size="sm" class="rounded-full"><Play class="size-4" /> {{ tr('assets.run') }}</Button>
          </Link>
          <Button v-if="canManage" size="sm" class="rounded-full" @click="showCreate = !showCreate"><Plus class="size-4" /> {{ tr('assets.new') }}</Button>
        </div>
      </template>
    </PageHeader>

    <!-- Status filters -->
    <div class="flex flex-wrap gap-1.5">
      <button v-for="f in filters" :key="f.id" type="button" class="rounded-full border px-3 py-1 text-xs font-medium transition" :class="(props.status || '') === f.id ? 'bg-primary text-primary-foreground border-primary' : 'bg-card text-muted-foreground hover:bg-accent'" @click="setFilter(f.id)">{{ f.label }}</button>
    </div>

    <!-- Create panel -->
    <div v-if="showCreate && canManage" class="rounded-xl border bg-card p-5 shadow-sm">
      <div class="flex items-center justify-between">
        <h2 class="flex items-center gap-2 font-semibold"><Plus class="size-4 text-primary" /> {{ tr('assets.new') }}</h2>
        <Button variant="ghost" size="icon" class="size-7" @click="showCreate = false"><X class="size-4" /></Button>
      </div>
      <form class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="submitCreate">
        <div class="lg:col-span-2"><Label>Name</Label><Input v-model="create.name" placeholder="e.g. Toyota Hilux chassis-2026" /></div>
        <div class="lg:col-span-2"><Label>Category</Label><SearchableSelect v-model="create.category_id" :options="categoryOptions" placeholder="Select category" /></div>
        <div><Label>Purchase date</Label><Input v-model="create.purchase_date" type="date" /></div>
        <div><Label>In-service (optional)</Label><Input v-model="create.in_service_date" type="date" /></div>
        <div><Label>Cost (BDT)</Label><Input v-model="create.cost" type="number" min="0" step="0.01" /></div>
        <div><Label>Salvage (blank = category %)</Label><Input v-model="create.salvage_value" type="number" min="0" step="0.01" /></div>
        <div><Label>Method</Label>
          <select v-model="create.method" class="h-10 w-full rounded-md border bg-background px-3 text-sm">
            <option value="">Category default</option>
            <option value="straight_line">Straight line</option>
            <option value="declining_balance">Declining balance</option>
          </select>
        </div>
        <div><Label>Life (years)</Label><Input v-model="create.useful_life_years" type="number" min="1" max="100" placeholder="cat. default" /></div>
        <div><Label>DB factor (blank = {{ chosenCategory ? chosenCategory.default_db_factor : 2 }})</Label><Input v-model="create.db_factor" type="number" min="0.01" max="10" step="0.01" /></div>
        <div><Label>Location</Label><Input v-model="create.location" /></div>
        <div class="sm:col-span-2 lg:col-span-4 flex items-end"><Button :disabled="create.processing || !create.name || !create.category_id || !create.cost"><Plus class="size-4" /> Capture asset</Button></div>
      </form>
      <p v-if="create.errors.name || create.errors.cost || create.errors.category_id" class="mt-2 text-sm text-destructive">{{ create.errors.name || create.errors.cost || create.errors.category_id }}</p>
      <p class="mt-2 text-xs text-muted-foreground">Captured as a draft — press <span class="font-medium">Capitalise</span> on its row to freeze the category defaults and start depreciating.</p>
    </div>

    <!-- Register table -->
    <div class="rounded-xl border bg-card shadow-sm">
      <div class="overflow-auto">
        <table class="w-full text-sm">
          <thead class="bg-muted/50 text-left text-xs text-muted-foreground">
            <tr><th class="px-4 py-2">Code</th><th class="px-4 py-2">Asset</th><th class="px-4 py-2">Category</th><th class="px-4 py-2">In service</th><th class="px-4 py-2">Status</th><th class="px-4 py-2 text-right">{{ tr('assets.cost') }}</th><th class="px-4 py-2 text-right">{{ tr('assets.accumulated') }}</th><th class="px-4 py-2 text-right">{{ tr('assets.nbv') }}</th><th v-if="canManage" class="px-4 py-2"></th></tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="a in assets" :key="a.id" class="hover:bg-accent/40">
              <td class="px-4 py-2.5 font-mono text-xs">{{ a.asset_code }}</td>
              <td class="px-4 py-2.5"><span class="font-medium">{{ a.name }}</span><span v-if="a.location" class="block text-xs text-muted-foreground">{{ a.location }}</span></td>
              <td class="px-4 py-2.5 text-xs">{{ a.category?.name ?? '—' }}</td>
              <td class="px-4 py-2.5 whitespace-nowrap text-xs">{{ a.in_service_date || a.purchase_date }}</td>
              <td class="px-4 py-2.5"><span class="inline-flex rounded-full border px-2 py-0.5 text-xs font-medium capitalize" :class="statusTone(a.status)">{{ a.status.replace(/_/g, ' ') }}</span></td>
              <td class="px-4 py-2.5 text-right font-mono">{{ formatCurrency(a.cost) }}</td>
              <td class="px-4 py-2.5 text-right font-mono text-muted-foreground">{{ formatCurrency(a.accumulated_dep) }}</td>
              <td class="px-4 py-2.5 text-right font-mono font-semibold">{{ formatCurrency(a.net_book_value) }}</td>
              <td v-if="canManage" class="px-4 py-2.5">
                <div class="flex justify-end gap-1.5">
                  <Button v-if="a.status === 'draft'" variant="outline" size="sm" class="rounded-full" @click="capitalize(a)"><Play class="size-3.5" /> {{ tr('assets.capitalise') }}</Button>
                  <Button v-else-if="a.status !== 'disposed'" variant="ghost" size="sm" class="rounded-full text-muted-foreground hover:text-destructive" @click="openDispose(a)"><Trash2 class="size-3.5" /> {{ tr('assets.dispose') }}</Button>
                </div>
              </td>
            </tr>
            <tr v-if="!assets.length"><td :colspan="canManage ? 9 : 8" class="px-4 py-8 text-center text-muted-foreground">No assets on the register yet.</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Dispose dialog (inline card) -->
    <div v-if="disposeTarget" class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" @click.self="disposeTarget = null">
      <div class="w-full max-w-md rounded-xl border bg-card p-5 shadow-xl">
        <div class="flex items-center justify-between">
          <h3 class="font-semibold">{{ tr('assets.dispose') }} · {{ disposeTarget.asset_code }}</h3>
          <Button variant="ghost" size="icon" class="size-7" @click="disposeTarget = null"><X class="size-4" /></Button>
        </div>
        <p class="mt-1 text-xs text-muted-foreground">Removes the asset from depreciation. NBV {{ formatCurrency(disposeTarget.net_book_value) }}. Post the write-off (proceeds, gain/loss) as a normal journal entry.</p>
        <form class="mt-4 grid gap-3 sm:grid-cols-2" @submit.prevent="submitDispose">
          <div><Label>Disposal date</Label><Input v-model="dispose.disposed_date" type="date" /></div>
          <div><Label>Proceeds (optional)</Label><Input v-model="dispose.disposal_proceeds" type="number" min="0" step="0.01" /></div>
          <div class="sm:col-span-2"><Label>Notes</Label><Input v-model="dispose.notes" /></div>
          <div class="sm:col-span-2 flex justify-end gap-2"><Button variant="outline" type="button" @click="disposeTarget = null">{{ tr('common.cancel') }}</Button><Button :disabled="dispose.processing"><Trash2 class="size-4" /> {{ tr('assets.dispose') }}</Button></div>
        </form>
        <p v-if="dispose.errors.disposed_date" class="mt-2 text-sm text-destructive">{{ dispose.errors.disposed_date }}</p>
      </div>
    </div>
  </AdminLayout>
</template>
