<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import CategoryController from '@/actions/App/Modules/Srm/Http/Controllers/Admin/CategoryController';
import FormField from '@/components/FormField.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import type { Category } from '@/types/srm';

const props = defineProps<{
    category?: Category;
}>();

const isOpen = defineModel<boolean>('open', { required: true });

const action = props.category
    ? CategoryController.update.form({ category: props.category.id })
    : CategoryController.store.form();
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
                            category
                                ? $t('Rename category')
                                : $t('Add category')
                        }}
                    </DialogTitle>
                </DialogHeader>

                <FormField
                    id="category-name"
                    :label="$t('Name')"
                    :error="errors.name"
                >
                    <Input
                        id="category-name"
                        name="name"
                        :default-value="category?.name"
                        required
                        maxlength="100"
                        autocomplete="off"
                    />
                </FormField>

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
