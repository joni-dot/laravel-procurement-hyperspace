<script setup lang="ts">
import HomeSectionHeading from '@/components/HomeSectionHeading.vue';
import ActionPeriodNote from '@/components/ppr/dashboard/ActionPeriodNote.vue';
import NextActionList from '@/components/ppr/dashboard/NextActionList.vue';
import { dashboard } from '@/routes/projects';
import type { HomeNextActions } from '@/types/ppr';

/**
 * The most urgent of the person's next actions, as on the project dashboard, which lists more.
 */
defineProps<{
    data: HomeNextActions;
}>();
</script>

<template>
    <section
        class="flex flex-col gap-3"
        aria-labelledby="home-next-actions-heading"
    >
        <HomeSectionHeading
            id="home-next-actions-heading"
            :title="$t('My next actions')"
            :link-label="$t('Project dashboard')"
            :href="dashboard()"
        >
            <ActionPeriodNote :notification-days="data.notificationDays" />
        </HomeSectionHeading>
        <NextActionList :actions="data.actions" :total="data.actionsTotal" />
    </section>
</template>
