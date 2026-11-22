<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import { Toaster } from '@/components/ui/sonner';
import { useNavigationState } from '@/composables/useNavigationState';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const { isSlow } = useNavigationState();
const page = usePage();
const isDemoCompany = computed(() => page.props.company?.is_demo === true);
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent variant="sidebar" class="min-w-0 overflow-x-clip">
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <p
                v-if="isDemoCompany"
                class="border-b border-sidebar-border/70 bg-muted/60 px-4 py-1.5 text-center text-xs text-muted-foreground"
                data-test="demo-notice"
            >
                {{ $t('Demo company: the data is made up.') }}
            </p>
            <!--
                The header stays usable while a page loads, so the sidebar toggle and notifications still work.
                Each new page's top element is created afresh, so the fade-in on the direct children plays once
                per page change, but not when filters, deferred data or form errors update the same page.
            -->
            <div
                data-test="page-content"
                class="flex min-w-0 flex-1 flex-col motion-safe:transition-opacity motion-safe:duration-150 motion-safe:*:animate-page-fade-in"
                :class="{ 'opacity-60': isSlow }"
                :inert="isSlow"
                :aria-busy="isSlow ? 'true' : undefined"
            >
                <slot />
            </div>
        </AppContent>
        <Toaster />
    </AppShell>
</template>
