<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { KeyRound, Link2, ShieldAlert } from 'lucide-vue-next';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import StaticTable from '@core/Components/StaticTable.vue';
import { confirmDialog } from '@core/Composables/useConfirm';

interface Identity {
    id: number;
    provider: string;
    label: string;
    email: string;
    name: string | null;
    last_login_at: string | null;
}

const props = defineProps<{
    identities: Identity[];
    /** provider key => display label, limited to providers that are configured and not yet linked. */
    available: Record<string, string>;
    enabled: boolean;
    /** True when removing a link would leave the account with a single way in, so the form asks for proof. */
    needsPassword: boolean;
}>();

const pending = ref<number | null>(null);
const proof = ref('');

async function unlink(id: number) {
    // The password field only appears when it is actually required, so the confirm dialog has to say why
    // the next click may bounce: "no" here is a policy about your account, not a failure of the app.
    const ok = await confirmDialog({
        title: 'Remove this linked sign-in?',
        description: props.needsPassword
            ? 'This is the only linked account on this profile. You will be asked for your password, because removing the last way in would strand the account.'
            : 'You will no longer be able to sign in to the ERP with this provider.',
        confirmLabel: 'Remove',
        danger: true,
    });

    if (!ok) return;

    pending.value = id;
    router.delete(route('core.sso.identities.unlink', id), {
        data: proof.value ? { password: proof.value } : {},
        preserveScroll: true,
        onFinish: () => {
            pending.value = null;
            proof.value = '';
        },
    });
}
</script>

<template>
    <AdminLayout :breadcrumbs="[{ title: 'Profile' }, { title: 'Sign-in methods' }]">
        <Head title="Sign-in methods" />
        <PageHeader
            title="Sign-in methods"
            description="Work accounts you may use to sign in to the ERP, alongside your password."
            :icon="KeyRound"
            :stats="[{ label: 'Linked', value: String(identities.length) }]"
        />

        <div v-if="!enabled" class="rounded-xl border bg-card p-5 text-sm">
            <p class="flex items-center gap-2 font-semibold"><ShieldAlert class="size-4 text-amber-500" /> Single sign-on is off</p>
            <p class="mt-1 text-muted-foreground">
                This installation has no identity provider configured, so your password is the only way in. Set
                <code class="font-mono text-xs">SSO_ENABLED</code> and a client id and secret to turn the buttons on.
            </p>
        </div>

        <template v-else>
            <div class="overflow-hidden rounded-xl border bg-card">
                <StaticTable
                    :empty="!identities.length"
                    empty-title="No linked accounts yet"
                    empty-description="Add one below and you can sign in with it instead of typing a password."
                >
                    <template #head>
                        <thead>
                            <tr class="text-xs text-muted-foreground">
                                <th class="text-left">Provider</th>
                                <th class="text-left">Address at the provider</th>
                                <th class="text-left">Last used</th>
                                <th class="text-right">Remove</th>
                            </tr>
                        </thead>
                    </template>
                    <tbody>
                        <tr v-for="i in identities" :key="i.id" class="border-t">
                            <td class="text-sm font-medium">{{ i.label }}</td>
                            <td class="text-sm">
                                {{ i.email }}
                                <span v-if="i.name" class="text-xs text-muted-foreground">· {{ i.name }}</span>
                            </td>
                            <td class="text-xs text-muted-foreground">{{ i.last_login_at ?? 'not used yet' }}</td>
                            <td class="text-right">
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    class="rounded-full text-destructive"
                                    :disabled="pending === i.id"
                                    @click="unlink(i.id)"
                                >
                                    Remove
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </StaticTable>
            </div>

            <!-- Proof of a second way in, asked only when the removal could strand the account. -->
            <div v-if="needsPassword && identities.length" class="mt-4 rounded-xl border bg-card p-5">
                <Label for="sso-proof" class="text-sm font-medium">Confirm your password to remove a link</Label>
                <p class="mt-1 text-xs text-muted-foreground">
                    This account has a single linked sign-in, so the server cannot tell from the database whether a
                    usable password exists. Typing it is the proof; a wrong one removes nothing.
                </p>
                <Input id="sso-proof" v-model="proof" type="password" autocomplete="current-password" class="mt-2 max-w-sm" />
            </div>

            <div v-if="Object.keys(available).length" class="mt-4 rounded-xl border bg-card p-5">
                <h2 class="flex items-center gap-2 text-sm font-semibold"><Link2 class="size-4 text-primary" /> Link another account</h2>
                <p class="mt-1 text-xs text-muted-foreground">
                    You will be sent to the provider and back. If an account already exists here for that address, it is
                    linked to <em>that</em> profile only when you are signed out — from here, the identity is attached to
                    the account you are using.
                </p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <a v-for="(label, key) in available" :key="key" :href="route('core.sso.redirect', key)">
                        <Button size="sm" variant="outline" class="rounded-full">Link {{ label }}</Button>
                    </a>
                </div>
            </div>
        </template>
    </AdminLayout>
</template>
