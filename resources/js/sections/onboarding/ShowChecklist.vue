<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import ShowOnboardingChecklistController from '@/actions/App/Http/Controllers/Onboarding/ShowOnboardingChecklistController';
import type { OnboardingSummary } from '@/types/onboarding';

/**
 * At the bottom of Home while an admin has hidden the getting-started checklist and steps are still open.
 */
defineProps<{
    data: OnboardingSummary;
}>();

const showing = ref(false);

// For the whole company. Home reloads only its list of sections and the checklist's two.
function show(): void {
    router.post(
        ShowOnboardingChecklistController.url(),
        {},
        {
            only: [
                'sections',
                'section_onboarding_checklist',
                'section_onboarding_show_again',
            ],
            preserveScroll: true,
            onStart: () => (showing.value = true),
            onFinish: () => (showing.value = false),
        },
    );
}
</script>

<template>
    <p class="text-sm text-muted-foreground">
        <button
            type="button"
            class="underline underline-offset-4 hover:text-foreground disabled:opacity-50"
            :disabled="showing"
            data-test="onboarding-show"
            @click="show"
        >
            {{ $t('Show the getting-started checklist') }}
        </button>
        ·
        {{
            $t(':done of :total done', {
                done: data.doneCount,
                total: data.total,
            })
        }}
    </p>
</template>
