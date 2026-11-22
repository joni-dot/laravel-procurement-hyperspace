<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { Download, FileUp, Upload } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import DownloadSupplierImportTemplateController from '@/actions/App/Modules/Srm/Http/Controllers/Suppliers/DownloadSupplierImportTemplateController';
import PreviewSupplierImportController from '@/actions/App/Modules/Srm/Http/Controllers/Suppliers/PreviewSupplierImportController';
import SupplierController from '@/actions/App/Modules/Srm/Http/Controllers/Suppliers/SupplierController';
import SupplierImportController from '@/actions/App/Modules/Srm/Http/Controllers/Suppliers/SupplierImportController';
import FormField from '@/components/FormField.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import type {
    SupplierImportColumn,
    SupplierImportPreview,
    SupplierImportProblem,
} from '@/types/srm';
import { trans } from '@/lib/i18n';

defineOptions({
    layout: () => ({
        breadcrumbs: [
            { title: trans('Suppliers'), href: SupplierController.index() },
            {
                title: trans('Import suppliers'),
                href: SupplierImportController.create(),
            },
        ],
    }),
});

defineProps<{
    columns: SupplierImportColumn[];
    maxRows: number;
    canCreateCategories: boolean;
}>();

const page = usePage();

// The file stays in the browser: the preview reads it, and "Import" sends it again.
const form = useForm<{ file: File | null }>({ file: null });
const fileInput = ref<HTMLInputElement | null>(null);
const preview = ref<SupplierImportPreview | null>(null);

// The server sends the preview once, as flash data.
watch(
    () => page.flash.supplierImportPreview as SupplierImportPreview | undefined,
    (sent) => {
        if (sent) {
            preview.value = sent;
        }
    },
    { immediate: true },
);

const csvTemplateUrl = DownloadSupplierImportTemplateController.url();
const excelTemplateUrl = DownloadSupplierImportTemplateController.url(
    {},
    { query: { format: 'xlsx' } },
);

const hiddenProblems = computed(() =>
    preview.value
        ? preview.value.problemCount - preview.value.problems.length
        : 0,
);

function chooseFile(event: Event): void {
    form.file = (event.target as HTMLInputElement).files?.[0] ?? null;
    form.clearErrors();
    preview.value = null;
}

function checkFile(): void {
    preview.value = null;
    form.post(PreviewSupplierImportController.url(), {
        preserveScroll: true,
        preserveState: true,
    });
}

function importFile(): void {
    form.post(SupplierImportController.store.url(), { preserveScroll: true });
}

function chooseAnotherFile(): void {
    form.reset();
    preview.value = null;

    if (fileInput.value) {
        fileInput.value.value = '';
        fileInput.value.click();
    }
}

function kindLabel(kind: SupplierImportProblem['kind']): string {
    return {
        error: trans('Error'),
        skipped: trans('Skipped'),
        warning: trans('Warning'),
    }[kind];
}

function kindVariant(
    kind: SupplierImportProblem['kind'],
): 'destructive' | 'secondary' | 'outline' {
    return (
        {
            error: 'destructive',
            skipped: 'secondary',
            warning: 'outline',
        } as const
    )[kind];
}
</script>

<template>
    <Head :title="$t('Import suppliers')" />

    <div class="flex max-w-5xl flex-1 flex-col gap-8 p-4">
        <Heading
            :title="$t('Import suppliers')"
            :description="
                $t(
                    'Add many suppliers at once from a CSV file or an Excel workbook (.xlsx). Suppliers you already have are never changed.',
                )
            "
            class="mb-0"
        />

        <section class="flex flex-col gap-4">
            <Heading
                variant="small"
                :title="$t('1. Fill in the template')"
                :description="
                    $t(
                        'The first row names the columns. Only Name and Country are required; other columns may be left out.',
                    )
                "
                class="mb-0"
            />
            <div class="flex flex-wrap gap-3">
                <Button variant="outline" as-child>
                    <a :href="excelTemplateUrl" download>
                        <Download /> {{ $t('Excel template') }}
                    </a>
                </Button>
                <Button variant="outline" as-child>
                    <a :href="csvTemplateUrl" download>
                        <Download /> {{ $t('CSV template') }}
                    </a>
                </Button>
            </div>

            <details class="rounded-xl border text-sm">
                <summary class="cursor-pointer px-4 py-3 font-medium">
                    {{ $t('What goes in each column') }}
                </summary>
                <table class="w-full">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-4 py-2 font-medium">
                                {{ $t('Column') }}
                            </th>
                            <th scope="col" class="px-4 py-2 font-medium">
                                {{ $t('What to enter') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="column in columns" :key="column.header">
                            <td class="px-4 py-2 font-mono text-xs">
                                {{ column.header }}
                            </td>
                            <td class="px-4 py-2 text-muted-foreground">
                                {{ column.hint }}
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p class="border-t px-4 py-3 text-muted-foreground">
                    {{
                        canCreateCategories
                            ? $t(
                                  'Category names the company does not have yet are added to its categories.',
                              )
                            : $t(
                                  'Categories must already exist; an admin can add new ones.',
                              )
                    }}
                </p>
            </details>
        </section>

        <section class="flex flex-col gap-4">
            <Heading
                variant="small"
                :title="$t('2. Check the file')"
                :description="
                    $t(
                        'Nothing is saved yet: you see first what the file would add.',
                    )
                "
                class="mb-0"
            />
            <form class="flex flex-col gap-4" @submit.prevent="checkFile">
                <FormField
                    id="import-file"
                    :label="$t('File')"
                    :hint="
                        $t('CSV or Excel (.xlsx), up to 5 MB and :rows rows', {
                            rows: maxRows.toLocaleString(),
                        })
                    "
                    :error="form.errors.file"
                >
                    <input
                        id="import-file"
                        ref="fileInput"
                        type="file"
                        name="file"
                        required
                        accept=".csv,.txt,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                        class="text-sm file:mr-3 file:rounded-md file:border file:border-input file:bg-transparent file:px-3 file:py-1.5 file:text-sm file:font-medium"
                        @change="chooseFile"
                    />
                </FormField>
                <div>
                    <Button
                        type="submit"
                        variant="secondary"
                        :disabled="!form.file || form.processing"
                    >
                        <Spinner v-if="form.processing && !preview" />
                        <FileUp v-else />
                        {{ $t('Check file') }}
                    </Button>
                </div>
            </form>
        </section>

        <section v-if="preview" class="flex flex-col gap-4" aria-live="polite">
            <Heading
                variant="small"
                :title="$t('3. Import')"
                :description="preview.fileName"
                class="mb-0"
            />

            <dl class="grid gap-3 sm:grid-cols-3">
                <div class="rounded-xl border p-4">
                    <dt class="text-sm text-muted-foreground">
                        {{ $t('Will be added') }}
                    </dt>
                    <dd class="text-2xl font-semibold" data-test="count-add">
                        {{ preview.counts.add }}
                    </dd>
                </div>
                <div class="rounded-xl border p-4">
                    <dt class="text-sm text-muted-foreground">
                        {{ $t('Skipped') }}
                    </dt>
                    <dd
                        class="text-2xl font-semibold"
                        data-test="count-skipped"
                    >
                        {{ preview.counts.skipped }}
                    </dd>
                </div>
                <div class="rounded-xl border p-4">
                    <dt class="text-sm text-muted-foreground">
                        {{ $t('Have errors') }}
                    </dt>
                    <dd class="text-2xl font-semibold" data-test="count-errors">
                        {{ preview.counts.errors }}
                    </dd>
                </div>
            </dl>

            <p
                v-if="preview.ignoredColumns.length > 0"
                class="text-sm text-muted-foreground"
            >
                {{
                    $t('Columns that are not imported: :columns', {
                        columns: preview.ignoredColumns.join(', '),
                    })
                }}
            </p>
            <p
                v-if="preview.newCategories.length > 0"
                class="text-sm text-muted-foreground"
            >
                {{
                    $t('New categories that will be created: :categories', {
                        categories: preview.newCategories.join(', '),
                    })
                }}
            </p>

            <div
                v-if="preview.problems.length > 0"
                class="overflow-x-auto rounded-xl border"
            >
                <table class="w-full text-sm">
                    <caption class="px-4 py-3 text-left font-medium">
                        {{
                            $t(
                                'Rows with errors are left out; fix them in the file and check it again if you want them.',
                            )
                        }}
                    </caption>
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-4 py-2 font-medium">
                                {{ $t('Row') }}
                            </th>
                            <th scope="col" class="px-4 py-2 font-medium">
                                {{ $t('Column') }}
                            </th>
                            <th scope="col" class="px-4 py-2 font-medium">
                                {{ $t('Problem') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="(problem, index) in preview.problems"
                            :key="index"
                            :data-test="`problem-${problem.kind}`"
                        >
                            <td class="px-4 py-2 whitespace-nowrap">
                                {{ problem.row }}
                            </td>
                            <td class="px-4 py-2 text-muted-foreground">
                                {{ problem.column ?? '—' }}
                            </td>
                            <td class="px-4 py-2">
                                <Badge
                                    :variant="kindVariant(problem.kind)"
                                    class="mr-2"
                                >
                                    {{ kindLabel(problem.kind) }}
                                </Badge>
                                {{ problem.message }}
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p
                    v-if="hiddenProblems > 0"
                    class="border-t px-4 py-3 text-sm text-muted-foreground"
                >
                    {{
                        $t('…and :count more.', {
                            count: hiddenProblems.toLocaleString(),
                        })
                    }}
                </p>
            </div>

            <div
                v-if="preview.suppliers.length > 0"
                class="overflow-x-auto rounded-xl border"
            >
                <table class="w-full text-sm">
                    <caption class="px-4 py-3 text-left font-medium">
                        {{
                            $t('First suppliers to be added')
                        }}
                    </caption>
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-4 py-2 font-medium">
                                {{ $t('Row') }}
                            </th>
                            <th scope="col" class="px-4 py-2 font-medium">
                                {{ $t('Name') }}
                            </th>
                            <th scope="col" class="px-4 py-2 font-medium">
                                {{ $t('Supplier no.') }}
                            </th>
                            <th scope="col" class="px-4 py-2 font-medium">
                                {{ $t('Location') }}
                            </th>
                            <th scope="col" class="px-4 py-2 font-medium">
                                {{ $t('Primary contact') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="supplier in preview.suppliers"
                            :key="supplier.row"
                        >
                            <td class="px-4 py-2">{{ supplier.row }}</td>
                            <td class="px-4 py-2 font-medium">
                                {{ supplier.name }}
                            </td>
                            <td class="px-4 py-2 text-muted-foreground">
                                {{ supplier.supplier_number }}
                            </td>
                            <td class="px-4 py-2">
                                {{
                                    [supplier.city, supplier.country]
                                        .filter(Boolean)
                                        .join(', ')
                                }}
                            </td>
                            <td class="px-4 py-2 text-muted-foreground">
                                {{ supplier.contact }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex flex-wrap gap-3">
                <Button
                    :disabled="preview.counts.add === 0 || form.processing"
                    @click="importFile"
                >
                    <Spinner v-if="form.processing" />
                    <Upload v-else />
                    {{ $t('Import') }}
                </Button>
                <Button
                    variant="outline"
                    :disabled="form.processing"
                    @click="chooseAnotherFile"
                >
                    {{ $t('Choose another file') }}
                </Button>
            </div>
        </section>
    </div>
</template>
