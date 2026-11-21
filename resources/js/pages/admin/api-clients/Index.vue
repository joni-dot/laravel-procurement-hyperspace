<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { Ban, KeyRound, Pencil, Plus } from '@lucide/vue';
import { ref, watch } from 'vue';
import ApiClientController from '@/actions/App/Http/Controllers/Admin/ApiClientController';
import RegenerateApiClientSecretController from '@/actions/App/Http/Controllers/Admin/RegenerateApiClientSecretController';
import RevokeApiClientController from '@/actions/App/Http/Controllers/Admin/RevokeApiClientController';
import ApiClientDialog from '@/components/api-clients/ApiClientDialog.vue';
import ApiClientSecretDialog from '@/components/api-clients/ApiClientSecretDialog.vue';
import ConfirmActionDialog from '@/components/ConfirmActionDialog.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/format';
import type { ApiClientRow, ApiScopeOption, NewApiClientSecret } from '@/types';
import { trans } from '@/lib/i18n';

defineOptions({
    layout: () => ({
        breadcrumbs: [
            { title: trans('API clients'), href: ApiClientController.index() },
        ],
    }),
});

const props = defineProps<{
    apiClients: ApiClientRow[];
    scopes: ApiScopeOption[];
    responsibleUsers: { id: number; name: string }[];
    tokenUrl: string;
    accessTokenMinutes: number;
}>();

const page = usePage();

const isAdding = ref(false);
const clientBeingEdited = ref<ApiClientRow | null>(null);
const clientGettingNewSecret = ref<ApiClientRow | null>(null);
const clientBeingRevoked = ref<ApiClientRow | null>(null);
const newCredentials = ref<NewApiClientSecret | null>(null);

// The server sends a new secret once, as flash data; it is kept only while the dialog is open.
watch(
    () => page.flash.apiClientSecret as NewApiClientSecret | undefined,
    (credentials) => {
        if (credentials) {
            newCredentials.value = credentials;
        }
    },
    { immediate: true },
);

function scopeLabels(client: ApiClientRow): string {
    return props.scopes
        .filter((scope) => client.scopes.includes(scope.value))
        .map((scope) => scope.label)
        .join(', ');
}
</script>

<template>
    <Head :title="$t('API clients')" />

    <div class="flex max-w-4xl flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                :title="$t('API clients')"
                :description="
                    $t(
                        'Systems that read supplier data on their own. Each one trades its secret for an access token that lasts :minutes minutes.',
                        { minutes: accessTokenMinutes },
                    )
                "
                class="mb-0"
            />
            <Button @click="isAdding = true">
                <Plus /> {{ $t('Add API client') }}
            </Button>
        </div>

        <p v-if="apiClients.length === 0" class="text-sm text-muted-foreground">
            {{ $t('No API clients yet.') }}
        </p>

        <ul v-else class="divide-y rounded-xl border">
            <li
                v-for="client in apiClients"
                :key="client.id"
                class="flex items-start justify-between gap-4 px-4 py-3 text-sm"
                :class="{ 'text-muted-foreground': client.revoked }"
            >
                <div class="min-w-0 space-y-1">
                    <p class="flex items-center gap-2 font-medium">
                        {{ client.name }}
                        <Badge v-if="client.revoked" variant="secondary">
                            {{ $t('Revoked') }}
                        </Badge>
                    </p>
                    <p>
                        {{ scopeLabels(client) || $t('No access') }}
                        <span v-if="client.responsible_user_name">
                            ·
                            {{
                                $t('Responsible: :name', {
                                    name: client.responsible_user_name,
                                })
                            }}
                        </span>
                    </p>
                    <p class="text-muted-foreground">
                        {{
                            $t('Created :date', {
                                date: formatDateTime(client.created_at),
                            })
                        }}
                        ·
                        {{
                            client.last_used_at
                                ? $t('Last used :date', {
                                      date: formatDateTime(client.last_used_at),
                                  })
                                : $t('Last used never')
                        }}
                    </p>
                    <p class="truncate font-mono text-xs text-muted-foreground">
                        {{ $t('Client ID :id', { id: client.id }) }}
                    </p>
                </div>
                <div v-if="!client.revoked" class="flex shrink-0">
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        :aria-label="$t('Edit :name', { name: client.name })"
                        @click="clientBeingEdited = client"
                    >
                        <Pencil />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        :aria-label="
                            $t('New secret for :name', { name: client.name })
                        "
                        @click="clientGettingNewSecret = client"
                    >
                        <KeyRound />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        :aria-label="$t('Revoke :name', { name: client.name })"
                        @click="clientBeingRevoked = client"
                    >
                        <Ban />
                    </Button>
                </div>
            </li>
        </ul>
    </div>

    <ApiClientDialog
        v-if="isAdding"
        :scopes="scopes"
        :responsible-users="responsibleUsers"
        :open="true"
        @update:open="isAdding = false"
    />

    <ApiClientDialog
        v-if="clientBeingEdited"
        :api-client="clientBeingEdited"
        :scopes="scopes"
        :responsible-users="responsibleUsers"
        :open="true"
        @update:open="clientBeingEdited = null"
    />

    <ConfirmActionDialog
        v-if="clientGettingNewSecret"
        :action="
            RegenerateApiClientSecretController.form({
                apiClient: clientGettingNewSecret.id,
            })
        "
        :title="
            $t('New secret for :name?', { name: clientGettingNewSecret.name })
        "
        :description="
            $t(
                'The old secret and its access tokens stop working at once. The system needs the new secret before it can connect again.',
            )
        "
        :confirm-label="$t('Create new secret')"
        :open="true"
        @update:open="clientGettingNewSecret = null"
    />

    <ConfirmActionDialog
        v-if="clientBeingRevoked"
        :action="
            RevokeApiClientController.form({ apiClient: clientBeingRevoked.id })
        "
        :title="$t('Revoke :name?', { name: clientBeingRevoked.name })"
        :description="
            $t(
                'The system loses API access at once, and this cannot be undone. The client stays in the list for the record.',
            )
        "
        :confirm-label="$t('Revoke')"
        :open="true"
        @update:open="clientBeingRevoked = null"
    />

    <ApiClientSecretDialog
        v-if="newCredentials"
        :credentials="newCredentials"
        :token-url="tokenUrl"
        :open="true"
        @update:open="newCredentials = null"
    />
</template>
