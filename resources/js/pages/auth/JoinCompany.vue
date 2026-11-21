<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InvitationController from '@/actions/App/Http/Controllers/InvitationController';
import JoinCompanyController from '@/actions/App/Http/Controllers/JoinCompanyController';
import { Button } from '@/components/ui/button';
import { trans } from '@/lib/i18n';

defineOptions({
    layout: () => ({
        title: trans('Invitation'),
    }),
});

defineProps<{
    invitation: { id: number; companyName: string; role: string };
}>();
</script>

<template>
    <Head :title="$t('Invitation')" />

    <div class="space-y-6 text-center">
        <h2 class="text-lg font-medium">
            {{
                $t('Join :company as :role', {
                    company: invitation.companyName,
                    role: invitation.role,
                })
            }}
        </h2>

        <div class="flex justify-center gap-2">
            <Form
                v-bind="
                    InvitationController.destroy.form({
                        invitation: invitation.id,
                    })
                "
                v-slot="{ processing }"
            >
                <Button
                    type="submit"
                    variant="secondary"
                    :disabled="processing"
                >
                    {{ $t('Decline') }}
                </Button>
            </Form>
            <Form
                v-bind="
                    JoinCompanyController.form({ invitation: invitation.id })
                "
                v-slot="{ processing }"
            >
                <Button type="submit" :disabled="processing">
                    {{ $t('Accept') }}
                </Button>
            </Form>
        </div>
    </div>
</template>
