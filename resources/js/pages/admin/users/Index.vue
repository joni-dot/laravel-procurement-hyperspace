<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { MoreHorizontal, Search } from '@lucide/vue';
import { ref, watch } from 'vue';
import DeactivateMembershipController from '@/actions/App/Http/Controllers/Admin/DeactivateMembershipController';
import MembershipController from '@/actions/App/Http/Controllers/Admin/MembershipController';
import ReactivateMembershipController from '@/actions/App/Http/Controllers/Admin/ReactivateMembershipController';
import ResendInvitationController from '@/actions/App/Http/Controllers/Admin/ResendInvitationController';
import ConfirmActionDialog from '@/components/ConfirmActionDialog.vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import ChangeRoleDialog from '@/components/users/ChangeRoleDialog.vue';
import InviteUserDialog from '@/components/users/InviteUserDialog.vue';
import MembershipStatusBadge from '@/components/users/MembershipStatusBadge.vue';
import { Badge } from '@/components/ui/badge';
import type { Member, Paginated, RoleOption } from '@/types';
import { trans } from '@/lib/i18n';

defineOptions({
    layout: () => ({
        breadcrumbs: [
            {
                title: trans('Users'),
                href: MembershipController.index(),
            },
        ],
    }),
});

const props = defineProps<{
    users: Paginated<Member>;
    filters: { search: string };
    roles: RoleOption[];
}>();

const memberChangingRole = ref<Member | null>(null);
const memberBeingDeactivated = ref<Member | null>(null);

const search = ref(props.filters.search);

const searchMembers = useDebounceFn((term: string) => {
    router.get(
        MembershipController.index.url(),
        { search: term || undefined },
        {
            only: ['users', 'filters'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}, 300);

watch(search, searchMembers);

function roleLabel(member: Member): string {
    return props.roles.find((role) => role.value === member.role)?.label ?? '';
}

function hasActions(member: Member): boolean {
    return Object.values(member.can).some(Boolean);
}

function reactivate(member: Member): void {
    router.post(
        ReactivateMembershipController.url({ membership: member.id }),
        {},
        { preserveScroll: true },
    );
}

function resendInvitation(member: Member): void {
    router.post(
        ResendInvitationController.url({ membership: member.id }),
        {},
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head :title="$t('Users')" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                :title="$t('Users')"
                :description="
                    $t(
                        'Invite people to this company and decide what they can do here',
                    )
                "
                class="mb-0"
            />
            <InviteUserDialog :roles="roles" />
        </div>

        <div class="relative max-w-sm">
            <Search
                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
            />
            <Input
                v-model="search"
                type="search"
                :placeholder="$t('Search by name or email')"
                :aria-label="$t('Search users')"
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
                            {{ $t('Email') }}
                        </th>
                        <th scope="col" class="px-4 py-3 font-medium">
                            {{ $t('Role') }}
                        </th>
                        <th scope="col" class="px-4 py-3 font-medium">
                            {{ $t('Status') }}
                        </th>
                        <th scope="col" class="px-4 py-3">
                            <span class="sr-only">{{ $t('Actions') }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="member in users.data" :key="member.id">
                        <td class="px-4 py-3 font-medium">
                            {{ member.name }}
                            <span
                                v-if="member.isYou"
                                class="font-normal text-muted-foreground"
                                >{{ $t('(you)') }}</span
                            >
                            <Badge
                                v-if="member.isPlatformAdmin"
                                variant="outline"
                                class="ml-2"
                                :title="
                                    $t(
                                        'Runs this service. Platform admins never need to be in a company: deactivate them if you didn\'t invite them.',
                                    )
                                "
                                >{{ $t('Platform admin') }}</Badge
                            >
                        </td>
                        <td class="px-4 py-3">{{ member.email }}</td>
                        <td class="px-4 py-3">{{ roleLabel(member) }}</td>
                        <td class="px-4 py-3">
                            <MembershipStatusBadge :status="member.status" />
                        </td>
                        <td class="px-4 py-3 text-right">
                            <DropdownMenu v-if="hasActions(member)">
                                <DropdownMenuTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        :aria-label="
                                            $t('Actions for :name', {
                                                name: member.name,
                                            })
                                        "
                                    >
                                        <MoreHorizontal />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        v-if="member.can.update"
                                        @select="memberChangingRole = member"
                                    >
                                        {{ $t('Change role') }}
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="member.can.resendInvitation"
                                        @select="resendInvitation(member)"
                                    >
                                        {{ $t('Resend invitation') }}
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="member.can.reactivate"
                                        @select="reactivate(member)"
                                    >
                                        {{ $t('Reactivate') }}
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="member.can.deactivate"
                                        variant="destructive"
                                        @select="
                                            memberBeingDeactivated = member
                                        "
                                    >
                                        {{ $t('Deactivate') }}
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </td>
                    </tr>
                    <tr v-if="users.data.length === 0">
                        <td
                            colspan="5"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            {{ $t('No users found.') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :paginator="users" />
    </div>

    <ChangeRoleDialog
        v-if="memberChangingRole"
        :member="memberChangingRole"
        :roles="roles"
        :open="true"
        @update:open="memberChangingRole = null"
    />

    <ConfirmActionDialog
        v-if="memberBeingDeactivated"
        :action="
            DeactivateMembershipController.form({
                membership: memberBeingDeactivated.id,
            })
        "
        :title="$t('Deactivate :name?', { name: memberBeingDeactivated.name })"
        :description="
            $t(
                'They can\'t open this company until an admin reactivates them, and an invitation link stops working. Their account, their other companies and everything they did here stay.',
            )
        "
        :confirm-label="$t('Deactivate')"
        :open="true"
        @update:open="memberBeingDeactivated = null"
    />
</template>
