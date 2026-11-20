<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { BadgeVariants } from '@/components/ui/badge';
import type { MembershipStatus } from '@/types';

defineProps<{
    status: MembershipStatus;
}>();

const appearance: Record<
    MembershipStatus,
    { label: string; variant: BadgeVariants['variant'] }
> = {
    active: { label: 'Active', variant: 'secondary' },
    invited: { label: 'Invited', variant: 'outline' },
    invitation_expired: { label: 'Invitation expired', variant: 'outline' },
    deactivated: { label: 'Deactivated', variant: 'destructive' },
};
</script>

<template>
    <Badge :variant="appearance[status].variant">
        {{ appearance[status].label }}
    </Badge>
</template>
