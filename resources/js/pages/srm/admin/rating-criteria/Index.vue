<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { ArchiveRestore, Pencil, Plus, Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import RatingCriterionController from '@/actions/App/Modules/Srm/Http/Controllers/Admin/RatingCriterionController';
import RestoreRatingCriterionController from '@/actions/App/Modules/Srm/Http/Controllers/Admin/RestoreRatingCriterionController';
import UpdateRatingWeightsController from '@/actions/App/Modules/Srm/Http/Controllers/Admin/UpdateRatingWeightsController';
import ConfirmActionDialog from '@/components/ConfirmActionDialog.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import RatingCriterionDialog from '@/components/srm/ratings/RatingCriterionDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { trans } from '@/lib/i18n';

type CriterionRow = {
    id: number;
    name: string;
    weight: number;
    retired_at: string | null;
    scores_count: number;
};

defineOptions({
    layout: () => ({
        breadcrumbs: [
            {
                title: trans('Rating criteria'),
                href: RatingCriterionController.index(),
            },
        ],
    }),
});

const props = defineProps<{
    criteria: CriterionRow[];
}>();

const MAX_TOTAL_WEIGHT = 100;

const isAdding = ref(false);
const criterionBeingRenamed = ref<CriterionRow | null>(null);
const criterionBeingRemoved = ref<CriterionRow | null>(null);

const weights = ref<Record<number, number | string>>({});

// Keeps unsaved weights, unless the server changed that weight itself.
watch(
    () => props.criteria,
    (criteria, previousCriteria) => {
        weights.value = Object.fromEntries(
            criteria.map((criterion) => {
                const previous = previousCriteria?.find(
                    ({ id }) => id === criterion.id,
                );

                return [
                    criterion.id,
                    previous?.weight === criterion.weight
                        ? weights.value[criterion.id]
                        : criterion.weight,
                ];
            }),
        );
    },
    { immediate: true },
);

const totalWeightInUse = computed(() =>
    props.criteria
        .filter((criterion) => !criterion.retired_at)
        .reduce(
            (total, criterion) =>
                total + (Number(weights.value[criterion.id]) || 0),
            0,
        ),
);

const isOverMaximum = computed(() => totalWeightInUse.value > MAX_TOTAL_WEIGHT);

function share(criterion: CriterionRow): string {
    if (criterion.retired_at) {
        return trans('Old ratings only');
    }

    const weight = Number(weights.value[criterion.id]) || 0;

    if (weight === 0) {
        return trans('Does not count');
    }

    return trans(':percent% of the score', {
        percent: Math.round((weight / totalWeightInUse.value) * 100),
    });
}

function usage(criterion: CriterionRow): string {
    return criterion.scores_count === 1
        ? trans('Used in 1 rating')
        : trans('Used in :count ratings', { count: criterion.scores_count });
}

function removalDescription(criterion: CriterionRow): string {
    return criterion.scores_count > 0
        ? trans(
              'Old ratings keep it, so it is retired rather than deleted. New ratings will not use it. Overall scores of old ratings do not change.',
          )
        : trans('No ratings use it, so it is deleted permanently.');
}

function restore(criterion: CriterionRow): void {
    router.post(
        RestoreRatingCriterionController.url({ ratingCriterion: criterion.id }),
        {},
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head :title="$t('Rating criteria')" />

    <div class="flex max-w-3xl flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                :title="$t('Rating criteria')"
                :description="$t('What buyers score suppliers on, from 1 to 5')"
                class="mb-0"
            />
            <Button @click="isAdding = true">
                <Plus /> {{ $t('Add criterion') }}
            </Button>
        </div>

        <p v-if="criteria.length === 0" class="text-sm text-muted-foreground">
            {{ $t('No criteria yet.') }}
        </p>

        <Form
            v-else
            v-bind="UpdateRatingWeightsController.form()"
            :options="{ preserveScroll: true }"
            class="space-y-4"
            v-slot="{ errors, processing }"
        >
            <ul class="divide-y rounded-xl border">
                <li
                    v-for="criterion in criteria"
                    :key="criterion.id"
                    class="flex flex-wrap items-center justify-between gap-4 px-4 py-3 text-sm"
                >
                    <div class="min-w-40 flex-1">
                        <p class="font-medium">
                            {{ criterion.name }}
                            <Badge
                                v-if="criterion.retired_at"
                                variant="outline"
                                class="ml-1"
                                >{{ $t('Retired') }}</Badge
                            >
                        </p>
                        <p class="text-muted-foreground">
                            {{ usage(criterion) }}
                        </p>
                    </div>
                    <div class="w-52">
                        <div class="flex items-center gap-2">
                            <Input
                                v-model="weights[criterion.id]"
                                :name="`weights[${criterion.id}]`"
                                :aria-label="
                                    $t('Weight of :name', {
                                        name: criterion.name,
                                    })
                                "
                                type="number"
                                min="0"
                                max="100"
                                step="1"
                                required
                                class="w-20"
                            />
                            <span
                                class="whitespace-nowrap text-muted-foreground"
                            >
                                {{ share(criterion) }}
                            </span>
                        </div>
                        <InputError
                            :message="errors[`weights.${criterion.id}`]"
                        />
                    </div>
                    <div class="flex">
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            :aria-label="
                                $t('Rename :name', { name: criterion.name })
                            "
                            @click="criterionBeingRenamed = criterion"
                        >
                            <Pencil />
                        </Button>
                        <Button
                            v-if="criterion.retired_at"
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            :aria-label="
                                $t('Bring back :name', { name: criterion.name })
                            "
                            @click="restore(criterion)"
                        >
                            <ArchiveRestore />
                        </Button>
                        <Button
                            v-else
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            :aria-label="
                                $t('Remove :name', { name: criterion.name })
                            "
                            @click="criterionBeingRemoved = criterion"
                        >
                            <Trash2 />
                        </Button>
                    </div>
                </li>
            </ul>

            <div>
                <p
                    class="text-sm font-medium"
                    :class="{ 'text-destructive': isOverMaximum }"
                >
                    {{
                        $t('Total: :total% of :maximum%', {
                            total: totalWeightInUse,
                            maximum: MAX_TOTAL_WEIGHT,
                        })
                    }}
                </p>
                <p v-if="isOverMaximum" class="text-sm text-destructive">
                    {{
                        $t('Lower the weights by :excess% to save.', {
                            excess: totalWeightInUse - MAX_TOTAL_WEIGHT,
                        })
                    }}
                </p>
                <InputError v-else :message="errors.weights" />
            </div>

            <div class="flex flex-wrap items-center justify-between gap-4">
                <p class="max-w-md text-sm text-muted-foreground">
                    {{
                        $t(
                            'A bigger weight makes a criterion count more. Saving recalculates the overall score of every rating and every risk level.',
                        )
                    }}
                </p>
                <Button type="submit" :disabled="processing || isOverMaximum">
                    <Spinner v-if="processing" />
                    {{ $t('Save weights') }}
                </Button>
            </div>
        </Form>
    </div>

    <RatingCriterionDialog
        v-if="isAdding"
        :open="true"
        @update:open="isAdding = false"
    />

    <RatingCriterionDialog
        v-if="criterionBeingRenamed"
        :criterion="criterionBeingRenamed"
        :open="true"
        @update:open="criterionBeingRenamed = null"
    />

    <ConfirmActionDialog
        v-if="criterionBeingRemoved"
        :action="
            RatingCriterionController.destroy.form({
                ratingCriterion: criterionBeingRemoved.id,
            })
        "
        :title="$t('Remove :name?', { name: criterionBeingRemoved.name })"
        :description="removalDescription(criterionBeingRemoved)"
        :confirm-label="$t('Remove')"
        :open="true"
        @update:open="criterionBeingRemoved = null"
    />
</template>
