<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { Pause, Pencil, Play, Plus, Search, UserPlus } from '@lucide/vue';
import { ref, watch } from 'vue';
import CompanyController from '@/actions/App/Http/Controllers/Platform/CompanyController';
import ReopenCompanyController from '@/actions/App/Http/Controllers/Platform/ReopenCompanyController';
import SuspendCompanyController from '@/actions/App/Http/Controllers/Platform/SuspendCompanyController';
import ConfirmActionDialog from '@/components/ConfirmActionDialog.vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import CompanyDialog from '@/components/platform/CompanyDialog.vue';
import FirstAdminDialog from '@/components/platform/FirstAdminDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/lib/format';
import type { Paginated, PlatformCompanyRow } from '@/types';
import { trans } from '@/lib/i18n';

defineOptions({
    layout: () => ({
        breadcrumbs: [
            { title: trans('Companies'), href: CompanyController.index() },
        ],
    }),
});

const props = defineProps<{
    companies: Paginated<PlatformCompanyRow>;
    filters: { search: string };
}>();

const isAdding = ref(false);
const companyBeingEdited = ref<PlatformCompanyRow | null>(null);
const companyBeingSuspended = ref<PlatformCompanyRow | null>(null);
const companyBeingReopened = ref<PlatformCompanyRow | null>(null);
const companyNeedingAdmin = ref<PlatformCompanyRow | null>(null);

const search = ref(props.filters.search);

const searchCompanies = useDebounceFn((term: string) => {
    router.get(
        CompanyController.index.url(),
        { search: term || undefined },
        {
            only: ['companies', 'filters'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}, 300);

watch(search, searchCompanies);

function memberSummary(company: PlatformCompanyRow): string {
    const count = company.active_members_count;
    const members =
        count === 1
            ? trans(':count member', { count })
            : trans(':count members', { count });

    return company.pending_invitations_count > 0
        ? trans(':members, :invited invited', {
              members,
              invited: company.pending_invitations_count,
          })
        : members;
}
</script>

<template>
    <Head :title="$t('Companies')" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                :title="$t('Companies')"
                :description="
                    $t(
                        'The companies using the service. You can\'t see their data, only who they are and how many people they have.',
                    )
                "
                class="mb-0"
            />
            <Button @click="isAdding = true">
                <Plus /> {{ $t('Add company') }}
            </Button>
        </div>

        <div class="relative max-w-sm">
            <Search
                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
            />
            <Input
                v-model="search"
                type="search"
                :placeholder="$t('Search by name or address')"
                :aria-label="$t('Search companies')"
                class="pl-9"
            />
        </div>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium">
                            {{ $t('Name') }}
                        </th>
                        <th scope="col" class="px-4 py-3 font-medium">
                            {{ $t('Address') }}
                        </th>
                        <th scope="col" class="px-4 py-3 font-medium">
                            {{ $t('Status') }}
                        </th>
                        <th scope="col" class="px-4 py-3 font-medium">
                            {{ $t('Members') }}
                        </th>
                        <th scope="col" class="px-4 py-3 font-medium">
                            {{ $t('Created') }}
                        </th>
                        <th scope="col" class="px-4 py-3">
                            <span class="sr-only">{{ $t('Actions') }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="company in companies.data" :key="company.id">
                        <td class="px-4 py-3 font-medium">
                            {{ company.name }}
                        </td>
                        <td class="px-4 py-3 font-mono text-xs">
                            /{{ company.slug }}
                        </td>
                        <td class="px-4 py-3">
                            <Badge
                                :variant="
                                    company.is_suspended
                                        ? 'destructive'
                                        : 'secondary'
                                "
                            >
                                {{
                                    company.is_suspended
                                        ? $t('Suspended')
                                        : $t('Active')
                                }}
                            </Badge>
                        </td>
                        <td class="px-4 py-3">{{ memberSummary(company) }}</td>
                        <td class="px-4 py-3">
                            {{ formatDate(company.created_at) }}
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <Button
                                v-if="company.first_admin_invitation"
                                variant="ghost"
                                size="icon-sm"
                                :aria-label="
                                    $t('Invite the first admin of :name', {
                                        name: company.name,
                                    })
                                "
                                @click="companyNeedingAdmin = company"
                            >
                                <UserPlus />
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                :aria-label="
                                    $t('Edit :name', { name: company.name })
                                "
                                @click="companyBeingEdited = company"
                            >
                                <Pencil />
                            </Button>
                            <Button
                                v-if="company.is_suspended"
                                variant="ghost"
                                size="icon-sm"
                                :aria-label="
                                    $t('Reopen :name', { name: company.name })
                                "
                                @click="companyBeingReopened = company"
                            >
                                <Play />
                            </Button>
                            <Button
                                v-else
                                variant="ghost"
                                size="icon-sm"
                                :aria-label="
                                    $t('Suspend :name', { name: company.name })
                                "
                                @click="companyBeingSuspended = company"
                            >
                                <Pause />
                            </Button>
                        </td>
                    </tr>
                    <tr v-if="companies.data.length === 0">
                        <td
                            colspan="6"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            {{ $t('No companies found.') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :paginator="companies" />
    </div>

    <CompanyDialog
        v-if="isAdding"
        :open="true"
        @update:open="isAdding = false"
    />

    <CompanyDialog
        v-if="companyBeingEdited"
        :company="companyBeingEdited"
        :open="true"
        @update:open="companyBeingEdited = null"
    />

    <FirstAdminDialog
        v-if="companyNeedingAdmin"
        :company="companyNeedingAdmin"
        :open="true"
        @update:open="companyNeedingAdmin = null"
    />

    <ConfirmActionDialog
        v-if="companyBeingSuspended"
        :action="
            SuspendCompanyController.form({ company: companyBeingSuspended.id })
        "
        :title="$t('Suspend :name?', { name: companyBeingSuspended.name })"
        :description="
            $t(
                'Its members can\'t open the company until you reopen it. All its data is kept.',
            )
        "
        :confirm-label="$t('Suspend')"
        :open="true"
        @update:open="companyBeingSuspended = null"
    />

    <ConfirmActionDialog
        v-if="companyBeingReopened"
        :action="
            ReopenCompanyController.form({ company: companyBeingReopened.id })
        "
        :title="$t('Reopen :name?', { name: companyBeingReopened.name })"
        :description="
            $t(
                'Its members can open the company again, with all its data as it was.',
            )
        "
        :confirm-label="$t('Reopen')"
        :open="true"
        @update:open="companyBeingReopened = null"
    />
</template>
