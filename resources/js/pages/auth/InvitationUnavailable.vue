<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { home, logout } from '@/routes';
import { trans } from '@/lib/i18n';

defineOptions({
    layout: () => ({
        title: trans('Invitation'),
    }),
});

defineProps<{
    reason: 'invalid' | 'another-account';
}>();
</script>

<template>
    <Head :title="$t('Invitation')" />

    <div
        v-if="reason === 'another-account'"
        class="space-y-4 text-center text-sm"
    >
        <p>
            {{
                $t(
                    'This invitation is for another account. Sign out and open the link again.',
                )
            }}
        </p>
        <Button variant="secondary" as-child>
            <Link :href="logout()" as="button">{{ $t('Sign out') }}</Link>
        </Button>
    </div>

    <div v-else class="space-y-4 text-center text-sm">
        <p>
            {{
                $t(
                    'This invitation link is invalid or has expired. Ask an admin to send you a new one.',
                )
            }}
        </p>
        <TextLink :href="home()">{{ $t('Continue') }}</TextLink>
    </div>
</template>
