<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Check, ChevronsUpDown, Mail, Settings } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import { Badge } from '@/components/ui/badge';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as invitations } from '@/routes/invitations';
import { edit as accountSettings } from '@/routes/profile';

const page = usePage();
const { isMobile, state } = useSidebar();

const company = computed(() => page.props.company);
const companies = computed(() => page.props.companies ?? []);
const invitationCount = computed(() => page.props.invitationCount ?? 0);

function initialOf(name: string): string {
    return name.trim().charAt(0).toUpperCase() || '?';
}
</script>

<template>
    <SidebarMenu>
        <SidebarMenuItem>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <SidebarMenuButton
                        size="lg"
                        class="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
                        data-test="company-switcher"
                    >
                        <template v-if="company">
                            <div
                                class="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary font-semibold text-sidebar-primary-foreground"
                                aria-hidden="true"
                            >
                                {{ initialOf(company.name) }}
                            </div>
                            <div class="ml-1 grid flex-1 text-left text-sm">
                                <span
                                    class="truncate leading-tight font-semibold"
                                >
                                    {{ company.name }}
                                </span>
                                <span class="sr-only">, switch company</span>
                            </div>
                        </template>
                        <AppLogo v-else />
                        <ChevronsUpDown class="ml-auto size-4" />
                    </SidebarMenuButton>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="w-(--reka-dropdown-menu-trigger-width) min-w-60 rounded-lg"
                    :side="
                        isMobile || state !== 'collapsed' ? 'bottom' : 'right'
                    "
                    align="start"
                    :side-offset="4"
                >
                    <template v-if="companies.length > 0">
                        <DropdownMenuLabel
                            class="text-xs text-muted-foreground"
                        >
                            Companies
                        </DropdownMenuLabel>
                        <DropdownMenuGroup>
                            <template
                                v-for="item in companies"
                                :key="item.slug"
                            >
                                <!-- Its members can't open a suspended company, so it is shown but not linked. -->
                                <DropdownMenuItem
                                    v-if="item.suspended"
                                    disabled
                                >
                                    <span class="truncate">{{
                                        item.name
                                    }}</span>
                                    <span
                                        class="ml-auto text-xs text-muted-foreground"
                                        >Suspended</span
                                    >
                                </DropdownMenuItem>
                                <DropdownMenuItem v-else as-child>
                                    <Link
                                        class="w-full cursor-pointer"
                                        :href="
                                            dashboard({ company: item.slug })
                                        "
                                        :aria-current="
                                            item.slug === company?.slug
                                                ? 'page'
                                                : undefined
                                        "
                                    >
                                        <span class="truncate">{{
                                            item.name
                                        }}</span>
                                        <Check
                                            v-if="item.slug === company?.slug"
                                            class="ml-auto"
                                            aria-hidden="true"
                                        />
                                    </Link>
                                </DropdownMenuItem>
                            </template>
                        </DropdownMenuGroup>
                        <DropdownMenuSeparator />
                    </template>
                    <DropdownMenuGroup>
                        <DropdownMenuItem as-child>
                            <Link
                                class="w-full cursor-pointer"
                                :href="invitations()"
                            >
                                <Mail />
                                Invitations
                                <Badge
                                    v-if="invitationCount > 0"
                                    variant="secondary"
                                    class="ml-auto"
                                    >{{ invitationCount }}</Badge
                                >
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuItem as-child>
                            <Link
                                class="w-full cursor-pointer"
                                :href="accountSettings()"
                            >
                                <Settings />
                                Account settings
                            </Link>
                        </DropdownMenuItem>
                    </DropdownMenuGroup>
                </DropdownMenuContent>
            </DropdownMenu>
        </SidebarMenuItem>
    </SidebarMenu>
</template>
