<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, ShieldCheck, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import DataTable, { type DataTableColumn } from '../../Components/DataTable.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { useAcl } from '../../Composables/useAcl';
import type { FilterDef, Paginator, RoleRow } from '../../types';
import { formatDate, formatNumber } from '../../Utils/format';

interface Props {
    roles: Paginator<RoleRow>;
    filterDefs?: FilterDef[];
}

const props = withDefaults(defineProps<Props>(), { filterDefs: () => [] });
const { can } = useAcl();

const columns: DataTableColumn[] = [
    { key: 'name', label: 'Role', sortable: true },
    { key: 'users_count', label: 'Members', sortable: false, align: 'right' },
    { key: 'permissions_count', label: 'Permissions', sortable: false, align: 'right' },
    { key: 'created_at', label: 'Created', sortable: true, width: '11rem' },
    { key: 'actions', label: '', align: 'right', exportable: false, width: '1rem' },
];

const confirmOpen = ref(false);
const pendingRole = ref<RoleRow | null>(null);
const processing = ref(false);

function askDelete(row: Record<string, unknown>) {
    pendingRole.value = row as unknown as RoleRow;
    confirmOpen.value = true;
}

function confirmDelete() {
    if (!pendingRole.value) return;

    processing.value = true;

    router.delete(route('core.roles.destroy', pendingRole.value.id), {
        preserveScroll: true,
        onFinish: () => {
            processing.value = false;
            confirmOpen.value = false;
            pendingRole.value = null;
        },
    });
}
</script>

<template>
    <AdminLayout :breadcrumbs="[{ title: 'Access Control' }, { title: 'Roles' }]">
        <Head title="Roles" />

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between print:hidden">
            <div>
                <h1 class="text-xl font-bold tracking-tight">Roles</h1>
                <p class="mt-0.5 text-sm text-muted-foreground">
                    Bundle permissions into reusable access profiles.
                </p>
            </div>
            <Link v-if="can('roles.create')" :href="route('core.roles.create')">
                <Button class="h-9 shadow-md shadow-primary/20">
                    <Plus class="size-4" />
                    New role
                </Button>
            </Link>
        </div>

        <DataTable
            route-name="core.roles.index"
            export-resource="roles"
            title="Roles"
            search-placeholder="Search roles…"
            :columns="columns"
            :rows="props.roles.data"
            :meta="props.roles.meta"
            :filter-defs="filterDefs"
            empty-title="No roles match this view"
            empty-description="Try a different search or clear the permission filter."
        >
            <template #cell-name="{ row }">
                <div class="flex items-center gap-3">
                    <span class="grid size-9 shrink-0 place-items-center rounded-lg border bg-gradient-to-br from-sky-500/15 to-cyan-500/15 text-sky-600 dark:text-sky-400">
                        <ShieldCheck class="size-4.5" />
                    </span>
                    <div class="min-w-0">
                        <p class="truncate font-semibold leading-tight">{{ row.name }}</p>
                        <p v-if="row.name === 'Super Admin'" class="text-xs text-muted-foreground">
                            Wildcard · protected
                        </p>
                    </div>
                </div>
            </template>

            <template #cell-users_count="{ row }">
                <span class="font-medium tabular-nums">{{ formatNumber(Number(row.users_count)) }}</span>
            </template>

            <template #cell-permissions_count="{ row }">
                <span
                    class="inline-flex items-center rounded-md border bg-muted/50 px-2 py-0.5 text-xs font-medium tabular-nums"
                    :class="Number(row.permissions_count) > 0 ? 'text-foreground' : 'text-muted-foreground'"
                >
                    {{ formatNumber(Number(row.permissions_count)) }}
                </span>
            </template>

            <template #cell-created_at="{ row }">
                <span class="whitespace-nowrap text-sm text-muted-foreground">{{ formatDate(row.created_at) }}</span>
            </template>

            <template #cell-actions="{ row }">
                <div v-if="can('roles.update') || can('roles.delete')" class="flex items-center justify-end gap-1">
                    <Link
                        v-if="can('roles.update')"
                        :href="route('core.roles.edit', row.id)"
                        class="inline-flex size-8 items-center justify-center rounded-md text-muted-foreground transition hover:bg-accent hover:text-accent-foreground"
                        :aria-label="`Edit ${row.name}`"
                        title="Edit"
                    >
                        <Pencil class="size-4" />
                    </Link>
                    <button
                        v-if="can('roles.delete')"
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
            title="Delete this role?"
            :description="`The role ${pendingRole?.name ?? ''} will be removed and detached from every user that currently holds it.`"
            confirm-label="Delete role"
            @confirm="confirmDelete"
        />
    </AdminLayout>
</template>
