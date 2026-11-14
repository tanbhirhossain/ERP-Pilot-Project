<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Check, Languages } from 'lucide-vue-next';

import { useLocale } from '../Composables/useLocale';

/**
 * Language switcher — U19. Sits in the shell header beside the colour-scheme picker and behaves exactly
 * like it: pick a language, the session remembers it, the layout direction follows for free.
 *
 * It shows only when more than one language is available, so a deployment that has not localised past
 * English gets no dead control.
 */
const { code, locales, t, setLocale } = useLocale();
</script>

<template>
    <DropdownMenu v-if="locales.length > 1">
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="icon"
                class="size-8 rounded-full text-muted-foreground hover:bg-accent"
                :aria-label="t('app.language')"
            >
                <Languages class="size-4" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-44 rounded-xl p-1.5 shadow-xl">
            <p class="px-2 pb-1 pt-1.5 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                {{ t('app.language') }}
            </p>
            <DropdownMenuItem
                v-for="option in locales"
                :key="option.id"
                class="gap-2 rounded-lg px-2 py-1.5 text-[13px] cursor-pointer"
                :class="option.id === code ? 'bg-accent font-semibold text-foreground' : 'text-muted-foreground'"
                @select.prevent="setLocale(option.id)"
            >
                <span class="truncate">{{ option.native }}</span>
                <span v-if="option.id !== code" class="ml-auto text-[11px] opacity-60">{{ option.label }}</span>
                <Check v-else class="ml-auto size-3.5 shrink-0 text-primary" />
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
