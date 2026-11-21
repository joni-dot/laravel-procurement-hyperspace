<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import ContractController from '@/actions/App/Modules/Srm/Http/Controllers/Suppliers/ContractController';
import SupplierController from '@/actions/App/Modules/Srm/Http/Controllers/Suppliers/SupplierController';
import ContractForm from '@/components/srm/contracts/ContractForm.vue';
import Heading from '@/components/Heading.vue';
import type { ContractFormData, ContractFormOptions } from '@/types/srm';
import { trans } from '@/lib/i18n';

const props = defineProps<
    ContractFormOptions & {
        supplier: { id: number; name: string };
        contract: ContractFormData;
    }
>();

const route = { supplier: props.supplier.id, contract: props.contract.id };

setLayoutProps({
    breadcrumbs: [
        { title: trans('Suppliers'), href: SupplierController.index() },
        {
            title: props.supplier.name,
            href: SupplierController.show({ supplier: props.supplier.id }),
        },
        { title: props.contract.title, href: ContractController.show(route) },
        { title: trans('Edit'), href: ContractController.edit(route) },
    ],
});
</script>

<template>
    <Head :title="$t('Edit :name', { name: contract.title })" />

    <div class="p-4">
        <Heading
            :title="$t('Edit :name', { name: contract.title })"
            :description="supplier.name"
        />
        <ContractForm
            :action="ContractController.update.form(route)"
            :cancel-href="ContractController.show.url(route)"
            :submit-label="$t('Save changes')"
            :options="props"
            :contract="contract"
        />
    </div>
</template>
