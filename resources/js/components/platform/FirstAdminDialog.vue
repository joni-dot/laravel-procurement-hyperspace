<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import InviteFirstAdminController from '@/actions/App/Http/Controllers/Platform/InviteFirstAdminController';
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
import { Spinner } from '@/components/ui/spinner';
import type { PlatformCompanyRow } from '@/types';

const props = defineProps<{
    company: PlatformCompanyRow;
}>();

const isOpen = defineModel<boolean>('open', { required: true });

const action = InviteFirstAdminController.form({ company: props.company.id });
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
                <DialogHeader>
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
                    <Button type="submit" :disabled="processing">
                        <Spinner v-if="processing" />
                        {{ $t('Send invitation') }}
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
