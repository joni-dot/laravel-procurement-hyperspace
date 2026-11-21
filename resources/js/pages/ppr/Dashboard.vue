<script setup lang="ts">
import { Deferred, Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import ActionPeriodNote from '@/components/ppr/dashboard/ActionPeriodNote.vue';
import NextActionList from '@/components/ppr/dashboard/NextActionList.vue';
import NextActionListSkeleton from '@/components/ppr/dashboard/NextActionListSkeleton.vue';
import ProjectAttentionList from '@/components/ppr/dashboard/ProjectAttentionList.vue';
import { trans } from '@/lib/i18n';
import { dashboard } from '@/routes/projects';
import { index as projectsIndex } from '@/routes/projects';
import type {
    DashboardAttention,
    DashboardProject,
    DashboardTiles,
} from '@/types/ppr';

defineOptions({
    layout: () => ({
        breadcrumbs: [
            {
                title: trans('Project dashboard'),
                href: dashboard(),
            },
        ],
    }),
});

const props = defineProps<{
    // Only the projects the person is a member of, admins included.
    projects: DashboardProject[];
    notificationDays: number;
    can: { seeAllProjects: boolean };
    // Deferred: missing while the page first loads; see DashboardController.
    attention?: DashboardAttention;
}>();

const tilesByProject = computed(() =>
    props.attention === undefined
        ? undefined
        : new Map<number, DashboardTiles>(
              props.attention.tiles.map((row) => [row.projectId, row.tiles]),
          ),
);
</script>

<template>
    <Head :title="$t('Project dashboard')" />

    <div class="flex max-w-5xl flex-col gap-8 p-4">
        <h1 class="text-xl font-semibold tracking-tight">
            {{ $t('Project dashboard') }}
        </h1>

        <div
            v-if="projects.length === 0"
            class="rounded-xl border border-dashed p-6 text-sm"
            data-test="no-projects"
        >
            <p class="font-medium">
                {{ $t("You're not in any project yet.") }}
            </p>
            <p class="text-muted-foreground">
                <Link
                    v-if="can.seeAllProjects"
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

        <template v-else>
            <section
                class="flex flex-col gap-3"
                aria-labelledby="next-actions-heading"
            >
                <div class="space-y-0.5">
                    <h2 id="next-actions-heading" class="text-lg font-semibold">
                        {{ $t('My next actions') }}
                    </h2>
                    <ActionPeriodNote :notification-days="notificationDays" />
                </div>

                <Deferred data="attention">
                    <template #fallback>
                        <NextActionListSkeleton />
                    </template>
                    <NextActionList
                        v-if="attention"
                        :actions="attention.actions"
                        :total="attention.actionsTotal"
                    />
                </Deferred>
            </section>

            <section
                class="flex flex-col gap-3"
                aria-labelledby="my-projects-heading"
            >
                <h2 id="my-projects-heading" class="text-lg font-semibold">
                    {{ $t('My projects') }}
                </h2>
                <ProjectAttentionList
                    :projects="projects"
                    :tiles-by-project="tilesByProject"
                />
            </section>
        </template>
    </div>
</template>
