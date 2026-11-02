<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { KeyRound, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import DataTable, { type DataTableColumn } from '../../Components/DataTable.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { useAcl } from '../../Composables/useAcl';
import type { FilterDef, Paginator, PermissionRow } from '../../types';
import { formatDate, formatNumber } from '../../Utils/format';

interface Props {
    permissions: Paginator<PermissionRow>;
    filterDefs?: FilterDef[];
}

const props = withDefaults(defineProps<Props>(), { filterDefs: () => [] });
const { can } = useAcl();

const columns: DataTableColumn[] = [
    { key: 'name', label: 'Permission', sortable: true },
    { key: 'group', label: 'Group' },
    { key: 'guard_name', label: 'Guard', sortable: true },
    { key: 'roles_count', label: 'Roles', align: 'right' },
    { key: 'created_at', label: 'Created', sortable: true, width: '11rem' },
    { key: 'actions', label: '', align: 'right', exportable: false, width: '1rem' },
];

const confirmOpen = ref(false);
const pending = ref<PermissionRow | null>(null);
const processing = ref(false);

function askDelete(row: Record<string, unknown>) {
    pending.value = row as unknown as PermissionRow;
    confirmOpen.value = true;
}

function confirmDelete() {
    if (!pending.value) return;

    processing.value = true;

    router.delete(route('core.permissions.destroy', pending.value.id), {
        preserveScroll: true,
        onFinish: () => {
            processing.value = false;
            confirmOpen.value = false;
            pending.value = null;
        },
    });
}
</script>

<template>
    <AdminLayout :breadcrumbs="[{ title: 'Access Control' }, { title: 'Permissions' }]">
        <Head title="Permissions" />

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between print:hidden">
            <div>
                <h1 class="text-xl font-bold tracking-tight">Permissions</h1>
                <p class="mt-0.5 text-sm text-muted-foreground">
                    Atomic capabilities using the <code class="rounded bg-muted px-1.5 py-0.5 text-xs">resource.action</code> convention.
                </p>
            </div>
            <Link v-if="can('permissions.create')" :href="route('core.permissions.create')">
                <Button class="h-9 shadow-md shadow-primary/20">
                    <Plus class="size-4" />
                    New permission
                </Button>
            </Link>
        </div>

        <DataTable
            route-name="core.permissions.index"
            export-resource="permissions"
            title="Permissions"
            search-placeholder="Search permissions…"
            :columns="columns"
            :rows="props.permissions.data"
            :meta="props.permissions.meta"
            :filter-defs="filterDefs"
            empty-title="No permissions match this view"
            empty-description="Adjust the search or group filter to widen the results."
        >
            <template #cell-name="{ row }">
                <div class="flex items-center gap-2.5">
                    <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400">
                        <KeyRound class="size-4" />
                    </span>
                    <code class="rounded-md bg-muted px-2 py-1 font-mono text-[13px] font-medium">{{ row.name }}</code>
                </div>
            </template>

            <template #cell-group="{ row }">
                <span class="inline-flex items-center rounded-full border bg-background px-2.5 py-1 text-xs font-medium">
                    {{ row.group }}
                </span>
            </template>

            <template #cell-guard_name="{ row }">
                <span class="text-sm text-muted-foreground">{{ row.guard_name }}</span>
            </template>

            <template #cell-roles_count="{ row }">
                <span class="tabular-nums">{{ formatNumber(Number(row.roles_count)) }}</span>
            </template>

            <template #cell-created_at="{ row }">
                <span class="whitespace-nowrap text-sm text-muted-foreground">{{ formatDate(row.created_at) }}</span>
            </template>

            <template #cell-actions="{ row }">
                <div v-if="can('permissions.update') || can('permissions.delete')" class="flex items-center justify-end gap-1">
                    <Link
                        v-if="can('permissions.update')"
                        :href="route('core.permissions.edit', row.id)"
                        class="inline-flex size-8 items-center justify-center rounded-md text-muted-foreground transition hover:bg-accent hover:text-accent-foreground"
                        :aria-label="`Edit ${row.name}`"
                        title="Edit"
                    >
                        <Pencil class="size-4" />
                    </Link>
                    <button
                        v-if="can('permissions.delete')"
                        type="button"
                        class="inline-flex size-8 items-center justify-center rounded-md text-muted-foreground transition hover:bg-destructive/10 hover:text-destructive"
                        :aria-label="`Delete ${row.name}`"
                        title="Delete"
                        @click="askDelete(row)"
                    >
                        <Trash2 class="size-4" />
                    </button>
                </div>
            </template>
        </DataTable>

        <ConfirmDialog
            v-model:open="confirmOpen"
            :processing="processing"
            title="Delete this permission?"
            :description="`Permissions that are still assigned to roles or users cannot be deleted. ${pending?.name ?? ''} will be removed permanently if unused.`"
            confirm-label="Delete permission"
            @confirm="confirmDelete"
        />
    </AdminLayout>
</template>
