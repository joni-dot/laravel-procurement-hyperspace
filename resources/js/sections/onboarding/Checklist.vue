<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Circle, CircleCheck } from '@lucide/vue';
import { ref } from 'vue';
import HideOnboardingChecklistController from '@/actions/App/Http/Controllers/Onboarding/HideOnboardingChecklistController';
import { Button } from '@/components/ui/button';
import type { OnboardingChecklist } from '@/types/onboarding';

/**
 * The getting-started checklist at the top of Home, for the company's admins until every step is done.
 * Each module adds the steps about its own data (App\Onboarding\OnboardingSteps).
 */
defineProps<{
    data: OnboardingChecklist;
}>();

const hiding = ref(false);

// For the whole company. Home reloads only its list of sections and the checklist's two.
function hide(): void {
    router.post(
        HideOnboardingChecklistController.url(),
        {},
        {
            only: [
                'sections',
                'section_onboarding_checklist',
                'section_onboarding_show_again',
            ],
            preserveScroll: true,
            onStart: () => (hiding.value = true),
            onFinish: () => (hiding.value = false),
        },
    );
}
</script>

<template>
    <section
        class="flex flex-col gap-3 rounded-xl border p-4"
        aria-labelledby="home-onboarding-heading"
    >
        <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-1">
            <div class="space-y-0.5">
                <h2 id="home-onboarding-heading" class="text-lg font-semibold">
                    {{ $t('Getting started') }}
                </h2>
                <p
                    class="text-sm text-muted-foreground"
                    data-test="onboarding-progress"
                >
                    {{
                        $t(':done of :total done', {
                            done: data.doneCount,
                            total: data.steps.length,
                        })
                    }}
                </p>
            </div>
            <Button
                variant="ghost"
                size="sm"
                :disabled="hiding"
                data-test="onboarding-hide"
                @click="hide"
            >
                {{ $t('Hide') }}
            </Button>
        </div>

        <ol class="divide-y">
            <li
                v-for="step in data.steps"
                :key="step.key"
                class="flex gap-3 py-3 first:pt-0 last:pb-0"
                :data-step="step.key"
                :data-done="step.done"
            >
                <CircleCheck
                    v-if="step.done"
                    class="mt-0.5 size-5 shrink-0 text-green-600 dark:text-green-500"
                    aria-hidden="true"
                />
                <Circle
                    v-else
                    class="mt-0.5 size-5 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <div class="space-y-0.5">
                    <p
                        v-if="step.done"
                        class="font-medium text-muted-foreground"
                    >
                        {{ step.title }}
                        <span class="sr-only">({{ $t('Done') }})</span>
                    </p>
                    <Link
                        v-else
                        :href="step.url"
                        class="font-medium underline underline-offset-4 hover:text-foreground/80"
                    >
                        {{ step.title }}
                    </Link>
                    <p class="text-sm text-muted-foreground">
                        {{ step.description }}
                    </p>
                </div>
            </li>
        </ol>
    </section>
</template>
