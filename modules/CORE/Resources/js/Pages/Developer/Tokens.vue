<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Check, Copy, KeyRound, Plus, Trash2 } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import { confirmDialog } from '@core/Composables/useConfirm';
import { useLocale } from '@core/Composables/useLocale';
import type { ApiTokenIssued, ApiTokenRow } from '@core/types';

const props = defineProps<{
    tokens: ApiTokenRow[];
    presets: string[];
    maxDays: number;
    issued?: ApiTokenIssued | null;
}>();

const { t: tr } = useLocale();

const showForm = ref(false);
const copied = ref(false);
const form = useForm<{ name: string; abilities: string[]; expires_in_days: string | number }>({
    name: '',
    abilities: [],
    expires_in_days: '',
});

function toggle(ability: string) {
    form.abilities = form.abilities.includes(ability)
        ? form.abilities.filter((a) => a !== ability)
        : [...form.abilities, ability];
}

function submit() {
    form.post(route('core.developer.tokens.store'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            showForm.value = false;
        },
    });
}

async function copyToken() {
    if (!props.issued) return;
    try {
        await navigator.clipboard.writeText(props.issued.token);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        // No clipboard permission (plain HTTP, embedded frame): selecting the text is
        // the fallback, so the token is still recoverable without a paste-then-lose it.
        copied.value = false;
    }
}

async function revoke(id: number) {
    // confirmDialog, not window.confirm: the native one is a ratcheted-away pattern in this
    // repo (NativeDialogTest), and a blocking OS dialog cannot be themed, translated, or
    // cancelled by keyboard the way the rest of the app's dialogs are.
    const proceed = await confirmDialog({
        title: tr('api.revoke'),
        description: 'Revoke this token? Any integration using it stops working immediately.',
        confirmLabel: tr('api.revoke'),
        danger: true,
    });
    if (!proceed) return;
    router.delete(route('core.developer.tokens.destroy', id), { preserveScroll: true });
}

function when(iso?: string | null) {
    return iso ? new Date(iso).toLocaleString() : tr('api.neverUsed');
}
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Administration' }, { title: tr('API Tokens') }]">
    <Head :title="tr('API Tokens')" />
    <PageHeader
      :title="tr('API Tokens')"
      :description="tr('api.tokensIntro')"
      :icon="KeyRound"
      :stats="[{ label: 'Tokens', value: String(tokens.length) }]"
    >
      <template #actions>
        <div class="flex items-center gap-2">
          <Link :href="route('core.developer.api.index')"><Button variant="outline" size="sm" class="rounded-full">{{ tr('API Reference') }}</Button></Link>
          <Button size="sm" class="rounded-full" @click="showForm = !showForm"><Plus class="size-4" /> {{ tr('api.newToken') }}</Button>
        </div>
      </template>
    </PageHeader>

    <!-- One-time reveal -->
    <div v-if="issued" class="rounded-xl border border-emerald-500/30 bg-emerald-500/[0.06] p-5">
      <p class="text-sm font-semibold text-emerald-800">{{ issued.name }}</p>
      <p class="mt-0.5 text-xs text-muted-foreground">{{ tr('api.oneTime') }}</p>
      <div class="mt-3 flex flex-wrap items-center gap-2">
        <code class="min-w-0 flex-1 truncate rounded-md border bg-background px-3 py-2 font-mono text-xs">{{ issued.token }}</code>
        <Button size="sm" variant="outline" class="rounded-full" @click="copyToken">
          <component :is="copied ? Check : Copy" class="size-4" /> {{ copied ? tr('api.copied') : tr('api.copy') }}
        </Button>
      </div>
      <p class="mt-2 text-xs text-muted-foreground">
        {{ tr('api.abilities') }}: <span class="font-mono">{{ issued.abilities.join(', ') }}</span>
        <template v-if="issued.expires_at"> · {{ tr('api.expires') }} {{ when(issued.expires_at) }}</template>
      </p>
    </div>

    <!-- Create -->
    <div v-if="showForm" class="rounded-xl border bg-card p-5 shadow-sm">
      <h2 class="flex items-center gap-2 font-semibold"><Plus class="size-4 text-primary" /> {{ tr('api.newToken') }}</h2>
      <form class="mt-4 grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
        <div>
          <Label for="tok-name">{{ tr('api.tokenName') }}</Label>
          <Input id="tok-name" v-model="form.name" placeholder="e.g. Warehouse scanner" class="mt-1" />
        </div>
        <div>
          <Label for="tok-days">{{ tr('api.expiresInDays') }}</Label>
          <Input id="tok-days" v-model="form.expires_in_days" type="number" min="1" :max="maxDays" class="mt-1" :placeholder="tr('api.noExpiry')" />
        </div>
        <div class="sm:col-span-2">
          <Label>{{ tr('api.abilities') }}</Label>
          <div class="mt-2 flex flex-wrap gap-2">
            <button
              v-for="p in presets"
              :key="p"
              type="button"
              class="rounded-full border px-3 py-1 text-xs font-medium transition"
              :class="form.abilities.includes(p) ? 'border-primary bg-primary/10 text-primary' : 'text-muted-foreground hover:bg-accent'"
              @click="toggle(p)"
            >{{ p }}</button>
          </div>
          <p class="mt-2 text-xs text-muted-foreground">
            Nothing selected means the token follows your permissions exactly. Selections only ever narrow it.
          </p>
        </div>
        <div class="flex items-center gap-2">
          <Button type="submit" :disabled="form.processing || !form.name"><Plus class="size-4" /> {{ tr('api.newToken') }}</Button>
          <Button type="button" variant="ghost" @click="showForm = false">Cancel</Button>
        </div>
      </form>
      <p v-if="form.errors.name" class="mt-2 text-sm text-destructive">{{ form.errors.name }}</p>
      <p v-if="form.errors.abilities" class="mt-2 text-sm text-destructive">{{ form.errors.abilities }}</p>
    </div>

    <!-- List -->
    <div class="rounded-xl border bg-card shadow-sm">
      <div class="border-b px-5 py-3"><h3 class="font-semibold">{{ tr('api.activeTokens') }}</h3></div>
      <div class="overflow-auto">
        <table class="w-full text-sm">
          <thead class="bg-muted/50 text-left text-xs text-muted-foreground">
            <tr>
              <th class="px-4 py-2">{{ tr('api.tokenName') }}</th>
              <th class="px-4 py-2">{{ tr('api.abilities') }}</th>
              <th class="px-4 py-2">{{ tr('api.lastUsed') }}</th>
              <th class="px-4 py-2">{{ tr('api.expires') }}</th>
              <th class="px-4 py-2"></th>
            </tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="row in tokens" :key="row.id" class="hover:bg-accent/40">
              <td class="px-4 py-2.5 font-medium">{{ row.name }}</td>
              <td class="px-4 py-2.5">
                <div class="flex flex-wrap gap-1">
                  <span v-for="a in row.abilities" :key="a" class="rounded-full border bg-muted/40 px-2 py-0.5 font-mono text-[11px]">{{ a }}</span>
                </div>
              </td>
              <td class="px-4 py-2.5 text-xs text-muted-foreground">{{ when(row.last_used_at) }}</td>
              <td class="px-4 py-2.5 text-xs">
                <span v-if="row.expired" class="rounded-full border border-rose-500/25 bg-rose-500/10 px-2 py-0.5 text-rose-700">expired</span>
                <span v-else class="text-muted-foreground">{{ row.expires_at ? when(row.expires_at) : 'never' }}</span>
              </td>
              <td class="px-4 py-2.5 text-right">
                <Button variant="ghost" size="sm" class="rounded-full text-muted-foreground hover:text-destructive" @click="revoke(row.id)">
                  <Trash2 class="size-3.5" /> {{ tr('api.revoke') }}
                </Button>
              </td>
            </tr>
            <tr v-if="!tokens.length"><td colspan="5" class="px-4 py-8 text-center text-muted-foreground">{{ tr('api.noTokens') }}</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </AdminLayout>
</template>
