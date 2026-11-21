<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { BadgeVariants } from '@/components/ui/badge';
import type { RiskLevel } from '@/types/srm';
import { trans } from '@/lib/i18n';

defineProps<{
    level: RiskLevel;
}>();

const appearance: Record<
    RiskLevel,
    { label: () => string; variant: BadgeVariants['variant'] }
> = {
    low: { label: () => trans('Low'), variant: 'secondary' },
    medium: { label: () => trans('Medium'), variant: 'default' },
    high: { label: () => trans('High'), variant: 'destructive' },
};
</script>

<template>
    <Badge :variant="appearance[level].variant">
        {{ appearance[level].label() }}
    </Badge>
</template>
