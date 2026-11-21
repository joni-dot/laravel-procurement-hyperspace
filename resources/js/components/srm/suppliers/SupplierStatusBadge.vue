<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { BadgeVariants } from '@/components/ui/badge';
import type { SupplierStatus } from '@/types/srm';
import { trans } from '@/lib/i18n';

defineProps<{
    status: SupplierStatus;
}>();

const appearance: Record<
    SupplierStatus,
    { label: () => string; variant: BadgeVariants['variant'] }
> = {
    prospect: { label: () => trans('Prospect'), variant: 'outline' },
    active: { label: () => trans('Active'), variant: 'secondary' },
    inactive: { label: () => trans('Inactive'), variant: 'outline' },
    blocked: { label: () => trans('Blocked'), variant: 'destructive' },
};
</script>

<template>
    <Badge :variant="appearance[status].variant">
        {{ appearance[status].label() }}
    </Badge>
</template>
