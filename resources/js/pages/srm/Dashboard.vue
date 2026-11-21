<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import SupplierController from '@/actions/App/Modules/Srm/Http/Controllers/Suppliers/SupplierController';
import Heading from '@/components/Heading.vue';
import AttentionTiles from '@/components/srm/dashboard/AttentionTiles.vue';
import UpcomingDeadlineTable from '@/components/srm/dashboard/UpcomingDeadlineTable.vue';
import RiskLevelBadge from '@/components/srm/risk/RiskLevelBadge.vue';
import SupplierStatusBadge from '@/components/srm/suppliers/SupplierStatusBadge.vue';
import { formatMoney } from '@/lib/format';
import { trans } from '@/lib/i18n';
import { dashboard } from '@/routes';
import type {
    DashboardAttention,
    RiskLevelCount,
    SupplierStatusCount,
    TopSpend,
    UpcomingDeadline,
} from '@/types/srm';

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

defineProps<{
    attention: DashboardAttention;
    upcoming: UpcomingDeadline[];
    riskLevels: RiskLevelCount[];
    statuses: SupplierStatusCount[];
    spendYear: number;
    topSpend: TopSpend[] | null;
}>();
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
            <AttentionTiles :attention="attention" />
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
                <UpcomingDeadlineTable :upcoming="upcoming" />
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
