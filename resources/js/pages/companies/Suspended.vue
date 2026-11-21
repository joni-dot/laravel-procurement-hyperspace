<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { home } from '@/routes';

defineProps<{
    companyName: string;
}>();
</script>

<template>
    <Head :title="$t('Company suspended')" />

    <div class="flex flex-col gap-4 p-4">
        <Heading
            :title="$t(':company is suspended', { company: companyName })"
            :description="
                $t(
                    'Its data is kept, but nobody can open the company until it is reopened. Contact the service if you think this is a mistake.',
                )
            "
        />
        <Link :href="home()" class="text-sm underline underline-offset-4">
            {{ $t('Go to your other companies') }}
        </Link>
    </div>
</template>
