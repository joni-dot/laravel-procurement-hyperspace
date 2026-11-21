<script setup lang="ts">
import { TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import RiskOverrideController from '@/actions/App/Modules/Srm/Http/Controllers/Suppliers/RiskOverrideController';
import ConfirmActionDialog from '@/components/ConfirmActionDialog.vue';
import Heading from '@/components/Heading.vue';
import RiskLevelBadge from '@/components/srm/risk/RiskLevelBadge.vue';
import RiskOverrideDialog from '@/components/srm/risk/RiskOverrideDialog.vue';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/format';
import type {
    RiskLevel,
    SelectOption,
    SupplierRiskAssessment,
} from '@/types/srm';
import { trans } from '@/lib/i18n';

const props = defineProps<{
    supplierId: number;
    risk: SupplierRiskAssessment;
    riskLevels: SelectOption[];
    canEdit: boolean;
}>();

const isChanging = ref(false);
const isClearing = ref(false);

const calculatedLevelNames: Record<RiskLevel, () => string> = {
    low: () => trans('low'),
    medium: () => trans('medium'),
    high: () => trans('high'),
};

const overriddenBy = computed(() => {
    const name = props.risk.overridden_by ?? trans('a former user');

    return props.risk.overridden_at
        ? trans('Set by :name on :date.', {
              name,
              date: formatDate(props.risk.overridden_at),
          })
        : trans('Set by :name.', { name });
});

// The sentence stays whole for translators; :level marks where the bold level goes.
const calculatedLevelSentence = computed(() =>
    trans('The calculated level is :level.').split(':level'),
);
</script>

<template>
    <section
        class="space-y-3 rounded-xl border p-6"
        aria-labelledby="risk-heading"
    >
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <Heading
                    id="risk-heading"
                    variant="small"
                    :title="$t('Risk level')"
                />
                <RiskLevelBadge :level="risk.level" />
            </div>
            <div v-if="canEdit" class="flex gap-2">
                <Button
                    v-if="risk.is_overridden"
                    variant="ghost"
                    size="sm"
                    @click="isClearing = true"
                >
                    {{ $t('Use calculated level') }}
                </Button>
                <Button variant="outline" size="sm" @click="isChanging = true">
                    {{ $t('Change risk level') }}
                </Button>
            </div>
        </div>

        <div v-if="risk.is_overridden" class="space-y-1 text-sm">
            <p class="text-muted-foreground">
                {{ overriddenBy }}
                {{ calculatedLevelSentence[0]
                }}<span class="font-medium">{{
                    calculatedLevelNames[risk.calculated_level]()
                }}</span
                >{{ calculatedLevelSentence[1] }}
            </p>
            <p class="whitespace-pre-line">{{ risk.reason }}</p>
        </div>
        <p v-else class="text-sm text-muted-foreground">
            {{
                $t(
                    "Calculated from the supplier's status, latest rating and certificates.",
                )
            }}
        </p>

        <ul v-if="risk.signals.length > 0" class="space-y-1 text-sm">
            <li
                v-for="signal in risk.signals"
                :key="signal.message"
                class="flex items-center gap-2"
            >
                <TriangleAlert class="size-4 shrink-0 text-muted-foreground" />
                {{ signal.message }}
            </li>
        </ul>
    </section>

    <RiskOverrideDialog
        v-if="isChanging"
        :supplier-id="supplierId"
        :current-level="risk.level"
        :current-reason="risk.reason"
        :risk-levels="riskLevels"
        :open="true"
        @update:open="isChanging = false"
    />

    <ConfirmActionDialog
        v-if="isClearing"
        :action="RiskOverrideController.destroy.form({ supplier: supplierId })"
        :title="$t('Use the calculated level?')"
        :description="$t('The manually set level and its reason are removed.')"
        :confirm-label="$t('Use calculated level')"
        :open="true"
        @update:open="isClearing = false"
    />
</template>
