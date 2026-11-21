<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import {
    BadgeCheck,
    Building,
    Building2,
    Code,
    FileText,
    FolderKanban,
    House,
    KeyRound,
    LayoutDashboard,
    LayoutGrid,
    Settings,
    Star,
    Tags,
    TriangleAlert,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import ApiClientController from '@/actions/App/Http/Controllers/Admin/ApiClientController';
import MembershipController from '@/actions/App/Http/Controllers/Admin/MembershipController';
import PlatformCompanyController from '@/actions/App/Http/Controllers/Platform/CompanyController';
import RiskWarningsController from '@/actions/App/Modules/Ppr/Http/Controllers/Admin/SettingsController';
import ProjectController from '@/actions/App/Modules/Ppr/Http/Controllers/Projects/ProjectController';
import CategoryController from '@/actions/App/Modules/Srm/Http/Controllers/Admin/CategoryController';
import CertificateTypeController from '@/actions/App/Modules/Srm/Http/Controllers/Admin/CertificateTypeController';
import ContractTypeController from '@/actions/App/Modules/Srm/Http/Controllers/Admin/ContractTypeController';
import RatingCriterionController from '@/actions/App/Modules/Srm/Http/Controllers/Admin/RatingCriterionController';
import SupplierSettingsController from '@/actions/App/Modules/Srm/Http/Controllers/Admin/SettingsController';
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
import { trans } from '@/lib/i18n';
import { dashboard as supplierDashboard } from '@/routes';
import { home as companyHome } from '@/routes/company';
import { dashboard as projectDashboard } from '@/routes/projects';
import type { NavItem } from '@/types';

const page = usePage();

// Links point into the open company (or the person's home company on account pages), so they are
// worked out again whenever the company changes. Without any company there is nothing to link to.
const homeNavItems = computed<NavItem[]>(() =>
    page.props.company
        ? [
              {
                  title: trans('Home'),
                  href: companyHome(),
                  icon: House,
                  exact: true,
              },
          ]
        : [],
);

const projectNavItems = computed<NavItem[]>(() =>
    page.props.company
        ? [
              {
                  title: trans('Project dashboard'),
                  href: projectDashboard(),
                  icon: LayoutDashboard,
              },
              {
                  title: trans('Projects'),
                  href: ProjectController.index(),
                  icon: FolderKanban,
              },
          ]
        : [],
);

const supplierNavItems = computed<NavItem[]>(() =>
    page.props.company
        ? [
              {
                  title: trans('Supplier dashboard'),
                  href: supplierDashboard(),
                  icon: LayoutGrid,
              },
              {
                  title: trans('Suppliers'),
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
                title: trans('Categories'),
                href: CategoryController.index(),
                icon: Tags,
            },
        },
        {
            isVisible: can.manageCertificateTypes,
            item: {
                title: trans('Certificate types'),
                href: CertificateTypeController.index(),
                icon: BadgeCheck,
            },
        },
        {
            isVisible: can.manageContractTypes,
            item: {
                title: trans('Contract types'),
                href: ContractTypeController.index(),
                icon: FileText,
            },
        },
        {
            isVisible: can.manageRatingCriteria,
            item: {
                title: trans('Rating criteria'),
                href: RatingCriterionController.index(),
                icon: Star,
            },
        },
        {
            isVisible: can.manageUsers,
            item: {
                title: trans('Users'),
                href: MembershipController.index(),
                icon: Users,
            },
        },
        {
            isVisible: can.manageApiClients,
            item: {
                title: trans('API clients'),
                href: ApiClientController.index(),
                icon: KeyRound,
            },
        },
        {
            isVisible: can.manageSettings,
            item: {
                title: trans('Supplier settings'),
                href: SupplierSettingsController.edit(),
                icon: Settings,
            },
        },
        {
            isVisible: can.manageSettings,
            item: {
                title: trans('Project risk warnings'),
                href: RiskWarningsController.edit(),
                icon: TriangleAlert,
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
                  title: trans('Companies'),
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
            <NavMain v-if="homeNavItems.length > 0" :items="homeNavItems" />
            <NavMain
                v-if="projectNavItems.length > 0"
                :label="$t('Projects')"
                :items="projectNavItems"
            />
            <NavMain
                v-if="supplierNavItems.length > 0"
                :label="$t('Suppliers')"
                :items="supplierNavItems"
            />
            <NavMain
                v-if="adminNavItems.length > 0"
                :label="$t('Administration')"
                :items="adminNavItems"
            />
            <NavMain
                v-if="platformNavItems.length > 0"
                :label="$t('Platform')"
                :items="platformNavItems"
            />
        </SidebarContent>

        <SidebarFooter>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        as-child
                        size="sm"
                        :tooltip="$t('Source code')"
                        class="text-muted-foreground"
                    >
                        <a
                            :href="page.props.sourceCodeUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <Code />
                            <span>{{ $t('Source code') }}</span>
                        </a>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
