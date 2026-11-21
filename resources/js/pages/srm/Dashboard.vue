<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Award,
    CalendarClock,
    FileWarning,
    ShieldAlert,
    Star,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import ContractController from '@/actions/App/Modules/Srm/Http/Controllers/Suppliers/ContractController';
import SupplierController from '@/actions/App/Modules/Srm/Http/Controllers/Suppliers/SupplierController';
import Heading from '@/components/Heading.vue';
import RiskLevelBadge from '@/components/srm/risk/RiskLevelBadge.vue';
import SupplierStatusBadge from '@/components/srm/suppliers/SupplierStatusBadge.vue';
import { formatDate, formatMoney } from '@/lib/format';
import { dashboard } from '@/routes';
import type {
    DashboardAttention,
    RiskLevelCount,
    SupplierStatusCount,
    TopSpend,
    UpcomingDeadline,
} from '@/types/srm';
import { trans } from '@/lib/i18n';

defineOptions({
    layout: () => ({
        breadcrumbs: [
            {
                title: trans('Supplier dashboard'),
                href: dashboard(),
            },
        ],
    }),
});

const props = defineProps<{
    attention: DashboardAttention;
    upcoming: UpcomingDeadline[];
    riskLevels: RiskLevelCount[];
    statuses: SupplierStatusCount[];
    spendYear: number;
    topSpend: TopSpend[] | null;
}>();

type AttentionTile = {
    label: string;
    description: string;
    icon: Component;
    count: number;
    query: Record<string, string | number>;
};

const attentionTiles = computed<AttentionTile[]>(() => {
    const tiles: AttentionTile[] = [
        {
            label: trans('Missing certificates'),
            description: trans(
                'Active suppliers without a valid required certificate',
            ),
            icon: FileWarning,
            count: props.attention.missing_certificates,
            query: { missing_certificates: 1 },
        },
        {
            label: trans('Certificates expiring'),
            description: trans(
                'Active suppliers with one expired or ending within 30 days',
            ),
            icon: Award,
            count: props.attention.certificates_expiring,
            query: { status: 'active', certificates_expiring: 1 },
        },
        {
            label: trans('High risk'),
            description: trans('Active suppliers rated high risk'),
            icon: ShieldAlert,
            count: props.attention.high_risk,
            query: { status: 'active', risk: 'high' },
        },
        {
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

const deadlineLabels: Record<UpcomingDeadline['kind'], () => string> = {
    contract_cancel_by: () => trans('Cancel by'),
    contract_ends: () => trans('Contract ends'),
    certificate_expires: () => trans('Certificate expires'),
};

function deadlineLink(deadline: UpcomingDeadline) {
    return deadline.contract_id === null
        ? SupplierController.show({ supplier: deadline.supplier_id })
        : ContractController.show({
              supplier: deadline.supplier_id,
              contract: deadline.contract_id,
          });
}
</script>

<template>
    <Head :title="$t('Supplier dashboard')" />

    <div class="flex flex-1 flex-col gap-8 p-4">
        <section class="space-y-4">
            <Heading
                :title="$t('Needs attention')"
                :description="
                    $t('Each number opens the list of suppliers behind it')
                "
                class="mb-4"
            />
            <div
                class="grid gap-4 sm:grid-cols-2"
                :class="
                    attentionTiles.length === 5
                        ? 'xl:grid-cols-5'
                        : 'xl:grid-cols-4'
                "
            >
                <Link
                    v-for="tile in attentionTiles"
                    :key="tile.label"
                    :href="SupplierController.index({}, { query: tile.query })"
                    class="flex flex-col gap-2 rounded-xl border p-4 transition-colors hover:bg-muted/30"
                >
                    <span class="flex items-center gap-2 text-sm font-medium">
                        <component
                            :is="tile.icon"
                            class="size-4 shrink-0 text-muted-foreground"
                        />
                        {{ tile.label }}
                    </span>
                    <span class="text-3xl font-semibold tabular-nums">
                        {{ tile.count }}
                    </span>
                    <span class="text-xs text-muted-foreground">
                        {{ tile.description }}
                    </span>
                </Link>
            </div>
        </section>

        <div class="grid gap-8 lg:grid-cols-3">
            <section class="space-y-4 lg:col-span-2">
                <Heading
                    variant="small"
                    :title="$t('Next 30 days')"
                    :description="
                        $t('The 10 soonest contract and certificate dates')
                    "
                />
                <div class="overflow-x-auto rounded-xl border">
                    <table class="w-full text-sm">
                        <thead
                            class="bg-muted/50 text-left text-muted-foreground"
                        >
                            <tr>
                                <th scope="col" class="px-4 py-3 font-medium">
                                    {{ $t('Date') }}
                                </th>
                                <th scope="col" class="px-4 py-3 font-medium">
                                    {{ $t('What') }}
                                </th>
                                <th scope="col" class="px-4 py-3 font-medium">
                                    {{ $t('Supplier') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="deadline in upcoming"
                                :key="deadline.key"
                                class="hover:bg-muted/30"
                            >
                                <td
                                    class="px-4 py-3 whitespace-nowrap tabular-nums"
                                >
                                    {{ formatDate(deadline.date) }}
                                </td>
                                <td class="px-4 py-3">
                                    <Link
                                        :href="deadlineLink(deadline)"
                                        class="font-medium hover:underline"
                                    >
                                        {{ deadline.title }}
                                    </Link>
                                    <p class="text-xs text-muted-foreground">
                                        {{ deadlineLabels[deadline.kind]() }}
                                    </p>
                                </td>
                                <td class="px-4 py-3">
                                    <Link
                                        :href="
                                            SupplierController.show({
                                                supplier: deadline.supplier_id,
                                            })
                                        "
                                        class="hover:underline"
                                    >
                                        {{ deadline.supplier_name }}
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="upcoming.length === 0">
                                <td
                                    colspan="3"
                                    class="px-4 py-10 text-center text-muted-foreground"
                                >
                                    {{
                                        $t(
                                            'Nothing is due in the next 30 days.',
                                        )
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="space-y-8">
                <section class="space-y-4">
                    <Heading variant="small" :title="$t('Risk overview')" />
                    <ul class="divide-y rounded-xl border text-sm">
                        <li v-for="risk in riskLevels" :key="risk.level">
                            <Link
                                :href="
                                    SupplierController.index(
                                        {},
                                        { query: { risk: risk.level } },
                                    )
                                "
                                class="flex items-center justify-between gap-4 px-4 py-3 hover:bg-muted/30"
                            >
                                <RiskLevelBadge :level="risk.level" />
                                <span class="font-medium tabular-nums">
                                    {{ risk.count }}
                                </span>
                            </Link>
                        </li>
                    </ul>
                </section>

                <section class="space-y-4">
                    <Heading variant="small" :title="$t('Supplier overview')" />
                    <ul class="divide-y rounded-xl border text-sm">
                        <li v-for="item in statuses" :key="item.status">
                            <Link
                                :href="
                                    SupplierController.index(
                                        {},
                                        { query: { status: item.status } },
                                    )
                                "
                                class="flex items-center justify-between gap-4 px-4 py-3 hover:bg-muted/30"
                            >
                                <SupplierStatusBadge :status="item.status" />
                                <span class="font-medium tabular-nums">
                                    {{ item.count }}
                                </span>
                            </Link>
                        </li>
                    </ul>
                </section>
            </div>
        </div>

        <section v-if="topSpend !== null" class="space-y-4">
            <Heading
                variant="small"
                :title="
                    $t('Biggest suppliers by spend in :year', {
                        year: spendYear,
                    })
                "
                :description="
                    $t(
                        'Top 5 per currency, because different currencies cannot be compared',
                    )
                "
            />
            <p
                v-if="topSpend.length === 0"
                class="text-sm text-muted-foreground"
            >
                {{ $t('No spend recorded for :year.', { year: spendYear }) }}
            </p>
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <table
                    v-for="group in topSpend"
                    :key="group.currency_code"
                    class="w-full rounded-xl border text-sm"
                >
                    <caption
                        class="pb-2 text-left text-sm font-medium text-muted-foreground"
                    >
                        {{
                            group.currency_code
                        }}
                    </caption>
                    <tbody class="divide-y">
                        <tr
                            v-for="supplier in group.suppliers"
                            :key="supplier.id"
                        >
                            <td class="px-4 py-3">
                                <Link
                                    :href="
                                        SupplierController.show({
                                            supplier: supplier.id,
                                        })
                                    "
                                    class="hover:underline"
                                >
                                    {{ supplier.name }}
                                </Link>
                            </td>
                            <td
                                class="px-4 py-3 text-right whitespace-nowrap tabular-nums"
                            >
                                {{
                                    formatMoney(
                                        supplier.amount,
                                        group.currency_code,
                                    )
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
