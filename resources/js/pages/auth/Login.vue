<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    status?: string;
    canResetPassword: boolean;
    /** Shared globally by HandleInertiaRequests; false unless registration is enabled. */
    canRegister?: boolean;
    /** provider key => label, limited to providers that are enabled *and* fully configured. */
    ssoProviders?: Record<string, string>;
    /** Why a provider said no, carried across the redirect so the answer appears where it belongs. */
    ssoError?: string;
}>();

// An empty object is still truthy in JS, so the separator has to be gated on the entry list, not on the
// prop's presence — otherwise every installation with SSO off gets a stray "or".
const providers = computed(() => Object.entries(props.ssoProviders ?? {}));

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <AuthBase title="Log in to your account" description="Enter your email and password below to log in">
        <Head title="Log in" />

        <div v-if="status" class="mb-4 text-center text-sm font-medium text-green-600">
            {{ status }}
        </div>

        <form @submit.prevent="submit" class="flex flex-col gap-6">
            <div class="grid gap-6">
                <div class="grid gap-2">
                    <Label for="email">Email address</Label>
                    <Input
                        id="email"
                        type="email"
                        required
                        autofocus
                        tabindex="1"
                        autocomplete="email"
                        v-model="form.email"
                        placeholder="email@example.com"
                    />
                    <InputError :message="form.errors.email" />
                </div>

                <div class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <Label for="password">Password</Label>
                        <TextLink v-if="canResetPassword" :href="route('password.request')" class="text-sm" tabindex="5"> Forgot password? </TextLink>
                    </div>
                    <Input
                        id="password"
                        type="password"
                        required
                        tabindex="2"
                        autocomplete="current-password"
                        v-model="form.password"
                        placeholder="Password"
                    />
                    <InputError :message="form.errors.password" />
                </div>

                <div class="flex items-center justify-between" tabindex="3">
                    <Label for="remember" class="flex items-center space-x-3">
                        <Checkbox id="remember" v-model:checked="form.remember" tabindex="4" />
                        <span>Remember me</span>
                    </Label>
                </div>

                <Button type="submit" class="mt-4 w-full" tabindex="4" :disabled="form.processing">
                    <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                    Log in
                </Button>
            </div>

            <div v-if="canRegister" class="text-muted-foreground text-center text-sm">
                Don't have an account?
                <TextLink :href="route('register')" :tabindex="5">Sign up</TextLink>
            </div>
        </form>

        <!--
            SSO. Real anchors, not <Link>: `core.sso.redirect` answers with a 302 to a third-party origin,
            and an Inertia XHR visit cannot follow that — the browser blocks the cross-origin fetch and the
            user sees a spinner. A plain navigation lets the redirect chain run.
        -->
        <div v-if="providers.length" class="grid gap-3">
            <div class="flex items-center gap-3">
                <span class="h-px flex-1 bg-border" />
                <span class="text-muted-foreground text-xs uppercase tracking-wide">or</span>
                <span class="h-px flex-1 bg-border" />
            </div>

            <a v-for="[key, label] in providers" :key="key" :href="route('core.sso.redirect', key)" class="block">
                <Button type="button" variant="outline" class="w-full" tabindex="6">
                    Continue with {{ label }}
                </Button>
            </a>

        </div>

        <!-- Outside the provider block on purpose: a refusal can arrive when nothing is configured any
             more (or the domain gate rejected a valid identity), and the reason must still be shown. -->
        <p v-if="ssoError" class="text-destructive text-center text-sm">{{ ssoError }}</p>
    </AuthBase>
</template>
