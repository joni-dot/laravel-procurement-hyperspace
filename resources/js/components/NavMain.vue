<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { Spinner } from '@/components/ui/spinner';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import type { NavItem } from '@/types';

const props = defineProps<{
    label: string;
    items: NavItem[];
}>();

const { isCurrentOrParentUrl, isOpeningSlowly } = useCurrentUrl();

// A page below an item keeps that item highlighted, but only the closest one: the project dashboard
// lives below the projects list's address, and must not light up both.
const activeUrl = computed(
    () =>
        props.items
            .map((item) => toUrl(item.href))
            .filter((url) => isCurrentOrParentUrl(url))
            .sort((a, b) => b.length - a.length)[0] ?? null,
);

function isActive(item: NavItem): boolean {
    return toUrl(item.href) === activeUrl.value;
}
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel>{{ label }}</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in items" :key="toUrl(item.href)">
                <SidebarMenuButton
                    as-child
                    :is-active="isActive(item)"
                    :tooltip="item.title"
                >
                    <Link
                        :href="item.href"
                        :prefetch="item.prefetch ?? true"
                        :aria-current="isActive(item) ? 'page' : undefined"
                    >
                        <!-- In the icon's place, so it also shows when the sidebar is collapsed to icons. -->
                        <Spinner v-if="isOpeningSlowly(item.href)" />
                        <component :is="item.icon" v-else />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
