<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import UpdateNotificationDaysController from '@/actions/App/Modules/Ppr/Http/Controllers/Settings/UpdateNotificationDaysController';
import FormField from '@/components/FormField.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';

/**
 * The projects' part of Settings → Notifications: how many days ahead the person is reminded of their packages' dates.
 */
defineProps<{
    data: { notificationDays: number; maxNotificationDays: number };
}>();
</script>

<template>
    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            :title="$t('Project dates')"
            :description="
                $t(
                    'You are notified when you become a package\'s owner or responsible buyer, and about the dates of the packages you own or buy.',
                )
            "
        />

        <Form
            v-bind="UpdateNotificationDaysController.form()"
            :options="{ preserveScroll: true }"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <FormField
                id="notification_days"
                :label="$t('Remind me this many days before a date')"
                :hint="
                    $t(
                        'With 7, you are reminded a week before a milestone of your package is due (its forecast, else its planned date). 0 turns these reminders off; you are still told when a date has passed.',
                    )
                "
                :error="errors.notification_days"
            >
                <Input
                    id="notification_days"
                    type="number"
                    name="notification_days"
                    class="max-w-32"
                    :default-value="data.notificationDays"
                    min="0"
                    :max="data.maxNotificationDays"
                    required
                />
            </FormField>

            <Button type="submit" :disabled="processing">
                <Spinner v-if="processing" />
                {{ $t('Save reminder period') }}
            </Button>
        </Form>
    </div>
</template>
