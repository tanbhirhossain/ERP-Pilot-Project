<script setup lang="ts">
import PageHeader from '@core/Components/PageHeader.vue';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import { Button } from '@/components/ui/button';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { AlertTriangle, CheckCircle2, Download, FileSpreadsheet, Upload, XCircle } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface ImportType {
    key: string;
    label: string;
    headers: string[];
}
interface PreviewResult {
    type: string;
    rows: number;
    valid: boolean;
    errors: Record<string, string[]>;
    preview: Array<Record<string, string | number>>;
    message?: string;
}
interface Props {
    types: ImportType[];
}
const props = defineProps<Props>();

const page = usePage<{ preview?: PreviewResult | null }>();
const selected = ref<string>(props.types[0]?.key ?? '');
const fileInput = ref<HTMLInputElement | null>(null);
const fileName = ref('');

const uploadForm = useForm({ file: null as File | null });
const commitForm = useForm({});

const currentType = computed(() => props.types.find((t) => t.key === selected.value));
const preview = computed<PreviewResult | null>(() => page.props.preview ?? null);
const previewForSelection = computed(() => (preview.value && preview.value.type === selected.value ? preview.value : null));

const errorCount = computed(() => {
    if (!previewForSelection.value) return 0;
    return Object.values(previewForSelection.value.errors).reduce((sum, errs) => sum + errs.length, 0);
});
const errorRows = computed(() => (previewForSelection.value ? Object.keys(previewForSelection.value.errors).length : 0));

function onFile(e: Event): void {
    const target = e.target as HTMLInputElement;
    const file = target.files?.[0] ?? null;
    uploadForm.file = file;
    fileName.value = file?.name ?? '';
}

function submitPreview(): void {
    if (!uploadForm.file) return;
    uploadForm.post(route('core.imports.preview', { type: selected.value }), {
        forceFormData: true,
        preserveScroll: true,
    });
}

function submitCommit(): void {
    commitForm.post(route('core.imports.commit', { type: selected.value }), { preserveScroll: true });
}

function downloadTemplate(): void {
    window.location.href = route('core.imports.template', { type: selected.value });
}

// Clear a stale preview when the operator switches type.
watch(selected, () => {
    fileName.value = '';
    uploadForm.file = null;
    if (fileInput.value) fileInput.value.value = '';
});
</script>

<template>
    <Head title="Data Import" />
    <AdminLayout>
        <PageHeader title="Data Import" description="Bring your existing records in from Excel or CSV. Preview first — nothing is written until you commit." :icon="FileSpreadsheet" />

        <div v-if="props.types.length === 0" class="mt-6 rounded-xl border border-dashed bg-card px-6 py-12 text-center">
            <Upload class="mx-auto size-8 text-muted-foreground/40" />
            <p class="mt-2 text-sm font-medium">No import types available</p>
            <p class="text-[13px] text-muted-foreground">You don't have permission to import any data type.</p>
        </div>

        <div v-else class="mt-6 grid gap-6 lg:grid-cols-[280px_1fr]">
            <!-- Type picker -->
            <aside class="space-y-2">
                <p class="px-1 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">What are you importing?</p>
                <button
                    v-for="t in props.types"
                    :key="t.key"
                    type="button"
                    class="flex w-full items-center gap-3 rounded-xl border px-3.5 py-3 text-left transition"
                    :class="selected === t.key ? 'border-primary bg-primary/5 shadow-sm' : 'bg-card hover:bg-accent'"
                    @click="selected = t.key"
                >
                    <span class="grid size-9 shrink-0 place-items-center rounded-lg" :class="selected === t.key ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground'">
                        <FileSpreadsheet class="size-4.5" />
                    </span>
                    <span class="min-w-0">
                        <span class="block text-sm font-medium">{{ t.label }}</span>
                        <span class="block truncate text-[11px] text-muted-foreground">{{ t.headers.length }} columns</span>
                    </span>
                </button>
            </aside>

            <!-- Upload + preview -->
            <div class="space-y-5">
                <div v-if="currentType" class="rounded-xl border bg-card p-5 shadow-sm">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 class="text-sm font-semibold">{{ currentType.label }}</h3>
                            <p class="mt-1 text-[13px] text-muted-foreground">
                                Expected columns:
                                <code v-for="(h, i) in currentType.headers" :key="h" class="ml-1 rounded bg-muted px-1.5 py-0.5 text-[11px]">{{ h }}<span v-if="i < currentType.headers.length - 1">,</span></code>
                            </p>
                        </div>
                        <Button variant="outline" size="sm" class="shrink-0 gap-1.5" @click="downloadTemplate"><Download class="size-4" /> Template</Button>
                    </div>

                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <input ref="fileInput" type="file" accept=".xlsx,.xls,.csv,.txt" class="hidden" @change="onFile" />
                        <Button variant="outline" class="gap-1.5" @click="fileInput?.click()"><Upload class="size-4" /> Choose file</Button>
                        <span v-if="fileName" class="text-[13px] text-muted-foreground">{{ fileName }}</span>
                        <Button :disabled="!uploadForm.file || uploadForm.processing" class="gap-1.5" @click="submitPreview">
                            {{ uploadForm.processing ? 'Validating…' : 'Preview' }}
                        </Button>
                    </div>
                    <p v-if="uploadForm.errors.file" class="mt-2 text-[12px] text-rose-600">{{ uploadForm.errors.file }}</p>
                </div>

                <!-- Preview result -->
                <div v-if="previewForSelection" class="rounded-xl border bg-card shadow-sm">
                    <div class="flex items-center justify-between border-b px-4 py-3">
                        <p class="flex items-center gap-2 text-sm font-semibold">
                            <CheckCircle2 v-if="previewForSelection.valid" class="size-4.5 text-emerald-500" />
                            <AlertTriangle v-else class="size-4.5 text-amber-500" />
                            Preview · {{ previewForSelection.rows }} row(s)
                        </p>
                        <span v-if="previewForSelection.valid" class="rounded-full bg-emerald-500/10 px-2.5 py-0.5 text-[11px] font-bold text-emerald-600">Ready to import</span>
                        <span v-else class="rounded-full bg-rose-500/10 px-2.5 py-0.5 text-[11px] font-bold text-rose-600">{{ errorCount }} error(s) in {{ errorRows }} row(s)</span>
                    </div>

                    <p v-if="previewForSelection.message" class="px-4 py-3 text-[13px] text-muted-foreground">{{ previewForSelection.message }}</p>

                    <!-- Errors -->
                    <div v-if="!previewForSelection.valid && errorRows > 0" class="border-b px-4 py-3">
                        <p class="mb-2 flex items-center gap-1.5 text-[12px] font-semibold text-rose-600"><XCircle class="size-3.5" /> Fix these before committing — the import is all-or-nothing.</p>
                        <ul class="max-h-40 space-y-1 overflow-y-auto text-[12px]">
                            <li v-for="(errs, rowIdx) in previewForSelection.errors" :key="rowIdx" class="text-rose-700 dark:text-rose-400">
                                <span class="font-semibold">Row {{ Number(rowIdx) + 2 }}:</span> {{ errs.join('; ') }}
                            </li>
                        </ul>
                    </div>

                    <!-- Sample rows -->
                    <div v-if="previewForSelection.preview.length" class="overflow-x-auto">
                        <table class="w-full text-[12px]">
                            <thead class="border-b bg-muted/40 text-left text-[10px] uppercase tracking-wider text-muted-foreground">
                                <tr><th v-for="h in currentType?.headers" :key="h" class="whitespace-nowrap px-3 py-2 font-semibold">{{ h }}</th></tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-for="(row, i) in previewForSelection.preview" :key="i" :class="previewForSelection.errors[String(i)] ? 'bg-rose-50/60 dark:bg-rose-950/20' : ''">
                                    <td v-for="h in currentType?.headers" :key="h" class="whitespace-nowrap px-3 py-1.5 tabular-nums">{{ row[h] ?? '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-if="previewForSelection.rows > 10" class="px-3 py-2 text-[11px] text-muted-foreground">Showing first 10 of {{ previewForSelection.rows }} rows.</p>
                    </div>

                    <div class="flex items-center justify-end gap-2 border-t px-4 py-3">
                        <Button variant="outline" @click="fileInput?.click()">Choose another file</Button>
                        <Button :disabled="!previewForSelection.valid || commitForm.processing" class="gap-1.5" @click="submitCommit">
                            <CheckCircle2 class="size-4" /> {{ commitForm.processing ? 'Importing…' : `Commit ${previewForSelection.rows} row(s)` }}
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
