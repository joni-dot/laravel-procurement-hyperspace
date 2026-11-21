<script setup lang="ts">
import { Head, Link, router, setLayoutProps } from '@inertiajs/vue3';
import { Archive, ArchiveRestore, Pencil, TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import ArchiveSupplierController from '@/actions/App/Modules/Srm/Http/Controllers/Suppliers/ArchiveSupplierController';
import RestoreSupplierController from '@/actions/App/Modules/Srm/Http/Controllers/Suppliers/RestoreSupplierController';
import DocumentController from '@/actions/App/Modules/Srm/Http/Controllers/Suppliers/DocumentController';
import SupplierController from '@/actions/App/Modules/Srm/Http/Controllers/Suppliers/SupplierController';
import RatingList from '@/components/srm/ratings/RatingList.vue';
import SupplierRiskPanel from '@/components/srm/risk/SupplierRiskPanel.vue';
import SpendList from '@/components/srm/spend/SpendList.vue';
import CertificateList from '@/components/srm/certificates/CertificateList.vue';
import CommentSection from '@/components/srm/comments/CommentSection.vue';
import ConfirmActionDialog from '@/components/ConfirmActionDialog.vue';
import Heading from '@/components/Heading.vue';
import ContractList from '@/components/srm/contracts/ContractList.vue';
import DocumentList from '@/components/srm/documents/DocumentList.vue';
import ContactList from '@/components/srm/suppliers/ContactList.vue';
import DetailItem from '@/components/srm/suppliers/DetailItem.vue';
import SupplierStatusBadge from '@/components/srm/suppliers/SupplierStatusBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type {
    CertificateType,
    CommentFeed,
    RatingCriterion,
    SelectOption,
    SupplierDetails,
} from '@/types/srm';
import { trans } from '@/lib/i18n';

const props = defineProps<{
    supplier: SupplierDetails;
    comments: CommentFeed;
    certificateTypes: CertificateType[];
    ratingCriteria: RatingCriterion[];
    riskLevels: SelectOption[];
    currencies: SelectOption[];
    can: {
        update: boolean;
        archive: boolean;
        restore: boolean;
        comment: boolean;
        viewContracts: boolean;
        viewSpend: boolean;
    };
}>();

setLayoutProps({
    breadcrumbs: [
        { title: trans('Suppliers'), href: SupplierController.index() },
        {
            title: props.supplier.name,
            href: SupplierController.show({ supplier: props.supplier.id }),
        },
    ],
});

const isConfirmingArchive = ref(false);

const contractCount = computed(() =>
    props.supplier.contracts_count === 1
        ? trans('1 contract')
        : trans(':count contracts', { count: props.supplier.contracts_count }),
);

// The sentence stays whole for translators; :certificates marks where the bold list goes.
const missingCertificatesSentence = computed(() =>
    trans('Missing a valid required certificate: :certificates').split(
        ':certificates',
    ),
);

const address = computed(() =>
    [
        props.supplier.street_address,
        [props.supplier.postal_code, props.supplier.city]
            .filter(Boolean)
            .join(' '),
        props.supplier.country,
    ]
        .filter(Boolean)
        .join(', '),
);

const deliveryTerm = computed(() =>
    [props.supplier.delivery_term, props.supplier.delivery_place]
        .filter(Boolean)
        .join(', '),
);

const archivedOn = computed(() =>
    props.supplier.archived_at
        ? new Date(props.supplier.archived_at).toLocaleDateString()
        : null,
);

function restore(): void {
    router.post(
        RestoreSupplierController.url({ supplier: props.supplier.id }),
        {},
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head :title="supplier.name" />

    <div class="flex max-w-5xl flex-col gap-8 p-4">
        <div
            v-if="archivedOn"
            class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-100"
        >
            {{
                $t(
                    'This supplier was archived on :date. Restore it to make changes.',
                    { date: archivedOn },
                )
            }}
        </div>

        <div
            v-if="supplier.missing_certificates.length > 0"
            role="alert"
            class="flex gap-3 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-100"
        >
            <TriangleAlert class="size-5 shrink-0" />
            <p>
                {{ missingCertificatesSentence[0]
                }}<strong>{{ supplier.missing_certificates.join(', ') }}</strong
                >{{ missingCertificatesSentence[1] }}
            </p>
        </div>

        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="space-y-2">
                <Heading
                    :title="supplier.name"
                    :description="
                        supplier.supplier_number
                            ? $t('Supplier number :number', {
                                  number: supplier.supplier_number,
                              })
                            : undefined
                    "
                    class="mb-0"
                />
                <div class="flex flex-wrap gap-2">
                    <SupplierStatusBadge :status="supplier.status" />
                    <Badge
                        v-for="category in supplier.categories"
                        :key="category"
                        variant="outline"
                    >
                        {{ category }}
                    </Badge>
                </div>
            </div>

            <div class="flex gap-2">
                <Button v-if="can.update" variant="outline" as-child>
                    <Link
                        :href="
                            SupplierController.edit({ supplier: supplier.id })
                        "
                    >
                        <Pencil /> {{ $t('Edit') }}
                    </Link>
                </Button>
                <Button
                    v-if="can.archive"
                    variant="outline"
                    @click="isConfirmingArchive = true"
                >
                    <Archive /> {{ $t('Archive') }}
                </Button>
                <Button v-if="can.restore" variant="outline" @click="restore">
                    <ArchiveRestore /> {{ $t('Restore') }}
                </Button>
            </div>
        </div>

        <dl
            class="grid gap-6 rounded-xl border p-6 sm:grid-cols-2 lg:grid-cols-3"
        >
            <DetailItem
                :label="$t('Responsible buyer')"
                :value="supplier.responsible_user"
            />
            <DetailItem
                :label="$t('Business ID')"
                :value="supplier.business_id"
            />
            <DetailItem
                :label="$t('VAT number')"
                :value="supplier.vat_number"
            />
            <DetailItem :label="$t('Address')" :value="address" />
            <DetailItem :label="$t('Website')">
                <a
                    v-if="supplier.website"
                    :href="supplier.website"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="break-all hover:underline"
                >
                    {{ supplier.website }}
                </a>
                <template v-else>—</template>
            </DetailItem>
            <DetailItem :label="$t('Email')">
                <a
                    v-if="supplier.email"
                    :href="`mailto:${supplier.email}`"
                    class="break-all hover:underline"
                >
                    {{ supplier.email }}
                </a>
                <template v-else>—</template>
            </DetailItem>
            <DetailItem :label="$t('Phone')">
                <a
                    v-if="supplier.phone"
                    :href="`tel:${supplier.phone}`"
                    class="hover:underline"
                >
                    {{ supplier.phone }}
                </a>
                <template v-else>—</template>
            </DetailItem>
            <DetailItem
                :label="$t('Payment terms')"
                :value="supplier.payment_terms"
            />
            <DetailItem :label="$t('Currency')" :value="supplier.currency" />
            <DetailItem :label="$t('Delivery term')" :value="deliveryTerm" />
            <DetailItem
                v-if="supplier.notes"
                :label="$t('Notes')"
                class="sm:col-span-2 lg:col-span-3"
            >
                <p class="whitespace-pre-line">{{ supplier.notes }}</p>
            </DetailItem>
        </dl>

        <SupplierRiskPanel
            :supplier-id="supplier.id"
            :risk="supplier.risk"
            :risk-levels="riskLevels"
            :can-edit="can.update"
        />

        <RatingList
            :supplier-id="supplier.id"
            :latest-score="supplier.latest_rating_score"
            :ratings="supplier.ratings"
            :criteria="ratingCriteria"
            :can-edit="can.update"
        />

        <SpendList
            v-if="can.viewSpend"
            :supplier-id="supplier.id"
            :spends="supplier.spends"
            :currencies="currencies"
            :can-edit="can.update"
        />

        <CertificateList
            :supplier-id="supplier.id"
            :certificates="supplier.certificates"
            :certificate-types="certificateTypes"
            :can-edit="can.update"
        />

        <ContractList
            v-if="can.viewContracts"
            :supplier-id="supplier.id"
            :contracts="supplier.contracts"
            :can-edit="can.update"
        />
        <section v-else class="space-y-2">
            <Heading variant="small" :title="$t('Contracts')" />
            <p class="text-sm text-muted-foreground">
                {{
                    $t(
                        ':contracts. Only buyers and admins can see the details.',
                        { contracts: contractCount },
                    )
                }}
            </p>
        </section>

        <DocumentList
            :title="$t('Documents')"
            :supplier-id="supplier.id"
            :documents="supplier.documents"
            :upload-action="
                DocumentController.store.form({ supplier: supplier.id })
            "
            :can-edit="can.update"
        />

        <ContactList
            :supplier-id="supplier.id"
            :contacts="supplier.contacts"
            :can-edit="can.update"
        />

        <CommentSection
            :supplier-id="supplier.id"
            :feed="comments"
            :can-comment="can.comment"
        />
    </div>

    <ConfirmActionDialog
        v-model:open="isConfirmingArchive"
        :action="ArchiveSupplierController.form({ supplier: supplier.id })"
        :title="$t('Archive :name?', { name: supplier.name })"
        :description="
            $t(
                'It will be hidden from the supplier list, but nothing is deleted. You can restore it at any time.',
            )
        "
        :confirm-label="$t('Archive')"
    />
</template>
