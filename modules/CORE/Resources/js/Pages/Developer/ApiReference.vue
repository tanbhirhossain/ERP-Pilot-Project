<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { BookOpen, KeyRound } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import { useLocale } from '@core/Composables/useLocale';
import type { ApiSpec, ApiSpecOperation, ApiSpecParam } from '@core/types';

const props = defineProps<{
    spec: ApiSpec;
    specUrl: string;
    canTry: boolean;
}>();

const { t: tr } = useLocale();

const base = computed(() => props.spec.servers?.[0]?.url ?? '');

/** The rules live in the spec's own description, so this page cannot restate them wrongly. */
const preamble = computed(() =>
    (props.spec.info.description ?? '').split('\n\n').filter((p) => p.trim() !== ''),
);

type Flat = { path: string; method: string; op: ApiSpecOperation };

const grouped = computed(() => {
    const byTag = new Map<string, Flat[]>();
    for (const [path, ops] of Object.entries(props.spec.paths ?? {})) {
        for (const [method, op] of Object.entries(ops)) {
            const tag = op.tags?.[0] ?? 'Other';
            if (!byTag.has(tag)) byTag.set(tag, []);
            byTag.get(tag)!.push({ path, method, op });
        }
    }
    return [...byTag.entries()].map(([tag, items]) => ({ tag, items: items.sort((a, b) => a.path.localeCompare(b.path)) }));
});

const opCount = computed(() => grouped.value.reduce((n, g) => n + g.items.length, 0));

function schemaName(ref?: string) {
    return ref ? ref.split('/').pop() : null;
}
function resolve(ref?: unknown): Record<string, unknown> {
    if (!ref || typeof ref !== 'object') return {};
    const r = ref as Record<string, unknown>;
    if (typeof r.$ref === 'string') {
        return (props.spec.components?.schemas?.[schemaName(r.$ref) as string] ?? {}) as Record<string, unknown>;
    }
    return r;
}
function propsOf(schema: Record<string, unknown>): string[] {
    const target = (schema.allOf as Record<string, unknown>[] | undefined)?.length
        ? (schema.allOf as Record<string, unknown>[]).flatMap((part) => Object.keys(propsOf(resolvePart(part))))
        : Object.keys((schema.properties as Record<string, unknown>) ?? {});
    return target;
}
function resolvePart(part: Record<string, unknown>): Record<string, unknown> {
    return typeof part.$ref === 'string' ? resolve(part.$ref) : part;
}
function responseSchema(op: ApiSpecOperation): Record<string, unknown> {
    const ok = (op.responses as Record<string, Record<string, unknown>>)['200'] ?? (op.responses as Record<string, Record<string, unknown>>)['201'];
    const content = ok?.content as Record<string, Record<string, unknown>> | undefined;
    return resolve(content?.['application/json']?.schema);
}
function requestSchema(op: ApiSpecOperation): Record<string, unknown> {
    const body = op.requestBody as { content?: Record<string, { schema?: unknown }> } | undefined;
    return resolve(body?.content?.['application/json']?.schema);
}
function requiredOf(schema: Record<string, unknown>): string[] {
    return (schema.required as string[] | undefined) ?? [];
}
function describeParam(p: ApiSpecParam): string {
    const s = p.schema as { type?: string | string[]; enum?: string[]; format?: string } | undefined;
    const type = Array.isArray(s?.type) ? s!.type.join('|') : (s?.type ?? 'string');
    return s?.enum ? `${type}: ${s.enum.join(' | ')}` : type;
}
function curlExample(item: Flat) {
    const url = `${base.value}${item.path}`.replace(/\{(\w+)\}/g, '<$1>');
    const verb = item.method.toUpperCase();
    const body = verb === 'POST' ? ` \\\n  -H "Content-Type: application/json" \\\n  -d '{"journal_date":"2026-11-08","type":"JV","lines":[{"account_id":1,"debit":"100.00"},{"account_id":2,"credit":"100.00"}]}'` : '';
    return `curl -X ${verb} "${url}" \\\n  -H "Authorization: Bearer $TOKEN"${body}`;
}
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Administration' }, { title: tr('API Reference') }]">
    <Head :title="tr('API Reference')" />
    <PageHeader
      :title="tr('API Reference')"
      :description="tr('api.specNote')"
      :icon="BookOpen"
      :stats="[
        { label: 'Endpoints', value: String(opCount) },
        { label: 'Spec', value: spec.openapi },
        { label: 'Version', value: spec.info.version },
      ]"
    >
      <template #actions>
        <div class="flex items-center gap-2">
          <a :href="specUrl" target="_blank" rel="noopener"><Button variant="outline" size="sm" class="rounded-full">openapi.json</Button></a>
          <Link :href="route('core.developer.tokens.index')"><Button size="sm" class="rounded-full"><KeyRound class="size-4" /> {{ tr('api.newToken') }}</Button></Link>
        </div>
      </template>
    </PageHeader>

    <!-- The rules, verbatim from the spec -->
    <div class="rounded-xl border bg-card p-5 shadow-sm">
      <div class="grid gap-3 text-sm sm:grid-cols-2">
        <p v-for="(para, i) in preamble.slice(1)" :key="i" class="text-muted-foreground first-line:font-medium first-line:text-foreground">{{ para.replace(/\*\*/g, '') }}</p>
      </div>
    </div>

    <!-- Getting a token -->
    <div class="rounded-xl border bg-card p-5 shadow-sm">
      <h2 class="font-semibold">Getting a token</h2>
      <p class="mt-1 text-sm text-muted-foreground">Either exchange credentials below, or create a token on the <Link class="underline" :href="route('core.developer.tokens.index')">API Tokens</Link> screen and paste it here.</p>
      <pre class="mt-3 overflow-auto rounded-lg border bg-muted/40 p-3 font-mono text-[11px] leading-relaxed">curl -X POST "{{ base }}/api/v1/auth/token" \
  -H "Content-Type: application/json" \
  -d '{"email":"you@company.test","password":"…","device_name":"ci","abilities":["read"]}'

TOKEN="…the token from that response…"
{{ curlExample({ path: '/api/v1/accounts/trial-balance', method: 'get', op: { summary: '', responses: {} } }) }}</pre>
    </div>

    <!-- Endpoints -->
    <section v-for="group in grouped" :key="group.tag" class="space-y-3">
      <h2 class="px-1 text-sm font-semibold uppercase tracking-wide text-muted-foreground">{{ group.tag }}</h2>

      <div v-for="item in group.items" :key="`${item.method}${item.path}`" class="rounded-xl border bg-card shadow-sm">
        <details class="group">
          <summary class="flex cursor-pointer flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3">
            <span class="rounded-md px-2 py-0.5 font-mono text-[11px] font-bold"
              :class="item.method === 'post' ? 'bg-emerald-500/10 text-emerald-700' : 'bg-sky-500/10 text-sky-700'">{{ item.method.toUpperCase() }}</span>
            <code class="font-mono text-xs">{{ item.path }}</code>
            <span class="min-w-0 flex-1 truncate text-sm text-muted-foreground">{{ item.op.summary }}</span>
            <span v-if="item.op['x-required-permission']" class="rounded-full border bg-muted/40 px-2 py-0.5 font-mono text-[11px]">{{ item.op['x-required-permission'] }}</span>
            <span v-if="item.op['x-required-ability']" class="rounded-full border border-amber-500/25 bg-amber-500/10 px-2 py-0.5 font-mono text-[11px] text-amber-800">{{ item.op['x-required-ability'] }}</span>
          </summary>

          <div class="space-y-4 border-t px-4 py-4 text-sm">
            <p v-if="item.op.description" class="max-w-3xl whitespace-pre-line text-muted-foreground">{{ item.op.description }}</p>

            <div v-if="item.op.parameters?.length">
              <h3 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{{ tr('api.parameters') }}</h3>
              <table class="mt-2 w-full text-xs">
                <tbody class="divide-y">
                  <tr v-for="p in item.op.parameters" :key="`${p.in}${p.name}`">
                    <td class="py-1.5 pr-3 font-mono align-top">{{ p.name }}<span v-if="p.required" class="text-destructive">*</span></td>
                    <td class="py-1.5 pr-3 align-top text-muted-foreground">{{ p.in }}</td>
                    <td class="py-1.5 pr-3 font-mono align-top">{{ describeParam(p) }}</td>
                    <td class="py-1.5 align-top text-muted-foreground">{{ p.description || '' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div v-if="Object.keys(requestSchema(item.op)).length" class="grid gap-4 lg:grid-cols-2">
              <div>
                <h3 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Request body</h3>
                <ul class="mt-2 space-y-1 font-mono text-xs">
                  <li v-for="k in propsOf(requestSchema(item.op))" :key="k" class="flex gap-2">
                    <span>{{ k }}</span>
                    <span v-if="requiredOf(requestSchema(item.op)).includes(k)" class="text-[10px] uppercase text-destructive">required</span>
                  </li>
                </ul>
              </div>
              <div v-if="Object.keys(responseSchema(item.op)).length">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{{ tr('api.usesCasts') }}</h3>
                <ul class="mt-2 space-y-1 font-mono text-xs">
                  <li v-for="k in propsOf(responseSchema(item.op))" :key="k">{{ k }}</li>
                </ul>
              </div>
            </div>

            <div>
              <h3 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Example</h3>
              <pre class="mt-2 overflow-auto rounded-lg border bg-muted/40 p-3 font-mono text-[11px] leading-relaxed">{{ curlExample(item) }}</pre>
            </div>

            <p class="text-[11px] text-muted-foreground">
              middleware: <span class="font-mono">{{ (item.op['x-middleware'] || []).join(' → ') }}</span>
              <template v-if="!canTry"> · sign in to try this endpoint</template>
            </p>
          </div>
        </details>
      </div>
    </section>
  </AdminLayout>
</template>
