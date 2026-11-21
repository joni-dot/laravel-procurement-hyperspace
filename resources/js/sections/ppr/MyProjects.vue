<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import HomeSectionHeading from '@/components/HomeSectionHeading.vue';
import ProjectAttentionList from '@/components/ppr/dashboard/ProjectAttentionList.vue';
import { dashboard, index as projectsIndex } from '@/routes/projects';
import type { DashboardTiles, HomeProjects } from '@/types/ppr';

/**
 * One row of numbers per project the person is a member of, as on the project dashboard. An admin in
 * no project gets a link to every project instead.
 */
const props = defineProps<{
    data: HomeProjects;
}>();

const tilesByProject = computed(
    () =>
        new Map<number, DashboardTiles>(
            props.data.projects.map((project) => [project.id, project.tiles]),
        ),
);
</script>

<template>
    <section
        class="flex flex-col gap-3"
        aria-labelledby="home-projects-heading"
    >
        <HomeSectionHeading
            id="home-projects-heading"
            :title="$t('My projects')"
            :link-label="$t('Project dashboard')"
            :href="dashboard()"
        />

        <div
            v-if="data.projects.length === 0"
            class="rounded-xl border border-dashed p-6 text-sm"
            data-test="no-projects"
        >
            <p class="font-medium">
                {{ $t("You're not in any project yet.") }}
            </p>
            <p class="text-muted-foreground">
                <Link
                    v-if="data.canSeeAllProjects"
                    :href="projectsIndex()"
                    class="underline underline-offset-4 hover:text-foreground"
                >
                    {{ $t('Go to the Projects page') }}
                </Link>
                <template v-else>
                    {{ $t('A project manager or admin can add you.') }}
                </template>
            </p>
        </div>

        <ProjectAttentionList
            v-else
            :projects="data.projects"
            :tiles-by-project="tilesByProject"
        />
    </section>
</template>
