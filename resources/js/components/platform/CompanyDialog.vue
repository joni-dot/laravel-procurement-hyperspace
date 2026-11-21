<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import CompanyController from '@/actions/App/Http/Controllers/Platform/CompanyController';
import FormField from '@/components/FormField.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import type { PlatformCompanyRow } from '@/types';

const props = defineProps<{
    company?: PlatformCompanyRow;
}>();

const isOpen = defineModel<boolean>('open', { required: true });

const name = ref(props.company?.name ?? '');
const slug = ref(props.company?.slug ?? '');

// A new company's address follows its name until someone types an address of their own.
const slugFollowsName = ref(!props.company);

watch(name, (newName) => {
    if (slugFollowsName.value) {
        slug.value = toSlug(newName);
    }
});

function toSlug(text: string): string {
    return text
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+/, '')
        .slice(0, 40)
        .replace(/-+$/, '');
}

const action = props.company
    ? CompanyController.update.form({ company: props.company.id })
    : CompanyController.store.form();
</script>

<template>
    <Dialog v-model:open="isOpen">
        <DialogContent>
            <Form
                v-bind="action"
                :options="{ preserveScroll: true }"
                @success="isOpen = false"
                class="space-y-6"
                v-slot="{ errors, processing }"
            >
                <DialogHeader>
                    <DialogTitle>
                        {{
                            company
                                ? $t('Edit :name', { name: company.name })
                                : $t('Add company')
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        {{
                            company
                                ? $t(
                                      'Old links keep working when the address changes: they forward to the new address. An old address is never given to another company.',
                                  )
                                : $t(
                                      'The company gets its own address, and the person you name here is invited to be its first admin.',
                                  )
                        }}
                    </DialogDescription>
                </DialogHeader>

                <FormField
                    id="company-name"
                    :label="$t('Company name')"
                    :error="errors.name"
                >
                    <Input
                        id="company-name"
                        v-model="name"
                        name="name"
                        required
                        maxlength="100"
                        autocomplete="off"
                    />
                </FormField>

                <FormField
                    id="company-slug"
                    :label="$t('Address')"
                    :hint="
                        $t(
                            'Lowercase letters, digits and dashes. The company opens at /:address',
                            { address: slug || $t('address') },
                        )
                    "
                    :error="errors.slug"
                >
                    <Input
                        id="company-slug"
                        v-model="slug"
                        name="slug"
                        required
                        maxlength="40"
                        autocomplete="off"
                        spellcheck="false"
                        @input="slugFollowsName = false"
                    />
                </FormField>

                <template v-if="!company">
                    <FormField
                        id="company-admin-name"
                        :label="$t('Admin\'s name')"
                        :error="errors.admin_name"
                    >
                        <Input
                            id="company-admin-name"
                            name="admin_name"
                            required
                            maxlength="255"
                            autocomplete="off"
                        />
                    </FormField>

                    <FormField
                        id="company-admin-email"
                        :label="$t('Admin\'s email')"
                        :hint="
                            $t(
                                'They get an email invitation to become the company\'s admin.',
                            )
                        "
                        :error="errors.admin_email"
                    >
                        <Input
                            id="company-admin-email"
                            name="admin_email"
                            type="email"
                            required
                            maxlength="255"
                            autocomplete="off"
                        />
                    </FormField>
                </template>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="secondary">
                            {{ $t('Cancel') }}
                        </Button>
                    </DialogClose>
                    <Button type="submit" :disabled="processing">
                        <Spinner v-if="processing" />
                        {{ $t('Save') }}
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
