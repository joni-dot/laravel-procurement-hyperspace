<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { home, logout } from '@/routes';

defineOptions({
    layout: {
        title: 'Invitation',
    },
});

defineProps<{
    reason: 'invalid' | 'another-account';
}>();
</script>

<template>
    <Head title="Invitation" />

    <div
        v-if="reason === 'another-account'"
        class="space-y-4 text-center text-sm"
    >
        <p>
            This invitation is for another account. Sign out and open the link
            again.
        </p>
        <Button variant="secondary" as-child>
            <Link :href="logout()" as="button">Sign out</Link>
        </Button>
    </div>

    <div v-else class="space-y-4 text-center text-sm">
        <p>
            This invitation link is invalid or has expired. Ask an admin to send
            you a new one.
        </p>
        <TextLink :href="home()">Continue</TextLink>
    </div>
</template>
