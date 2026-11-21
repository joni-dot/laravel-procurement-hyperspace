<script setup lang="ts">
import HomeSectionHeading from '@/components/HomeSectionHeading.vue';
import UpcomingDeadlineTable from '@/components/srm/dashboard/UpcomingDeadlineTable.vue';
import { dashboard } from '@/routes';
import type { UpcomingDeadline } from '@/types/srm';

/**
 * The soonest supplier dates, as on the supplier dashboard.
 */
defineProps<{
    data: UpcomingDeadline[];
}>();
</script>

<template>
    <section
        class="flex flex-col gap-3"
        aria-labelledby="home-upcoming-dates-heading"
    >
        <HomeSectionHeading
            id="home-upcoming-dates-heading"
            :title="$t('Supplier dates in the next 30 days')"
            :link-label="$t('Supplier dashboard')"
            :href="dashboard()"
        >
            <p class="text-sm text-muted-foreground">
                {{ $t('The 10 soonest contract and certificate dates') }}
            </p>
        </HomeSectionHeading>
        <UpcomingDeadlineTable :upcoming="data" />
    </section>
</template>
