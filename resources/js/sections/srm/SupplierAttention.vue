<script setup lang="ts">
import HomeSectionHeading from '@/components/HomeSectionHeading.vue';
import AttentionTiles from '@/components/srm/dashboard/AttentionTiles.vue';
import { dashboard } from '@/routes';
import type { DashboardAttention } from '@/types/srm';

/**
 * The supplier dashboard's attention tiles; each opens the supplier list behind its number.
 */
defineProps<{
    data: DashboardAttention;
}>();
</script>

<template>
    <section
        class="flex flex-col gap-3"
        aria-labelledby="home-supplier-attention-heading"
    >
        <HomeSectionHeading
            id="home-supplier-attention-heading"
            :title="$t('Suppliers needing attention')"
            :link-label="$t('Supplier dashboard')"
            :href="dashboard()"
        >
            <p class="text-sm text-muted-foreground">
                {{ $t('Each number opens the list of suppliers behind it') }}
            </p>
        </HomeSectionHeading>
        <AttentionTiles :attention="data" />
    </section>
</template>
