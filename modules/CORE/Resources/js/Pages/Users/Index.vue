<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2, UserCheck, UserX } from 'lucide-vue-next';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import DataTable, { type DataTableColumn } from '../../Components/DataTable.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { useAcl } from '../../Composables/useAcl';
import type { FilterDef, Paginator, UserRow } from '../../types';
import { avatarTone, formatDate, initials } from '../../Utils/format';

interface Props {
    users: Paginator<UserRow>;
    filterDefs?: FilterDef[];
}

const props = withDefaults(defineProps<Props>(), { filterDefs: () => [] });
const { can } = useAcl();

const columns: DataTableColumn[] = [
    { key: 'name', label: 'User', sortable: true, width: '24%' },
    { key: 'email', label: 'Email', sortable: true },
    { key: 'roles', label: 'Roles' },
    { key: 'verified', label: 'Status', sortable: true, align: 'center' },
    { key: 'created_at', label: 'Joined', sortable: true, width: '11rem' },
    { key: 'actions', label: '', align: 'right', exportable: false, width: '1rem' },
];

/* ------------------------------- delete flow ----------------------------- */
const confirmOpen = ref(false);
const pendingUser = ref<UserRow | null>(null);
const processing = ref(false);

function askDelete(row: Record<string, unknown>) {
    pendingUser.value = row as unknown as UserRow;
    confirmOpen.value = true;
}

function confirmDelete() {
    if (!pendingUser.value) return;

    processing.value = true;

    router.delete(route('core.users.destroy', pendingUser.value.id), {
        preserveScroll: true,
        onFinish: () => {
            processing.value = false;
            confirmOpen.value = false;
            pendingUser.value = null;
        },
    });
}
</script>

<template>
    <AdminLayout :breadcrumbs="[{ title: 'Access Control' }, { title: 'Users' }]">
        <Head title="Users" />

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between print:hidden">
            <div>
                <h1 class="text-xl font-bold tracking-tight">Users</h1>
                <p class="mt-0.5 text-sm text-muted-foreground">
                    Manage identities, assignments and account status.
                </p>
            </div>
            <Link v-if="can('users.create')" :href="route('core.users.create')">
                <Button class="h-9 shadow-md shadow-primary/20">
                    <Plus class="size-4" />
                    New user
                </Button>
            </Link>
        </div>

        <DataTable
            route-name="core.users.index"
            export-resource="users"
            title="Users"
            search-placeholder="Search by name or email…"
            :columns="columns"
            :rows="props.users.data"
            :meta="props.users.meta"
            :filter-defs="filterDefs"
            empty-title="No users match this view"
            empty-description="Adjust the search, role or status filters to widen the results."
        >
            <template #cell-name="{ row }">
                <div class="flex items-center gap-3">
                    <span
                        class="grid size-9 shrink-0 place-items-center rounded-lg bg-gradient-to-br text-xs font-bold text-white shadow-sm"
                        :class="avatarTone(String(row.name))"
                    >
                        {{ initials(String(row.name)) }}
                    </span>
                    <div class="min-w-0">
                        <p class="truncate font-semibold leading-tight">{{ row.name }}</p>
                        <p class="truncate text-xs text-muted-foreground md:hidden">{{ row.email }}</p>
                    </div>
                </div>
            </template>

            <template #cell-email="{ row }">
                <span class="text-muted-foreground">{{ row.email }}</span>
            </template>

            <template #cell-roles="{ row }">
                <div class="flex flex-wrap gap-1.5">
                    <span
                        v-for="role in (row.roles || [])"
                        :key="role.id"
                        class="inline-flex items-center rounded-md border border-violet-500/30 bg-violet-500/10 px-2 py-0.5 text-xs font-medium text-violet-600 dark:text-violet-400"
                    >
                        {{ role.name }}
                    </span>
                    <span v-if="!row.roles.length" class="text-xs text-muted-foreground">No role</span>
                </div>
            </template>

            <template #cell-verified="{ row }">
                <span
                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium"
                    :class="
                        row.verified
                            ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                            : 'bg-amber-500/10 text-amber-600 dark:text-amber-400'
                    "
                >
                    <UserCheck v-if="row.verified" class="size-3.5" />
                    <UserX v-else class="size-3.5" />
                    {{ row.verified ? 'Verified' : 'Unverified' }}
                </span>
            </template>

            <template #cell-created_at="{ row }">
                <span class="whitespace-nowrap text-sm text-muted-foreground">{{ formatDate(row.created_at) }}</span>
            </template>

            <template #cell-actions="{ row }">
                <div v-if="can('users.update') || can('users.delete')" class="flex items-center justify-end gap-1">
                    <Link
                        v-if="can('users.update')"
                        :href="route('core.users.edit', row.id)"
                        class="inline-flex size-8 items-center justify-center rounded-md text-muted-foreground transition hover:bg-accent hover:text-accent-foreground"
                        :aria-label="`Edit ${row.name}`"
                        title="Edit"
                    >
                        <Pencil class="size-4" />
                    </Link>
                    <button
                        v-if="can('users.delete')"
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
            title="Delete this user?"
            :description="`You are about to permanently delete ${pendingUser?.name ?? ''} (${pendingUser?.email ?? ''}). Their audit history is preserved, but access is revoked immediately.`"
            confirm-label="Delete user"
            @confirm="confirmDelete"
        />
    </AdminLayout>
</template>
