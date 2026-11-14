<script setup lang="ts">
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Paperclip, Upload, Download, Trash2, FileText, Loader2 } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { useConfirm } from '@core/Composables/useConfirm';
import { useToast } from '@core/Composables/useToast';
import { formatDate } from '@core/Utils/format';

interface AttachmentRow {
    id: number;
    name: string;
    mime_type: string | null;
    size: number;
    created_at: string | null;
    uploader?: { name: string } | null;
}

const props = withDefaults(
    defineProps<{
        /** Allow-listed type key, e.g. 'invoice', 'bill', 'journal'. */
        type: string;
        recordId: number;
        attachments?: AttachmentRow[];
        canManage?: boolean;
    }>(),
    { attachments: () => [], canManage: false },
);

const { confirm } = useConfirm();
const toast = useToast();

const fileInput = ref<HTMLInputElement | null>(null);
const uploading = ref(false);

const rows = computed(() => props.attachments);

function humanSize(bytes: number): string {
    if (bytes >= 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    if (bytes >= 1024) return `${Math.round(bytes / 1024)} KB`;
    return `${bytes} B`;
}

function pick(): void {
    fileInput.value?.click();
}

function onFileChosen(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;

    if (file.size > 10 * 1024 * 1024) {
        toast.error('File is larger than 10 MB.');
        input.value = '';
        return;
    }

    uploading.value = true;
    router.post(route('core.attachments.store', { type: props.type, id: props.recordId }), { file }, {
        forceFormData: true,
        preserveScroll: true,
        onError: () => toast.error('Upload failed. Check the file type and size.'),
        onFinish: () => {
            uploading.value = false;
            input.value = '';
        },
    });
}

async function remove(row: AttachmentRow): Promise<void> {
    const ok = await confirm({
        title: 'Remove attachment?',
        description: `"${row.name}" will be permanently deleted.`,
        confirmLabel: 'Remove',
        danger: true,
    });
    if (!ok) return;
    router.delete(route('core.attachments.destroy', row.id), { preserveScroll: true });
}
</script>

<template>
    <section class="bg-card rounded-xl border p-4 shadow-sm">
        <header class="mb-3 flex items-center gap-2">
            <Paperclip class="text-muted-foreground size-4" />
            <h3 class="text-sm font-semibold">Attachments</h3>
            <span class="text-muted-foreground text-xs">{{ rows.length }}</span>
            <Button v-if="canManage" variant="outline" size="sm" class="ml-auto h-8 rounded-lg" :disabled="uploading" @click="pick">
                <Loader2 v-if="uploading" class="size-4 animate-spin" />
                <Upload v-else class="size-4" />
                {{ uploading ? 'Uploading…' : 'Upload' }}
            </Button>
            <input ref="fileInput" type="file" class="hidden" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx,.csv,.txt" @change="onFileChosen" />
        </header>

        <ul v-if="rows.length" class="divide-border/70 divide-y">
            <li v-for="row in rows" :key="row.id" class="flex items-center gap-3 py-2">
                <span class="bg-muted grid size-8 shrink-0 place-items-center rounded-lg">
                    <FileText class="text-muted-foreground size-4" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium">{{ row.name }}</p>
                    <p class="text-muted-foreground text-xs">
                        {{ humanSize(row.size) }} · {{ row.uploader?.name ?? 'Unknown' }}<template v-if="row.created_at"> · {{ formatDate(row.created_at) }}</template>
                    </p>
                </div>
                <a
                    :href="route('core.attachments.download', row.id)"
                    class="hover:bg-accent grid size-8 shrink-0 place-items-center rounded-md"
                    :aria-label="`Download ${row.name}`"
                >
                    <Download class="size-4" />
                </a>
                <button
                    v-if="canManage"
                    type="button"
                    class="hover:bg-destructive/10 hover:text-destructive grid size-8 shrink-0 place-items-center rounded-md"
                    :aria-label="`Remove ${row.name}`"
                    @click="remove(row)"
                >
                    <Trash2 class="size-4" />
                </button>
            </li>
        </ul>
        <p v-else class="text-muted-foreground py-3 text-center text-sm">No documents attached yet.</p>
    </section>
</template>
