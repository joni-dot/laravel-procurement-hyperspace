<script setup lang="ts">
import { Form, useHttp } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import InviteFirstAdminController from '@/actions/App/Http/Controllers/Platform/InviteFirstAdminController';
import PromoteMemberController from '@/actions/App/Http/Controllers/Platform/PromoteMemberController';
import SearchMembersToPromoteController from '@/actions/App/Http/Controllers/Platform/SearchMembersToPromoteController';
import FormField from '@/components/FormField.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import type { MemberToPromote, PlatformCompanyRow } from '@/types';

/** The most members one search returns (SearchMembersToPromoteController::MAX_RESULTS). */
const MAX_RESULTS = 20;

const props = defineProps<{
    company: PlatformCompanyRow;
}>();

const isOpen = defineModel<boolean>('open', { required: true });

// A company with members gets one of them as admin; only a company with nobody to choose gets an
// invitation. Follows the row, so a reload that changes the company changes the dialog too.
const promotesAMember = computed(
    () => props.company.members_to_promote_count > 0,
);
const action = computed(() =>
    promotesAMember.value
        ? PromoteMemberController.form({ company: props.company.id })
        : InviteFirstAdminController.form({ company: props.company.id }),
);

// The members are asked for only here, a few at a time, so the companies list never carries them.
const memberSearch = useHttp<
    { search: string },
    { members: MemberToPromote[] }
>({ search: '' });
const members = ref<MemberToPromote[]>([]);
const chosenMemberId = ref<number | null>(null);
const searchFailed = ref(false);
let latestSearch = 0;

async function searchMembers(): Promise<void> {
    const thisSearch = ++latestSearch;
    memberSearch.cancel();

    try {
        const response = await memberSearch.get(
            SearchMembersToPromoteController.url({ company: props.company.id }),
        );

        if (thisSearch !== latestSearch) {
            return;
        }

        members.value = response?.members ?? [];
        searchFailed.value = false;

        if (!members.value.some(({ id }) => id === chosenMemberId.value)) {
            chosenMemberId.value = members.value[0]?.id ?? null;
        }
    } catch {
        // A search replaced by a newer one is cancelled; only the newest one's failure counts.
        if (thisSearch === latestSearch) {
            searchFailed.value = true;
        }
    }
}

const searchMembersSoon = useDebounceFn(searchMembers, 300);

watch(() => memberSearch.search, searchMembersSoon);

watch(
    () => [promotesAMember.value, props.company.id] as const,
    ([promotes]) => {
        if (promotes) {
            void searchMembers();
        }
    },
    { immediate: true },
);
</script>

<template>
    <Dialog v-model:open="isOpen">
        <DialogContent>
            <Form
                v-bind="action"
                :options="{ preserveScroll: true }"
                @success="isOpen = false"
                class="space-y-6"
                v-slot="{ errors, processing }"
            >
                <DialogHeader v-if="promotesAMember">
                    <DialogTitle>{{ $t('Choose a new admin') }}</DialogTitle>
                    <DialogDescription>
                        {{
                            $t(
                                ':company has no admin left. Make one of its members the admin; they get an email about it.',
                                { company: company.name },
                            )
                        }}
                    </DialogDescription>
                </DialogHeader>

                <FormField
                    v-if="promotesAMember"
                    id="member-search"
                    :label="$t('Search members')"
                >
                    <Input
                        id="member-search"
                        v-model="memberSearch.search"
                        type="search"
                        :placeholder="$t('Search by name or email')"
                        maxlength="255"
                        autocomplete="off"
                        @keydown.enter.prevent
                    />
                </FormField>

                <FormField
                    v-if="promotesAMember"
                    id="new-admin"
                    :label="$t('Member')"
                    :error="errors.membership_id"
                    :hint="
                        members.length >= MAX_RESULTS
                            ? $t(
                                  'Showing the first :count. Search by name or email to find others.',
                                  { count: MAX_RESULTS },
                              )
                            : undefined
                    "
                >
                    <NativeSelect
                        id="new-admin"
                        v-model="chosenMemberId"
                        name="membership_id"
                        required
                    >
                        <option
                            v-for="member in members"
                            :key="member.id"
                            :value="member.id"
                        >
                            {{ member.name }} ({{ member.email }})
                        </option>
                    </NativeSelect>
                    <p
                        v-if="searchFailed"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ $t('The members could not be loaded. Try again.') }}
                    </p>
                    <p
                        v-else-if="
                            members.length === 0 && !memberSearch.processing
                        "
                        class="text-sm text-muted-foreground"
                    >
                        {{ $t('No active member matches the search.') }}
                    </p>
                </FormField>

                <DialogHeader v-if="!promotesAMember">
                    <DialogTitle>{{
                        $t('Invite the first admin')
                    }}</DialogTitle>
                    <DialogDescription>
                        {{
                            $t(
                                ':company has no admin yet, so nobody can invite people. Send the invitation again, or to a corrected address. A link sent earlier to another address stops working.',
                                { company: company.name },
                            )
                        }}
                    </DialogDescription>
                </DialogHeader>

                <FormField
                    v-if="!promotesAMember"
                    id="first-admin-name"
                    :label="$t('Admin\'s name')"
                    :error="errors.admin_name"
                >
                    <Input
                        id="first-admin-name"
                        name="admin_name"
                        :default-value="
                            company.first_admin_invitation?.name ?? ''
                        "
                        required
                        maxlength="255"
                        autocomplete="off"
                    />
                </FormField>

                <FormField
                    v-if="!promotesAMember"
                    id="first-admin-email"
                    :label="$t('Admin\'s email')"
                    :error="errors.admin_email"
                >
                    <Input
                        id="first-admin-email"
                        name="admin_email"
                        type="email"
                        :default-value="
                            company.first_admin_invitation?.email ?? ''
                        "
                        required
                        maxlength="255"
                        autocomplete="off"
                    />
                </FormField>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="secondary">
                            {{ $t('Cancel') }}
                        </Button>
                    </DialogClose>
                    <Button
                        type="submit"
                        :disabled="
                            processing ||
                            (promotesAMember && chosenMemberId === null)
                        "
                    >
                        <Spinner v-if="processing" />
                        {{
                            promotesAMember
                                ? $t('Make admin')
                                : $t('Send invitation')
                        }}
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
