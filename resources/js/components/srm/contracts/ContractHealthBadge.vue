<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { BadgeVariants } from '@/components/ui/badge';
import type { ContractHealth } from '@/types/srm';
import { trans } from '@/lib/i18n';

defineProps<{
    health: ContractHealth;
}>();

const appearance: Record<
    ContractHealth,
    { label: () => string; variant: BadgeVariants['variant'] }
> = {
    draft: { label: () => trans('Draft'), variant: 'outline' },
    active: { label: () => trans('Active'), variant: 'secondary' },
    action_soon: {
        label: () => trans('Action needed soon'),
        variant: 'default',
    },
    expired: { label: () => trans('Expired'), variant: 'destructive' },
    terminated: { label: () => trans('Terminated'), variant: 'outline' },
};
</script>

<template>
    <Badge :variant="appearance[health].variant">
        {{ appearance[health].label() }}
    </Badge>
</template>
