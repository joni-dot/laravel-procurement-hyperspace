<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import SupplierController from '@/actions/App/Modules/Srm/Http/Controllers/Suppliers/SupplierController';
import Heading from '@/components/Heading.vue';
import SupplierForm from '@/components/srm/suppliers/SupplierForm.vue';
import type { SupplierFormOptions } from '@/types/srm';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Suppliers', href: SupplierController.index() },
            { title: 'New supplier', href: SupplierController.create() },
        ],
    },
});

const options = defineProps<SupplierFormOptions>();
</script>

<template>
    <Head title="New supplier" />

    <div class="p-4">
        <Heading title="New supplier" />
        <SupplierForm
            :action="SupplierController.store.form()"
            :cancel-href="SupplierController.index.url()"
            submit-label="Create supplier"
            :options="options"
        />
    </div>
</template>
