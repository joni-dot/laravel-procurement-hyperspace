<script setup lang="ts">
import { Plus, X } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

const MAX_REMINDERS = 5;

defineProps<{
    disabled?: boolean;
    unitLabel?: string;
}>();

const days = defineModel<number[]>({ required: true });

function addReminder(): void {
    days.value = [...days.value, 7];
}

function removeReminder(index: number): void {
    days.value = days.value.filter((_, position) => position !== index);
}
</script>

<template>
    <div class="space-y-2">
        <div
            v-for="(_, index) in days"
            :key="index"
            class="flex items-center gap-2"
        >
            <Input
                v-model.number="days[index]"
                name="reminder_days[]"
                type="number"
                min="1"
                max="365"
                required
                class="w-24"
                :disabled="disabled"
                :aria-label="
                    $t('Reminder :number, days before expiry', {
                        number: index + 1,
                    })
                "
            />
            <span class="text-sm text-muted-foreground">{{
                unitLabel ?? $t('days before expiry')
            }}</span>
            <Button
                type="button"
                variant="ghost"
                size="icon-sm"
                :disabled="disabled"
                :aria-label="
                    $t('Remove reminder :number', { number: index + 1 })
                "
                @click="removeReminder(index)"
            >
                <X />
            </Button>
        </div>
        <Button
            v-if="days.length < MAX_REMINDERS"
            type="button"
            variant="outline"
            size="sm"
            :disabled="disabled"
            @click="addReminder"
        >
            <Plus /> {{ $t('Add reminder') }}
        </Button>
    </div>
</template>
