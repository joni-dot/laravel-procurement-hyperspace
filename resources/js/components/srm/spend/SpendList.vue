<script setup lang="ts">
import { Pencil, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import SpendController from '@/actions/App/Modules/Srm/Http/Controllers/Suppliers/SpendController';
import ConfirmActionDialog from '@/components/ConfirmActionDialog.vue';
import Heading from '@/components/Heading.vue';
import SpendDialog from '@/components/srm/spend/SpendDialog.vue';
import { Button } from '@/components/ui/button';
import { formatMoney } from '@/lib/format';
import type { SelectOption, SupplierSpend } from '@/types/srm';

defineProps<{
    supplierId: number;
    spends: SupplierSpend[];
    currencies: SelectOption[];
    canEdit: boolean;
}>();

const isAdding = ref(false);
const spendBeingEdited = ref<SupplierSpend | null>(null);
const spendBeingDeleted = ref<SupplierSpend | null>(null);
</script>

<template>
    <section class="space-y-4">
        <div class="flex items-center justify-between gap-4">
            <Heading variant="small" :title="$t('Spend')" />
            <Button
                v-if="canEdit"
                variant="outline"
                size="sm"
                @click="isAdding = true"
            >
                <Plus /> {{ $t('Add spend') }}
            </Button>
        </div>

        <p v-if="spends.length === 0" class="text-sm text-muted-foreground">
            {{ $t('No spend recorded yet.') }}
        </p>

        <ul v-else class="divide-y rounded-xl border">
            <li
                v-for="spend in spends"
                :key="spend.id"
                class="flex items-center justify-between gap-4 px-4 py-2 text-sm"
            >
                <p>
                    <span class="font-medium">{{ spend.year }}</span>
                    <span class="ml-4">{{
                        formatMoney(spend.amount, spend.currency_code)
                    }}</span>
                </p>
                <div v-if="canEdit" class="flex">
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        :aria-label="
                            $t('Edit spend for :year', { year: spend.year })
                        "
                        @click="spendBeingEdited = spend"
                    >
                        <Pencil />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        :aria-label="
                            $t('Delete spend for :year', { year: spend.year })
                        "
                        @click="spendBeingDeleted = spend"
                    >
                        <Trash2 />
                    </Button>
                </div>
            </li>
        </ul>
    </section>

    <SpendDialog
        v-if="isAdding"
        :supplier-id="supplierId"
        :currencies="currencies"
        :open="true"
        @update:open="isAdding = false"
    />

    <SpendDialog
        v-if="spendBeingEdited"
        :supplier-id="supplierId"
        :currencies="currencies"
        :spend="spendBeingEdited"
        :open="true"
        @update:open="spendBeingEdited = null"
    />

    <ConfirmActionDialog
        v-if="spendBeingDeleted"
        :action="
            SpendController.destroy.form({
                supplier: supplierId,
                spend: spendBeingDeleted.id,
            })
        "
        :title="$t('Delete spend for :year?', { year: spendBeingDeleted.year })"
        :description="$t('The amount is removed permanently.')"
        :confirm-label="$t('Delete')"
        :open="true"
        @update:open="spendBeingDeleted = null"
    />
</template>
