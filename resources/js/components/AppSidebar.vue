<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import {
    BadgeCheck,
    Building,
    Building2,
    Code,
    KeyRound,
    FileText,
    LayoutGrid,
    Settings,
    Star,
    Tags,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import ApiClientController from '@/actions/App/Http/Controllers/Admin/ApiClientController';
import CategoryController from '@/actions/App/Modules/Srm/Http/Controllers/Admin/CategoryController';
import CertificateTypeController from '@/actions/App/Modules/Srm/Http/Controllers/Admin/CertificateTypeController';
import ContractTypeController from '@/actions/App/Modules/Srm/Http/Controllers/Admin/ContractTypeController';
import RatingCriterionController from '@/actions/App/Modules/Srm/Http/Controllers/Admin/RatingCriterionController';
import SettingsController from '@/actions/App/Modules/Srm/Http/Controllers/Admin/SettingsController';
import MembershipController from '@/actions/App/Http/Controllers/Admin/MembershipController';
import PlatformCompanyController from '@/actions/App/Http/Controllers/Platform/CompanyController';
import SupplierController from '@/actions/App/Modules/Srm/Http/Controllers/Suppliers/SupplierController';
import CompanySwitcher from '@/components/CompanySwitcher.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const page = usePage();

// Links point into the open company (or the person's home company on account pages), so they are
// worked out again whenever the company changes. Without any company there is nothing to link to.
const mainNavItems = computed<NavItem[]>(() =>
    page.props.company
        ? [
              { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
              {
                  title: 'Suppliers',
                  href: SupplierController.index(),
                  icon: Building2,
              },
          ]
        : [],
);

const adminNavItems = computed<NavItem[]>(() => {
    if (!page.props.company) {
        return [];
    }

    const can = page.props.auth.can;

    const items: { isVisible: boolean; item: NavItem }[] = [
        {
            isVisible: can.manageCategories,
            item: {
                title: 'Categories',
                href: CategoryController.index(),
                icon: Tags,
            },
        },
        {
            isVisible: can.manageCertificateTypes,
            item: {
                title: 'Certificate types',
                href: CertificateTypeController.index(),
                icon: BadgeCheck,
            },
        },
        {
            isVisible: can.manageContractTypes,
            item: {
                title: 'Contract types',
                href: ContractTypeController.index(),
                icon: FileText,
            },
        },
        {
            isVisible: can.manageRatingCriteria,
            item: {
                title: 'Rating criteria',
                href: RatingCriterionController.index(),
                icon: Star,
            },
        },
        {
            isVisible: can.manageUsers,
            item: {
                title: 'Users',
                href: MembershipController.index(),
                icon: Users,
            },
        },
        {
            isVisible: can.manageApiClients,
            item: {
                title: 'API clients',
                href: ApiClientController.index(),
                icon: KeyRound,
            },
        },
        {
            isVisible: can.manageSettings,
            item: {
                title: 'Settings',
                href: SettingsController.edit(),
                icon: Settings,
            },
        },
    ];

    return items.filter(({ isVisible }) => isVisible).map(({ item }) => item);
});

// The service's own administration, outside every company; only platform admins see it.
const platformNavItems = computed<NavItem[]>(() =>
    page.props.auth.isPlatformAdmin
        ? [
              {
                  title: 'Companies',
                  href: PlatformCompanyController.index(),
                  icon: Building,
              },
          ]
        : [],
);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <CompanySwitcher />
        </SidebarHeader>

        <SidebarContent>
            <NavMain
                v-if="mainNavItems.length > 0"
                label="Company"
                :items="mainNavItems"
            />
            <NavMain
                v-if="adminNavItems.length > 0"
                label="Administration"
                :items="adminNavItems"
            />
            <NavMain
                v-if="platformNavItems.length > 0"
                label="Platform"
                :items="platformNavItems"
            />
        </SidebarContent>

        <SidebarFooter>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        as-child
                        size="sm"
                        tooltip="Source code"
                        class="text-muted-foreground"
                    >
                        <a
                            :href="page.props.sourceCodeUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <Code />
                            <span>Source code</span>
                        </a>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
