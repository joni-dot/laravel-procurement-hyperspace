<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Award,
    CalendarClock,
    FileWarning,
    ShieldAlert,
    Star,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import SupplierController from '@/actions/App/Modules/Srm/Http/Controllers/Suppliers/SupplierController';
import { trans } from '@/lib/i18n';
import type { DashboardAttention } from '@/types/srm';

/**
 * The supplier numbers that need attention. Each opens the supplier list filtered to exactly the
 * suppliers it counts (app/Modules/Srm/Dashboard/SupplierAttention.php). The contract tile only comes
 * for people who may see contract details: the server sends no count for anyone else.
 */
const props = defineProps<{
    attention: DashboardAttention;
}>();

type AttentionTile = {
    name: keyof DashboardAttention;
    label: string;
    description: string;
    icon: Component;
    count: number;
    query: Record<string, string | number>;
};

const tiles = computed<AttentionTile[]>(() => {
    const tiles: AttentionTile[] = [
        {
            name: 'missing_certificates',
            label: trans('Missing certificates'),
            description: trans(
                'Active suppliers without a valid required certificate',
            ),
            icon: FileWarning,
            count: props.attention.missing_certificates,
            query: { missing_certificates: 1 },
        },
        {
            name: 'certificates_expiring',
            label: trans('Certificates expiring'),
            description: trans(
                'Active suppliers with one expired or ending within 30 days',
            ),
            icon: Award,
            count: props.attention.certificates_expiring,
            query: { status: 'active', certificates_expiring: 1 },
        },
        {
            name: 'high_risk',
            label: trans('High risk'),
            description: trans('Active suppliers rated high risk'),
            icon: ShieldAlert,
            count: props.attention.high_risk,
            query: { status: 'active', risk: 'high' },
        },
        {
            name: 'rating_overdue',
            label: trans('Rating overdue'),
            description: trans(
                'Active suppliers not rated in the past 12 months',
            ),
            icon: Star,
            count: props.attention.rating_overdue,
            query: { rating_overdue: 1 },
        },
    ];

    if (props.attention.contracts_action_soon !== null) {
        tiles.unshift({
            name: 'contracts_action_soon',
            label: trans('Contracts to act on'),
            description: trans(
                'Suppliers with a contract ending or due for cancellation within 30 days',
            ),
            icon: CalendarClock,
            count: props.attention.contracts_action_soon,
            query: { contracts_action_soon: 1 },
        });
    }

    return tiles;
});
</script>

<template>
    <div
        class="grid gap-4 sm:grid-cols-2"
        :class="tiles.length === 5 ? 'xl:grid-cols-5' : 'xl:grid-cols-4'"
    >
        <Link
            v-for="tile in tiles"
            :key="tile.name"
            :href="SupplierController.index({}, { query: tile.query })"
            class="flex flex-col gap-2 rounded-xl border p-4 transition-colors hover:bg-muted/30"
            data-test="supplier-tile"
            :data-tile="tile.name"
        >
            <span class="flex items-center gap-2 text-sm font-medium">
                <component
                    :is="tile.icon"
                    class="size-4 shrink-0 text-muted-foreground"
                />
                {{ tile.label }}
            </span>
            <span
                class="text-3xl font-semibold tabular-nums"
                data-test="supplier-tile-count"
            >
                {{ tile.count }}
            </span>
            <span class="text-xs text-muted-foreground">
                {{ tile.description }}
            </span>
        </Link>
    </div>
</template>
