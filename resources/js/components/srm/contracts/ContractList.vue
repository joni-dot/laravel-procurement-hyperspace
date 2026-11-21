<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import ContractController from '@/actions/App/Modules/Srm/Http/Controllers/Suppliers/ContractController';
import ContractHealthBadge from '@/components/srm/contracts/ContractHealthBadge.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { formatDate, formatMoney } from '@/lib/format';
import type { ContractSummary } from '@/types/srm';
import { trans } from '@/lib/i18n';

defineProps<{
    supplierId: number;
    contracts: ContractSummary[];
    canEdit: boolean;
}>();

function deadline(contract: ContractSummary): string {
    if (!contract.action_by) {
        return trans('No end date');
    }

    const date = formatDate(contract.action_by);

    return contract.auto_renews
        ? trans('Cancel by :date', { date })
        : trans('Ends :date', { date });
}

function value(contract: ContractSummary): string {
    if (!contract.value || !contract.currency_code) {
        return '—';
    }

    return trans(':amount :period', {
        amount: formatMoney(contract.value, contract.currency_code),
        period: contract.value_period ?? '',
    }).trim();
}
</script>

<template>
    <section class="space-y-4">
        <div class="flex items-center justify-between gap-4">
            <Heading variant="small" :title="$t('Contracts')" />
            <Button v-if="canEdit" variant="outline" size="sm" as-child>
                <Link
                    :href="ContractController.create({ supplier: supplierId })"
                >
                    <Plus /> {{ $t('Add contract') }}
                </Link>
            </Button>
        </div>

        <p v-if="contracts.length === 0" class="text-sm text-muted-foreground">
            {{ $t('No contracts yet.') }}
        </p>

        <div v-else class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium">
                            {{ $t('Contract') }}
                        </th>
                        <th scope="col" class="px-4 py-3 font-medium">
                            {{ $t('Date') }}
                        </th>
                        <th scope="col" class="px-4 py-3 font-medium">
                            {{ $t('Value') }}
                        </th>
                        <th scope="col" class="px-4 py-3 font-medium">
                            {{ $t('Status') }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="contract in contracts" :key="contract.id">
                        <td class="px-4 py-3">
                            <Link
                                :href="
                                    ContractController.show({
                                        supplier: supplierId,
                                        contract: contract.id,
                                    })
                                "
                                class="font-medium hover:underline"
                            >
                                {{ contract.title }}
                            </Link>
                            <p class="text-muted-foreground">
                                {{ contract.type }}
                            </p>
                        </td>
                        <td class="px-4 py-3">{{ deadline(contract) }}</td>
                        <td class="px-4 py-3">{{ value(contract) }}</td>
                        <td class="px-4 py-3">
                            <ContractHealthBadge :health="contract.health" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
