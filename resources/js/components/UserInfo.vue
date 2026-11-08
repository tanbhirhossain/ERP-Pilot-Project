<script setup lang="ts">
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/composables/useInitials';
import { computed } from 'vue';

/**
 * The three fields this component reads, and no more. Two different user shapes reach it — the starter
 * kit's `User` and CORE's `AuthUser`, which are the two `Authenticatable` subclasses behind finding 53
 * — and a prop typed as the wider one only accepts one of them. `avatar` stays nullable because CORE's
 * shape says so.
 */
interface Props {
    user: { name: string; email: string; avatar?: string | null };
    showEmail?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    showEmail: false,
});

const { getInitials } = useInitials();

// Compute whether we should show the avatar image
const showAvatar = computed(() => props.user.avatar && props.user.avatar !== '');
</script>

<template>
    <Avatar class="h-8 w-8 overflow-hidden rounded-lg">
        <AvatarImage v-if="showAvatar" :src="user.avatar ?? ''" :alt="user.name" />
        <AvatarFallback class="rounded-lg text-black dark:text-white">
            {{ getInitials(user.name) }}
        </AvatarFallback>
    </Avatar>

    <div class="grid flex-1 text-left text-sm leading-tight">
        <span class="truncate font-medium">{{ user.name }}</span>
        <span v-if="showEmail" class="text-muted-foreground truncate text-xs">{{ user.email }}</span>
    </div>
</template>
