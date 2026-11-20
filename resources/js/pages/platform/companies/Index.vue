<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { Pause, Pencil, Play, Plus, Search } from '@lucide/vue';
import { ref, watch } from 'vue';
import CompanyController from '@/actions/App/Http/Controllers/Platform/CompanyController';
import ReopenCompanyController from '@/actions/App/Http/Controllers/Platform/ReopenCompanyController';
import SuspendCompanyController from '@/actions/App/Http/Controllers/Platform/SuspendCompanyController';
import ConfirmActionDialog from '@/components/ConfirmActionDialog.vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import CompanyDialog from '@/components/platform/CompanyDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/lib/format';
import type { Paginated, PlatformCompanyRow } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Companies', href: CompanyController.index() }],
    },
});

const props = defineProps<{
    companies: Paginated<PlatformCompanyRow>;
    filters: { search: string };
}>();

const isAdding = ref(false);
const companyBeingEdited = ref<PlatformCompanyRow | null>(null);
const companyBeingSuspended = ref<PlatformCompanyRow | null>(null);
const companyBeingReopened = ref<PlatformCompanyRow | null>(null);

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
    const members = `${company.active_members_count} ${company.active_members_count === 1 ? 'member' : 'members'}`;

    return company.pending_invitations_count > 0
        ? `${members}, ${company.pending_invitations_count} invited`
        : members;
}
</script>

<template>
    <Head title="Companies" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Companies"
                description="The companies using the service. You can't see their data, only who they are and how many people they have."
                class="mb-0"
            />
            <Button @click="isAdding = true"><Plus /> Add company</Button>
        </div>

        <div class="relative max-w-sm">
            <Search
                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
            />
            <Input
                v-model="search"
                type="search"
                placeholder="Search by name or address"
                aria-label="Search companies"
                class="pl-9"
            />
        </div>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium">Name</th>
                        <th scope="col" class="px-4 py-3 font-medium">
                            Address
                        </th>
                        <th scope="col" class="px-4 py-3 font-medium">
                            Status
                        </th>
                        <th scope="col" class="px-4 py-3 font-medium">
                            Members
                        </th>
                        <th scope="col" class="px-4 py-3 font-medium">
                            Created
                        </th>
                        <th scope="col" class="px-4 py-3">
                            <span class="sr-only">Actions</span>
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
                                        ? 'Suspended'
                                        : 'Active'
                                }}
                            </Badge>
                        </td>
                        <td class="px-4 py-3">{{ memberSummary(company) }}</td>
                        <td class="px-4 py-3">
                            {{ formatDate(company.created_at) }}
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                :aria-label="`Edit ${company.name}`"
                                @click="companyBeingEdited = company"
                            >
                                <Pencil />
                            </Button>
                            <Button
                                v-if="company.is_suspended"
                                variant="ghost"
                                size="icon-sm"
                                :aria-label="`Reopen ${company.name}`"
                                @click="companyBeingReopened = company"
                            >
                                <Play />
                            </Button>
                            <Button
                                v-else
                                variant="ghost"
                                size="icon-sm"
                                :aria-label="`Suspend ${company.name}`"
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
                            No companies found.
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

    <ConfirmActionDialog
        v-if="companyBeingSuspended"
        :action="
            SuspendCompanyController.form({ company: companyBeingSuspended.id })
        "
        :title="`Suspend ${companyBeingSuspended.name}?`"
        description="Its members can't open the company until you reopen it. All its data is kept."
        confirm-label="Suspend"
        :open="true"
        @update:open="companyBeingSuspended = null"
    />

    <ConfirmActionDialog
        v-if="companyBeingReopened"
        :action="
            ReopenCompanyController.form({ company: companyBeingReopened.id })
        "
        :title="`Reopen ${companyBeingReopened.name}?`"
        description="Its members can open the company again, with all its data as it was."
        confirm-label="Reopen"
        :open="true"
        @update:open="companyBeingReopened = null"
    />
</template>
