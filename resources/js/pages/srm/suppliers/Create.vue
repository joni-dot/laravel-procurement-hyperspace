<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import SupplierController from '@/actions/App/Modules/Srm/Http/Controllers/Suppliers/SupplierController';
import Heading from '@/components/Heading.vue';
import SupplierForm from '@/components/srm/suppliers/SupplierForm.vue';
import type { SupplierFormOptions } from '@/types/srm';
import { trans } from '@/lib/i18n';

defineOptions({
    layout: () => ({
        breadcrumbs: [
            { title: trans('Suppliers'), href: SupplierController.index() },
            { title: trans('New supplier'), href: SupplierController.create() },
        ],
    }),
});

const options = defineProps<SupplierFormOptions>();
</script>

<template>
    <Head :title="$t('New supplier')" />

    <div class="p-4">
        <Heading :title="$t('New supplier')" />
        <SupplierForm
            :action="SupplierController.store.form()"
            :cancel-href="SupplierController.index.url()"
            :submit-label="$t('Create supplier')"
            :options="options"
        />
    </div>
</template>
