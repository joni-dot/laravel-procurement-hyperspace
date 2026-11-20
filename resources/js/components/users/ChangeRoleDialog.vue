<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import MembershipController from '@/actions/App/Http/Controllers/Admin/MembershipController';
import InputError from '@/components/InputError.vue';
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
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import RoleSelect from '@/components/users/RoleSelect.vue';
import type { Member, RoleOption } from '@/types';

defineProps<{
    member: Member;
    roles: RoleOption[];
}>();

const isOpen = defineModel<boolean>('open', { required: true });
</script>

<template>
    <Dialog v-model:open="isOpen">
        <DialogContent>
            <Form
                v-bind="
                    MembershipController.update.form({ membership: member.id })
                "
                :options="{ preserveScroll: true }"
                @success="isOpen = false"
                class="space-y-6"
                v-slot="{ errors, processing }"
            >
                <DialogHeader>
                    <DialogTitle>Change role of {{ member.name }}</DialogTitle>
                    <DialogDescription>{{ member.email }}</DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="change-role">Role</Label>
                    <RoleSelect
                        id="change-role"
                        :roles="roles"
                        :default-value="member.role"
                    />
                    <InputError :message="errors.role" />
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="secondary"
                            >Cancel</Button
                        >
                    </DialogClose>
                    <Button type="submit" :disabled="processing">
                        <Spinner v-if="processing" />
                        Save
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
