<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import AcceptInvitationController from '@/actions/App/Http/Controllers/Auth/AcceptInvitationController';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { trans } from '@/lib/i18n';

defineOptions({
    layout: () => ({
        title: trans('Welcome aboard'),
        description: trans(
            'Choose a password to finish setting up your account',
        ),
    }),
});

defineProps<{
    token: string;
    email: string;
    name: string;
    passwordRules: string;
}>();
</script>

<template>
    <Head :title="$t('Set your password')" />

    <Form
        v-bind="AcceptInvitationController.store.form()"
        :transform="(data) => ({ ...data, token })"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
    >
        <div class="grid gap-6">
            <InputError :message="errors.token" />

            <div class="grid gap-2">
                <Label for="email">{{ $t('Email') }}</Label>
                <Input
                    id="email"
                    type="email"
                    autocomplete="username"
                    :default-value="email"
                    readonly
                />
            </div>

            <div class="grid gap-2">
                <Label for="name">{{ $t('Name') }}</Label>
                <Input
                    id="name"
                    name="name"
                    autocomplete="name"
                    :default-value="name"
                    required
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="password">{{ $t('Password') }}</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    autocomplete="new-password"
                    autofocus
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">{{
                    $t('Confirm password')
                }}</Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    autocomplete="new-password"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <Button type="submit" class="mt-4 w-full" :disabled="processing">
                <Spinner v-if="processing" />
                {{ $t('Set password and join') }}
            </Button>
        </div>
    </Form>
</template>
