<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InvitationController from '@/actions/App/Http/Controllers/InvitationController';
import JoinCompanyController from '@/actions/App/Http/Controllers/JoinCompanyController';
import { Button } from '@/components/ui/button';

defineOptions({
    layout: {
        title: 'Invitation',
    },
});

defineProps<{
    invitation: { id: number; companyName: string; role: string };
}>();
</script>

<template>
    <Head title="Invitation" />

    <div class="space-y-6 text-center">
        <h2 class="text-lg font-medium">
            Join {{ invitation.companyName }} as {{ invitation.role }}
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
                    Decline
                </Button>
            </Form>
            <Form
                v-bind="
                    JoinCompanyController.form({ invitation: invitation.id })
                "
                v-slot="{ processing }"
            >
                <Button type="submit" :disabled="processing">Accept</Button>
            </Form>
        </div>
    </div>
</template>
