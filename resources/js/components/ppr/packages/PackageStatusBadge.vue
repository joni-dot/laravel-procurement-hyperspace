<script setup lang="ts">
import { computed } from 'vue';
import { statusBadge } from '@/lib/ppr/packageBadges';
import type { PackageStatus } from '@/types/ppr';

const props = defineProps<{
    status: PackageStatus;
}>();

const badge = computed(() => statusBadge(props.status));
</script>

<template>
    <span data-slot="badge" :class="badge.className">{{ badge.label }}</span>
</template>
