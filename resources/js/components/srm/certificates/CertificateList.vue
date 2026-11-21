<script setup lang="ts">
import { Download, Pencil, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import CertificateController from '@/actions/App/Modules/Srm/Http/Controllers/Suppliers/CertificateController';
import CertificateDialog from '@/components/srm/certificates/CertificateDialog.vue';
import CertificateStatusBadge from '@/components/srm/certificates/CertificateStatusBadge.vue';
import ConfirmActionDialog from '@/components/ConfirmActionDialog.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { formatDate, formatFileSize } from '@/lib/format';
import type { Certificate, CertificateType } from '@/types/srm';
import { trans } from '@/lib/i18n';

defineProps<{
    supplierId: number;
    certificates: Certificate[];
    certificateTypes: CertificateType[];
    canEdit: boolean;
}>();

const isAdding = ref(false);
const certificateBeingEdited = ref<Certificate | null>(null);
const certificateBeingDeleted = ref<Certificate | null>(null);

function reminderSummary(certificate: Certificate): string {
    if (!certificate.expires_on || certificate.reminder_days.length === 0) {
        return trans('No reminders');
    }

    return trans('Reminders :days days before', {
        days: certificate.reminder_days.join(` ${trans('and')} `),
    });
}
</script>

<template>
    <section class="space-y-4">
        <div class="flex items-center justify-between gap-4">
            <Heading variant="small" :title="$t('Certificates')" />
            <Button
                v-if="canEdit"
                variant="outline"
                size="sm"
                @click="isAdding = true"
            >
                <Plus /> {{ $t('Add certificate') }}
            </Button>
        </div>

        <p
            v-if="certificates.length === 0"
            class="text-sm text-muted-foreground"
        >
            {{ $t('No certificates yet.') }}
        </p>

        <div v-else class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium">
                            {{ $t('Type') }}
                        </th>
                        <th scope="col" class="px-4 py-3 font-medium">
                            {{ $t('Valid until') }}
                        </th>
                        <th scope="col" class="px-4 py-3 font-medium">
                            {{ $t('Status') }}
                        </th>
                        <th scope="col" class="px-4 py-3 font-medium">
                            {{ $t('File') }}
                        </th>
                        <th scope="col" class="px-4 py-3">
                            <span class="sr-only">{{ $t('Actions') }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr
                        v-for="certificate in certificates"
                        :key="certificate.id"
                    >
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ certificate.type }}</p>
                            <p
                                v-if="certificate.reference"
                                class="text-muted-foreground"
                            >
                                {{
                                    $t('No. :reference', {
                                        reference: certificate.reference,
                                    })
                                }}
                            </p>
                        </td>
                        <td class="px-4 py-3">
                            <p>
                                {{
                                    certificate.expires_on
                                        ? formatDate(certificate.expires_on)
                                        : $t('No expiry')
                                }}
                            </p>
                            <p class="text-muted-foreground">
                                {{ reminderSummary(certificate) }}
                            </p>
                        </td>
                        <td class="px-4 py-3">
                            <CertificateStatusBadge
                                :status="certificate.status"
                            />
                        </td>
                        <td class="px-4 py-3">
                            <a
                                v-if="certificate.file_name"
                                :href="
                                    CertificateController.show.url({
                                        supplier: supplierId,
                                        certificate: certificate.id,
                                    })
                                "
                                class="inline-flex items-center gap-1 hover:underline"
                            >
                                <Download class="size-4" />
                                <span class="max-w-48 truncate">{{
                                    certificate.file_name
                                }}</span>
                                <span
                                    v-if="certificate.file_size"
                                    class="text-muted-foreground"
                                    >({{
                                        formatFileSize(certificate.file_size)
                                    }})</span
                                >
                            </a>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <template v-if="canEdit">
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    :aria-label="
                                        $t('Edit :name', {
                                            name: certificate.type,
                                        })
                                    "
                                    @click="
                                        certificateBeingEdited = certificate
                                    "
                                >
                                    <Pencil />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    :aria-label="
                                        $t('Delete :name', {
                                            name: certificate.type,
                                        })
                                    "
                                    @click="
                                        certificateBeingDeleted = certificate
                                    "
                                >
                                    <Trash2 />
                                </Button>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <CertificateDialog
        v-if="isAdding"
        :supplier-id="supplierId"
        :certificate-types="certificateTypes"
        :open="true"
        @update:open="isAdding = false"
    />

    <CertificateDialog
        v-if="certificateBeingEdited"
        :supplier-id="supplierId"
        :certificate-types="certificateTypes"
        :certificate="certificateBeingEdited"
        :open="true"
        @update:open="certificateBeingEdited = null"
    />

    <ConfirmActionDialog
        v-if="certificateBeingDeleted"
        :action="
            CertificateController.destroy.form({
                supplier: supplierId,
                certificate: certificateBeingDeleted.id,
            })
        "
        :title="$t('Delete :name?', { name: certificateBeingDeleted.type })"
        :description="
            $t(
                'The certificate, its file and its reminders are removed permanently.',
            )
        "
        :confirm-label="$t('Delete')"
        :open="true"
        @update:open="certificateBeingDeleted = null"
    />
</template>
