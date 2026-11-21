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
import { trans } from '@/lib/i18n';

defineOptions({
    layout: () => ({
        breadcrumbs: [
            { title: trans('Invitations'), href: InvitationController.index() },
        ],
    }),
});

defineProps<{
    invitations: Invitation[];
}>();

const page = usePage();

// Without any company, this page is where people land, so it explains how to get in.
const emptyMessage = computed(() =>
    page.props.company === null
        ? trans(
              "You're not in any company yet. When a company invites you, you'll get an email, and the invitation shows up here.",
          )
        : trans('You have no invitations.'),
);
</script>

<template>
    <Head :title="$t('Invitations')" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <Heading
            :title="$t('Invitations')"
            :description="$t('Companies that have invited you to join them')"
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
                        <span class="font-normal text-muted-foreground">{{
                            $t('as :role', { role: invitation.role })
                        }}</span>
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{
                            invitation.invitedBy
                                ? $t('Invited by :name on :date', {
                                      name: invitation.invitedBy,
                                      date: formatDate(invitation.sentOn),
                                  })
                                : $t('Invited on :date', {
                                      date: formatDate(invitation.sentOn),
                                  })
                        }}
                    </p>
                </div>

                <div
                    v-if="invitation.isExpired"
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <Badge variant="outline">{{ $t('Expired') }}</Badge>
                    {{ $t('Ask the company for a new invitation.') }}
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
                            :aria-label="
                                $t('Decline the invitation from :company', {
                                    company: invitation.companyName,
                                })
                            "
                        >
                            {{ $t('Decline') }}
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
                            :aria-label="
                                $t('Accept the invitation from :company', {
                                    company: invitation.companyName,
                                })
                            "
                        >
                            {{ $t('Accept') }}
                        </Button>
                    </Form>
                </div>
            </li>
        </ul>
    </div>
</template>
