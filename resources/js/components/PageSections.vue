<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import type { Component } from 'vue';
import { Skeleton } from '@/components/ui/skeleton';
import type { PageSection } from '@/types/sections';

/**
 * Draws the sections modules added to this page (App\Pages\PageSections). Each section component gets its
 * data as the "data" prop: the page prop "section_{key}". Data that hasn't arrived yet (a deferred section)
 * shows a placeholder.
 */
defineProps<{ sections: PageSection[] }>();

// Eager, so a section draws in the same render as its page instead of popping in afterwards.
const components = import.meta.glob<Component>('../sections/**/*.vue', {
    eager: true,
    import: 'default',
});

const page = usePage();

function componentFor(section: PageSection): Component {
    const component = components[`../sections/${section.component}.vue`];

    if (component === undefined) {
        throw new Error(`No section component "${section.component}".`);
    }

    return component;
}

function dataOf(section: PageSection): unknown {
    return (page.props as Record<string, unknown>)[`section_${section.key}`];
}
</script>

<template>
    <template v-for="section in sections" :key="section.key">
        <Skeleton
            v-if="dataOf(section) === undefined"
            class="h-32 w-full rounded-xl"
            :data-test="`section-${section.key}-loading`"
        />
        <component
            :is="componentFor(section)"
            v-else
            :data="dataOf(section)"
            :data-test="`section-${section.key}`"
        />
    </template>
</template>
