<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { InertiaLinkProps } from '@inertiajs/vue3';

/**
 * The heading of a section on the Home page, with a link to the module page that shows all of it.
 */
defineProps<{
    id: string;
    title: string;
    linkLabel: string;
    href: NonNullable<InertiaLinkProps['href']>;
}>();
</script>

<template>
    <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-1">
        <div class="space-y-0.5">
            <h2 :id="id" class="text-lg font-semibold">{{ title }}</h2>
            <slot />
        </div>
        <Link
            :href="href"
            class="text-sm text-muted-foreground underline underline-offset-4 hover:text-foreground"
            data-test="section-dashboard-link"
        >
            {{ linkLabel }}
        </Link>
    </div>
</template>
