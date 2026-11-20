<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import InvitationController from '@/actions/App/Http/Controllers/InvitationController';
import JoinCompanyController from '@/actions/App/Http/Controllers/JoinCompanyController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/format';
import type { Invitation } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Invitations', href: InvitationController.index() },
        ],
    },
});

defineProps<{
    invitations: Invitation[];
}>();

const page = usePage();

// Without any company, this page is where people land, so it explains how to get in.
const emptyMessage = computed(() =>
    page.props.company === null
        ? "You're not in any company yet. When a company invites you, you'll get an email, and the invitation shows up here."
        : 'You have no invitations.',
);
</script>

<template>
    <Head title="Invitations" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <Heading
            title="Invitations"
            description="Companies that have invited you to join them"
            class="mb-0"
        />

        <p
            v-if="invitations.length === 0"
            class="rounded-xl border px-4 py-10 text-center text-sm text-muted-foreground"
        >
            {{ emptyMessage }}
        </p>

        <ul v-else class="divide-y rounded-xl border">
            <li
                v-for="invitation in invitations"
                :key="invitation.id"
                class="flex flex-wrap items-center justify-between gap-4 px-4 py-3"
            >
                <div class="space-y-1">
                    <p class="font-medium">
                        {{ invitation.companyName }}
                        <span class="font-normal text-muted-foreground"
                            >as {{ invitation.role }}</span
                        >
                    </p>
                    <p class="text-sm text-muted-foreground">
                        <template v-if="invitation.invitedBy"
                            >Invited by {{ invitation.invitedBy }} on
                        </template>
                        <template v-else>Invited on </template>
                        {{ formatDate(invitation.sentOn) }}
                    </p>
                </div>

                <div
                    v-if="invitation.isExpired"
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <Badge variant="outline">Expired</Badge>
                    Ask the company for a new invitation.
                </div>
                <div v-else class="flex gap-2">
                    <Form
                        v-bind="
                            InvitationController.destroy.form({
                                invitation: invitation.id,
                            })
                        "
                        :options="{ preserveScroll: true }"
                        v-slot="{ processing }"
                    >
                        <Button
                            type="submit"
                            variant="secondary"
                            :disabled="processing"
                            :aria-label="`Decline the invitation from ${invitation.companyName}`"
                        >
                            Decline
                        </Button>
                    </Form>
                    <Form
                        v-bind="
                            JoinCompanyController.form({
                                invitation: invitation.id,
                            })
                        "
                        v-slot="{ processing }"
                    >
                        <Button
                            type="submit"
                            :disabled="processing"
                            :aria-label="`Accept the invitation from ${invitation.companyName}`"
                        >
                            Accept
                        </Button>
                    </Form>
                </div>
            </li>
        </ul>
    </div>
</template>
