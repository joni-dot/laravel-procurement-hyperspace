<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import ContractController from '@/actions/App/Modules/Srm/Http/Controllers/Suppliers/ContractController';
import SupplierController from '@/actions/App/Modules/Srm/Http/Controllers/Suppliers/SupplierController';
import ContractForm from '@/components/srm/contracts/ContractForm.vue';
import Heading from '@/components/Heading.vue';
import type { ContractFormOptions } from '@/types/srm';
import { trans } from '@/lib/i18n';

const props = defineProps<
    ContractFormOptions & {
        supplier: { id: number; name: string };
    }
>();

setLayoutProps({
    breadcrumbs: [
        { title: trans('Suppliers'), href: SupplierController.index() },
        {
            title: props.supplier.name,
            href: SupplierController.show({ supplier: props.supplier.id }),
        },
        {
            title: trans('New contract'),
            href: ContractController.create({ supplier: props.supplier.id }),
        },
    ],
});
</script>

<template>
    <Head :title="$t('New contract')" />

    <div class="p-4">
        <Heading :title="$t('New contract')" :description="supplier.name" />
        <ContractForm
            :action="ContractController.store.form({ supplier: supplier.id })"
            :cancel-href="
                SupplierController.show.url({ supplier: supplier.id })
            "
            :submit-label="$t('Create contract')"
            :options="props"
        />
    </div>
</template>
