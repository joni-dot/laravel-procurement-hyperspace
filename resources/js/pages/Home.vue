<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PageSections from '@/components/PageSections.vue';
import { trans } from '@/lib/i18n';
import { home } from '@/routes/company';
import type { PageSection } from '@/types/sections';

defineOptions({
    layout: () => ({
        breadcrumbs: [
            {
                title: trans('Home'),
                href: home(),
            },
        ],
    }),
});

// Each module adds its own sections, only for the people they are useful for (App\Pages\PageSections).
defineProps<{
    sections: PageSection[];
}>();
</script>

<template>
    <Head :title="$t('Home')" />

    <div class="flex max-w-6xl flex-col gap-10 p-4">
        <h1 class="text-xl font-semibold tracking-tight">{{ $t('Home') }}</h1>

        <PageSections :sections="sections" />
    </div>
</template>
