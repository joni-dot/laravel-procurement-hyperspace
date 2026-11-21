<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import type { CancelToken } from '@inertiajs/core';
import {
    Check,
    Pencil,
    Plus,
    Search,
    Trash2,
    TriangleAlert,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import PackageSupplierController from '@/actions/App/Modules/Sourcing/Http/Controllers/PackageSupplierController';
import SupplierController from '@/actions/App/Modules/Srm/Http/Controllers/Suppliers/SupplierController';
import ConfirmActionDialog from '@/components/ConfirmActionDialog.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import RiskLevelBadge from '@/components/srm/risk/RiskLevelBadge.vue';
import SupplierStatusBadge from '@/components/srm/suppliers/SupplierStatusBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { riskReasonInWords } from '@/lib/ppr/packageRisk';
import type {
    PackageCandidate,
    PackageSuppliersSection,
    QuoteStatus,
    SupplierMatch,
} from '@/types/sourcing';

/**
 * The suppliers asked for a quote on a package, and the one chosen. Those who may edit the package add,
 * change and remove them; everyone else who can see the package sees the list.
 */
const props = defineProps<{ data: PackageSuppliersSection }>();

const SECTION_PROP = 'section_sourcing_suppliers';

// Searching waits for a short pause in typing, so the server isn't asked again for every letter.
const SEARCH_DELAY_MS = 250;

const MAX_NOTE_LENGTH = 500;

const searchInput = ref('');
const isSearching = ref(false);
let searchTimer: ReturnType<typeof setTimeout> | undefined;
let searchRequest: CancelToken | null = null;

const candidateBeingRemoved = ref<PackageCandidate | null>(null);
const noteBeingEdited = ref<{ id: number; note: string } | null>(null);
const noteError = ref<string | null>(null);
// Redraws the status selects, so a refused change shows the saved status again.
const statusSelectsVersion = ref(0);

const chosenAtRisk = computed(() =>
    props.data.candidates.find((candidate) => candidate.putsPackageAtRisk),
);

const searchTerm = computed(() => searchInput.value.trim());

// Only the answer to what is in the box now, never a late answer to an earlier search.
const matches = computed<SupplierMatch[] | null>(() =>
    searchTerm.value !== '' && props.data.search?.term === searchTerm.value
        ? props.data.search.matches
        : null,
);

function routeOf(candidate: PackageCandidate) {
    return {
        project: props.data.projectId,
        package: props.data.packageId,
        packageSupplier: candidate.id,
    };
}

function cancelSearch(): void {
    clearTimeout(searchTimer);
    searchRequest?.cancel();
    searchRequest = null;
}

// Asks only for this section, with up to 20 matches; the page address stays as it is.
function search(term: string): void {
    router.reload({
        only: [SECTION_PROP],
        data: { supplier_search: term },
        preserveUrl: true,
        onCancelToken: (token) => (searchRequest = token),
        onStart: () => (isSearching.value = true),
        onFinish: () => {
            searchRequest = null;
            isSearching.value = false;
        },
    });
}

watch(searchTerm, (term) => {
    cancelSearch();

    if (term !== '') {
        searchTimer = setTimeout(() => search(term), SEARCH_DELAY_MS);
    }
});

onBeforeUnmount(cancelSearch);

function add(match: SupplierMatch): void {
    router.post(
        PackageSupplierController.store.url({
            project: props.data.projectId,
            package: props.data.packageId,
        }),
        { supplier_id: match.id, status: 'asked' },
        {
            preserveScroll: true,
            onSuccess: () => (searchInput.value = ''),
            onError: (errors) =>
                toast.error(errors.supplier_id ?? errors.status),
        },
    );
}

function save(
    candidate: PackageCandidate,
    changes: { status: QuoteStatus; note: string | null },
    onSuccess?: () => void,
): void {
    router.put(
        PackageSupplierController.update.url(routeOf(candidate)),
        changes,
        {
            preserveScroll: true,
            onSuccess,
            onError: (errors) => {
                if (errors.note) {
                    noteError.value = errors.note;
                }

                if (errors.status) {
                    toast.error(errors.status);
                }

                statusSelectsVersion.value++;
            },
        },
    );
}

function changeStatus(candidate: PackageCandidate, status: QuoteStatus): void {
    save(candidate, { status, note: candidate.note });
}

function editNote(candidate: PackageCandidate): void {
    noteError.value = null;
    noteBeingEdited.value = { id: candidate.id, note: candidate.note ?? '' };
}

function saveNote(candidate: PackageCandidate): void {
    const note = noteBeingEdited.value?.note.trim() ?? '';

    save(
        candidate,
        { status: candidate.status, note: note === '' ? null : note },
        () => (noteBeingEdited.value = null),
    );
}

// A supplier archived or blocked after it was added stays listed, but can't become the chosen one.
function cannotBeChosen(candidate: PackageCandidate): boolean {
    return (
        candidate.status !== 'chosen' &&
        (candidate.supplier.isArchived ||
            candidate.supplier.status === 'blocked')
    );
}
</script>

<template>
    <section class="space-y-4" :aria-label="$t('Suppliers')">
        <Heading variant="small" :title="$t('Suppliers')" class="mb-0" />

        <p
            v-if="chosenAtRisk"
            class="flex items-start gap-2 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-900/30 dark:text-amber-100"
            data-test="chosen-supplier-warning"
        >
            <TriangleAlert class="mt-0.5 size-4 shrink-0" />
            <span>
                {{ riskReasonInWords('supplier_at_risk') }}:
                <strong>{{ chosenAtRisk.supplier.name }}</strong>
            </span>
        </p>

        <p
            v-if="data.candidates.length === 0"
            class="text-sm text-muted-foreground"
        >
            {{ $t('No suppliers have been asked for a quote yet.') }}
        </p>

        <div v-else class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium">
                            {{ $t('Supplier') }}
                        </th>
                        <th scope="col" class="px-4 py-3 font-medium">
                            {{ $t('Supplier risk') }}
                        </th>
                        <th scope="col" class="px-4 py-3 font-medium">
                            {{ $t('Quote') }}
                        </th>
                        <th scope="col" class="px-4 py-3 font-medium">
                            {{ $t('Note') }}
                        </th>
                        <th v-if="data.canManage" scope="col" class="px-4 py-3">
                            <span class="sr-only">{{ $t('Actions') }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr
                        v-for="candidate in data.candidates"
                        :key="candidate.id"
                        :class="{
                            'bg-muted/40': candidate.status === 'chosen',
                        }"
                        :data-test="`candidate-${candidate.supplier.id}`"
                    >
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <Link
                                    :href="
                                        SupplierController.show({
                                            supplier: candidate.supplier.id,
                                        })
                                    "
                                    class="font-medium hover:underline"
                                >
                                    {{ candidate.supplier.name }}
                                </Link>
                                <Badge
                                    v-if="candidate.status === 'chosen'"
                                    data-test="chosen-badge"
                                >
                                    <Check />
                                    {{ $t('Chosen') }}
                                </Badge>
                            </div>
                            <div
                                class="mt-1 flex flex-wrap items-center gap-2 text-muted-foreground"
                            >
                                <span
                                    v-if="candidate.supplier.number"
                                    class="font-mono"
                                >
                                    {{ candidate.supplier.number }}
                                </span>
                                <SupplierStatusBadge
                                    :status="candidate.supplier.status"
                                />
                                <Badge
                                    v-if="candidate.supplier.isArchived"
                                    variant="outline"
                                >
                                    {{ $t('Archived') }}
                                </Badge>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <RiskLevelBadge
                                :level="candidate.supplier.riskLevel"
                            />
                        </td>
                        <td class="px-4 py-3">
                            <NativeSelect
                                v-if="data.canManage"
                                :key="`${candidate.id}-${statusSelectsVersion}`"
                                :model-value="candidate.status"
                                :aria-label="
                                    $t('Quote status of :name', {
                                        name: candidate.supplier.name,
                                    })
                                "
                                class="min-w-40"
                                @update:model-value="
                                    changeStatus(
                                        candidate,
                                        $event as QuoteStatus,
                                    )
                                "
                            >
                                <option
                                    v-for="option in data.statuses"
                                    :key="option.value"
                                    :value="option.value"
                                    :disabled="
                                        option.value === 'chosen' &&
                                        cannotBeChosen(candidate)
                                    "
                                >
                                    {{ option.label }}
                                </option>
                            </NativeSelect>
                            <template v-else>
                                {{ candidate.statusLabel }}
                            </template>
                        </td>
                        <td class="px-4 py-3">
                            <form
                                v-if="noteBeingEdited?.id === candidate.id"
                                class="space-y-2"
                                @submit.prevent="saveNote(candidate)"
                            >
                                <Textarea
                                    v-model="noteBeingEdited.note"
                                    :aria-label="
                                        $t('Note about :name', {
                                            name: candidate.supplier.name,
                                        })
                                    "
                                    :maxlength="MAX_NOTE_LENGTH"
                                    rows="2"
                                    class="min-w-56"
                                />
                                <InputError :message="noteError ?? undefined" />
                                <div class="flex gap-2">
                                    <Button type="submit" size="sm">
                                        {{ $t('Save note') }}
                                    </Button>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        @click="noteBeingEdited = null"
                                    >
                                        {{ $t('Cancel') }}
                                    </Button>
                                </div>
                            </form>
                            <p v-else class="whitespace-pre-line">
                                {{ candidate.note ?? '–' }}
                            </p>
                        </td>
                        <td
                            v-if="data.canManage"
                            class="px-4 py-3 text-right whitespace-nowrap"
                        >
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                :aria-label="
                                    $t('Edit the note about :name', {
                                        name: candidate.supplier.name,
                                    })
                                "
                                @click="editNote(candidate)"
                            >
                                <Pencil />
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                :aria-label="
                                    $t('Remove :name', {
                                        name: candidate.supplier.name,
                                    })
                                "
                                @click="candidateBeingRemoved = candidate"
                            >
                                <Trash2 />
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="data.canManage" class="max-w-xl space-y-2">
            <Label for="supplier_search">{{ $t('Add supplier') }}</Label>
            <div class="relative">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    id="supplier_search"
                    v-model="searchInput"
                    type="search"
                    maxlength="100"
                    autocomplete="off"
                    :placeholder="$t('Search by name or number')"
                    class="pl-9"
                />
                <Spinner
                    v-if="isSearching"
                    class="absolute top-1/2 right-3 -translate-y-1/2"
                />
            </div>

            <ul
                v-if="matches && matches.length > 0"
                class="divide-y rounded-xl border"
                :aria-label="$t('Matching suppliers')"
            >
                <li
                    v-for="match in matches"
                    :key="match.id"
                    class="flex items-center justify-between gap-4 px-4 py-2 text-sm"
                >
                    <span>
                        <span class="font-medium">{{ match.name }}</span>
                        <span
                            v-if="match.number"
                            class="ml-2 font-mono text-muted-foreground"
                        >
                            {{ match.number }}
                        </span>
                    </span>
                    <Button
                        variant="outline"
                        size="sm"
                        :aria-label="$t('Add :name', { name: match.name })"
                        @click="add(match)"
                    >
                        <Plus />
                        {{ $t('Add') }}
                    </Button>
                </li>
            </ul>
            <p v-else-if="matches" class="text-sm text-muted-foreground">
                {{
                    $t(
                        'No supplier matches. Archived and blocked suppliers, and those already on the package, are left out.',
                    )
                }}
            </p>
        </div>

        <ConfirmActionDialog
            v-if="candidateBeingRemoved"
            :action="
                PackageSupplierController.destroy.form(
                    routeOf(candidateBeingRemoved),
                )
            "
            :title="
                $t('Remove :name from the package?', {
                    name: candidateBeingRemoved.supplier.name,
                })
            "
            :description="
                candidateBeingRemoved.status === 'chosen'
                    ? $t(
                          'It is the chosen supplier, so the package will have none. The supplier itself is kept.',
                      )
                    : $t(
                          'Its quote status and note are removed. The supplier itself is kept.',
                      )
            "
            :confirm-label="$t('Remove')"
            :open="true"
            @update:open="candidateBeingRemoved = null"
        />
    </section>
</template>
