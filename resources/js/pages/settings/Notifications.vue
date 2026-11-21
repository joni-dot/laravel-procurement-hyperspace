<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import NotificationSettingsController from '@/actions/App/Http/Controllers/Settings/NotificationSettingsController';
import FormField from '@/components/FormField.vue';
import Heading from '@/components/Heading.vue';
import PageSections from '@/components/PageSections.vue';
import { Button } from '@/components/ui/button';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { trans } from '@/lib/i18n';
import { edit } from '@/routes/notification-settings';
import type { NotificationEmail } from '@/types';
import type { PageSection } from '@/types/sections';

defineOptions({
    layout: () => ({
        breadcrumbs: [
            {
                title: trans('Notification settings'),
                href: edit(),
            },
        ],
    }),
});

const props = defineProps<{
    notificationEmail: NotificationEmail;
    emailOptions: { value: NotificationEmail; label: string }[];
    sections: PageSection[];
}>();

const notificationEmail = ref<string>(props.notificationEmail);
</script>

<template>
    <Head :title="$t('Notification settings')" />

    <h1 class="sr-only">{{ $t('Notification settings') }}</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            :title="$t('Email')"
            :description="
                $t(
                    'Your notifications are always in the app, under the bell. You can also get them by email: one summary each morning per company, listing the unread notifications that arrived since the last one.',
                )
            "
        />

        <Form
            v-bind="NotificationSettingsController.update.form()"
            :options="{ preserveScroll: true }"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <FormField
                id="notification_email"
                :label="$t('Notifications by email')"
                :error="errors.notification_email"
            >
                <NativeSelect
                    id="notification_email"
                    v-model="notificationEmail"
                    name="notification_email"
                    class="max-w-60"
                    required
                >
                    <option
                        v-for="option in emailOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </NativeSelect>
            </FormField>

            <Button type="submit" :disabled="processing">
                <Spinner v-if="processing" />
                {{ $t('Save email choice') }}
            </Button>
        </Form>
    </div>

    <PageSections :sections="sections" />
</template>
