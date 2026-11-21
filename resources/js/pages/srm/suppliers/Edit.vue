<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import SupplierController from '@/actions/App/Modules/Srm/Http/Controllers/Suppliers/SupplierController';
import Heading from '@/components/Heading.vue';
import SupplierForm from '@/components/srm/suppliers/SupplierForm.vue';
import type { SupplierFormData, SupplierFormOptions } from '@/types/srm';
import { trans } from '@/lib/i18n';

const props = defineProps<
    SupplierFormOptions & {
        supplier: SupplierFormData;
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
            title: trans('Edit'),
            href: SupplierController.edit({ supplier: props.supplier.id }),
        },
    ],
});
</script>

<template>
    <Head :title="$t('Edit :name', { name: supplier.name })" />

    <div class="p-4">
        <Heading :title="$t('Edit :name', { name: supplier.name })" />
        <SupplierForm
            :action="SupplierController.update.form({ supplier: supplier.id })"
            :cancel-href="
                SupplierController.show.url({ supplier: supplier.id })
            "
            :submit-label="$t('Save changes')"
            :options="props"
            :supplier="supplier"
        />
    </div>
</template>
