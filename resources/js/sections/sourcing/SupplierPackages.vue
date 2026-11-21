<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import PackageController from '@/actions/App/Modules/Ppr/Http/Controllers/Projects/PackageController';
import ProjectController from '@/actions/App/Modules/Ppr/Http/Controllers/Projects/ProjectController';
import Heading from '@/components/Heading.vue';
import PackageStatusBadge from '@/components/ppr/packages/PackageStatusBadge.vue';
import { Badge } from '@/components/ui/badge';
import type { SupplierPackagesSection } from '@/types/sourcing';

/**
 * The project packages this supplier is on, in the projects the person can see. Hidden when there are none.
 */
defineProps<{ data: SupplierPackagesSection }>();
</script>

<template>
    <section
        v-if="data.total > 0"
        class="space-y-4"
        :aria-label="$t('Project packages')"
    >
        <Heading
            variant="small"
            :title="$t('Project packages')"
            :description="
                data.total > data.packages.length
                    ? $t('The first :shown of :total packages.', {
                          shown: data.packages.length,
                          total: data.total,
                      })
                    : undefined
            "
        />

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium">
                            {{ $t('Project') }}
                        </th>
                        <th scope="col" class="px-4 py-3 font-medium">
                            {{ $t('Package') }}
                        </th>
                        <th scope="col" class="px-4 py-3 font-medium">
                            {{ $t('Package status') }}
                        </th>
                        <th scope="col" class="px-4 py-3 font-medium">
                            {{ $t('Quote') }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="item in data.packages" :key="item.packageId">
                        <td class="px-4 py-3">
                            <Link
                                :href="
                                    ProjectController.show({
                                        project: item.projectId,
                                    })
                                "
                                class="hover:underline"
                            >
                                <span class="font-mono text-muted-foreground">
                                    {{ item.projectCode }}
                                </span>
                                {{ item.projectName }}
                            </Link>
                        </td>
                        <td class="px-4 py-3">
                            <Link
                                :href="
                                    PackageController.show({
                                        project: item.projectId,
                                        package: item.packageId,
                                    })
                                "
                                class="hover:underline"
                            >
                                <span class="font-mono text-muted-foreground">
                                    {{ item.number }}
                                </span>
                                <span class="ml-2 font-medium">
                                    {{ item.title }}
                                </span>
                            </Link>
                        </td>
                        <td class="px-4 py-3">
                            <PackageStatusBadge :status="item.packageStatus" />
                        </td>
                        <td class="px-4 py-3">
                            <Badge v-if="item.status === 'chosen'">
                                <Check />
                                {{ item.statusLabel }}
                            </Badge>
                            <template v-else>
                                {{ item.statusLabel }}
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
