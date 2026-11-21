<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import ContractController from '@/actions/App/Modules/Srm/Http/Controllers/Suppliers/ContractController';
import SupplierController from '@/actions/App/Modules/Srm/Http/Controllers/Suppliers/SupplierController';
import { formatDate } from '@/lib/format';
import { trans } from '@/lib/i18n';
import type { UpcomingDeadline } from '@/types/srm';

/**
 * The soonest contract and certificate dates, as the server chose them: contract ones only for people
 * who may see contract details.
 */
defineProps<{
    upcoming: UpcomingDeadline[];
}>();

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
    <div class="overflow-x-auto rounded-xl border">
        <table class="w-full text-sm">
            <thead class="bg-muted/50 text-left text-muted-foreground">
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
                    data-test="upcoming-date"
                    :data-kind="deadline.kind"
                >
                    <td class="px-4 py-3 whitespace-nowrap tabular-nums">
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
                        {{ $t('Nothing is due in the next 30 days.') }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
