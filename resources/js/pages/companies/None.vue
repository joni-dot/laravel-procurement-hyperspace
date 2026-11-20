<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
</script>

<template>
    <Head title="No company yet" />

    <div class="flex flex-col gap-4 p-4">
        <Heading
            title="You're not in any company yet"
            description="When a company invites you, you'll get an email. After you accept, the company opens here."
        />
    </div>
</template>
