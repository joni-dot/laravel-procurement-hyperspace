<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { BadgeVariants } from '@/components/ui/badge';
import type { MembershipStatus } from '@/types';
import { trans } from '@/lib/i18n';

defineProps<{
    status: MembershipStatus;
}>();

const appearance: Record<
    MembershipStatus,
    { label: () => string; variant: BadgeVariants['variant'] }
> = {
    active: { label: () => trans('Active'), variant: 'secondary' },
    invited: { label: () => trans('Invited'), variant: 'outline' },
    invitation_expired: {
        label: () => trans('Invitation expired'),
        variant: 'outline',
    },
    deactivated: { label: () => trans('Deactivated'), variant: 'destructive' },
};
</script>

<template>
    <Badge :variant="appearance[status].variant">
        {{ appearance[status].label() }}
    </Badge>
</template>
