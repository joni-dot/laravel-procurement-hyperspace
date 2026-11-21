<script setup lang="ts">
import { computed } from 'vue';
import { riskBadge } from '@/lib/ppr/packageBadges';
import type { RiskLevel } from '@/types/ppr';

const props = defineProps<{
    level: Exclude<RiskLevel, 'none'>;
}>();

const badge = computed(() => riskBadge(props.level));
</script>

<template>
    <span data-slot="badge" data-test="risk-badge" :class="badge.className">
        {{ badge.label }}
    </span>
</template>
