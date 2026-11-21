<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { BadgeVariants } from '@/components/ui/badge';
import type { CertificateStatus } from '@/types/srm';
import { trans } from '@/lib/i18n';

defineProps<{
    status: CertificateStatus;
}>();

const appearance: Record<
    CertificateStatus,
    { label: () => string; variant: BadgeVariants['variant'] }
> = {
    valid: { label: () => trans('Valid'), variant: 'secondary' },
    expiring_soon: { label: () => trans('Expiring soon'), variant: 'outline' },
    expired: { label: () => trans('Expired'), variant: 'destructive' },
};
</script>

<template>
    <Badge :variant="appearance[status].variant">
        {{ appearance[status].label() }}
    </Badge>
</template>
