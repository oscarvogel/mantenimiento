<script setup>
import { computed, reactive, ref } from 'vue'
import PageHeading from './components/PageHeading.vue'
import TelemetryUnitCombobox from './components/TelemetryUnitCombobox.vue'
import { fieldClass, primaryButton } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })
const integrations = computed(() => props.data.integrations ?? [])
const openIntegrationId = ref(null)
const loadingIntegrationId = ref(null)
const snapshots = reactive({})
const mappings = reactive({})
const searchTerms = reactive({})
const loadErrors = reactive({})
const provider = ref('wialon')
const subscriptionKey = ref('')
const apiUsername = ref('')
const apiPassword = ref('')
const testUrl = computed(() => props.data.actions.testAutotrac)
const autotracAccounts = ref([])
const accountCode = ref('')
const connectionMessage = ref('')
const connectionError = ref('')
const testingConnection = ref(false)

const providerLabel = (value) => value === 'autotrac' ? 'Autotrac' : value === 'wialon' ? 'Wialon' : value
const invalidateAutotracAccounts = () => {
  autotracAccounts.value = []
  accountCode.value = ''
  connectionMessage.value = ''
  connectionError.value = ''
}

const testAutotracConnection = async () => {
  testingConnection.value = true
  connectionError.value = ''
  connectionMessage.value = ''
  autotracAccounts.value = []
  accountCode.value = ''
  try {
    const body = new URLSearchParams({
      [props.data.csrf.name]: props.data.csrf.hash,
      subscription_key: subscriptionKey.value,
      username: apiUsername.value,
      password: apiPassword.value,
    })
    const response = await fetch(testUrl.value, {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
      credentials: 'same-origin',
      body,
    })
    const payload = await response.json()
    if (!response.ok) throw new Error(payload.message || 'No se pudo validar la conexión ATIC.')
    autotracAccounts.value = payload.accounts ?? []
    connectionMessage.value = payload.message || 'Conexión ATIC validada. Elegí la cuenta.'
    if (autotracAccounts.value.length === 1) accountCode.value = String(autotracAccounts.value[0].Code)
  } catch (error) {
    connectionError.value = error instanceof Error ? error.message : 'No se pudo validar la conexión ATIC.'
  } finally {
    testingConnection.value = false
  }
}

const loadUnits = async (integration) => {
  openIntegrationId.value = integration.id
  loadingIntegrationId.value = integration.id
  loadErrors[integration.id] = ''

  try {
    const response = await fetch(integration.unitsUrl, {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    })
    const payload = await response.json()
    if (!response.ok) throw new Error(payload.message || 'No se pudieron consultar las unidades.')

    snapshots[integration.id] = payload
    const suggestedLinks = payload.suggestedLinks ?? {}
    mappings[integration.id] = Object.fromEntries(
      payload.equipment.map((equipment) => [equipment.id, payload.links?.[equipment.id] ?? suggestedLinks[equipment.id] ?? '']),
    )
  } catch (error) {
    snapshots[integration.id] = null
    loadErrors[integration.id] = error instanceof Error
      ? error.message
      : 'No se pudieron consultar las unidades. Intentá de nuevo.'
  } finally {
    loadingIntegrationId.value = null
  }
}

const visibleEquipment = (integrationId) => {
  const snapshot = snapshots[integrationId]
  const query = String(searchTerms[integrationId] ?? '').trim().toLocaleLowerCase('es-AR')
  if (!snapshot || !query) return snapshot?.equipment ?? []

  return snapshot.equipment.filter((equipment) =>
    [equipment.code, equipment.plate].filter(Boolean).join(' ').toLocaleLowerCase('es-AR').includes(query),
  )
}

const equipmentLabel = (equipment) => equipment.plate && equipment.plate !== equipment.code
  ? `${equipment.plate} · ${equipment.code}`
  : equipment.code

const suggestedCount = (integrationId) => {
  const snapshot = snapshots[integrationId]
  if (!snapshot) return 0
  return Object.entries(snapshot.suggestedLinks ?? {}).filter(([equipmentId, unitId]) =>
    !snapshot.links?.[equipmentId] && mappings[integrationId]?.[equipmentId] === unitId,
  ).length
}

const usedUnitIds = (integrationId, equipmentId) => Object.entries(mappings[integrationId] ?? {})
  .filter(([otherEquipmentId, selectedUnitId]) => otherEquipmentId !== String(equipmentId) && selectedUnitId && selectedUnitId !== '__unlink__')
  .map(([, selectedUnitId]) => selectedUnitId)
</script>

<template>
  <div class="space-y-6">
    <PageHeading
      eyebrow="Administración · Integraciones"
      title="Telemetría de la empresa"
      description="Conectá las cuentas de telemetría de tu empresa y elegí qué unidad del proveedor corresponde a cada equipo."
    />

    <section class="rounded-2xl border border-border bg-surface-raised p-5 shadow-card sm:p-6">
      <form method="post" :action="data.actions.save" class="grid gap-5">
        <input type="hidden" :name="data.csrf.name" :value="data.csrf.hash" />

        <label class="block">
          <span class="mb-1.5 block text-sm font-semibold text-ink">Proveedor</span>
          <select v-model="provider" name="provider" required :class="fieldClass">
            <option value="wialon">Wialon</option>
            <option value="autotrac">Autotrac (ATIC API)</option>
          </select>
        </label>

        <label class="block">
          <span class="mb-1.5 block text-sm font-semibold text-ink">Nombre de la cuenta <span class="font-normal text-ink-muted">(opcional)</span></span>
          <input name="name" maxlength="100" :placeholder="providerLabel(provider)" :class="fieldClass" />
          <span class="mt-1 block text-xs text-ink-muted">Usá un nombre distinto si tu empresa tiene más de una cuenta del mismo proveedor.</span>
        </label>

        <label v-if="provider === 'wialon'" class="block">
          <span class="mb-1.5 block text-sm font-semibold text-ink">Token</span>
          <textarea name="token" rows="3" required maxlength="4096" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="Pegá acá el token del proveedor" :class="fieldClass" />
          <span class="mt-1 block text-xs text-ink-muted">Se valida al guardar y queda cifrado en la configuración de tu empresa. No se vuelve a mostrar.</span>
        </label>

        <template v-if="provider === 'autotrac'">
          <label class="block">
            <span class="mb-1.5 block text-sm font-semibold text-ink">Clave de suscripción ATIC</span>
            <input v-model="subscriptionKey" name="subscription_key" type="password" maxlength="4096" autocomplete="new-password" required :class="fieldClass" @input="invalidateAutotracAccounts" />
          </label>
          <label class="block">
            <span class="mb-1.5 block text-sm font-semibold text-ink">Usuario ATIC</span>
            <input v-model="apiUsername" name="username" maxlength="255" autocomplete="username" required :class="fieldClass" @input="invalidateAutotracAccounts" />
          </label>
          <label class="block">
            <span class="mb-1.5 block text-sm font-semibold text-ink">Contraseña ATIC</span>
            <input v-model="apiPassword" name="password" type="password" maxlength="4096" autocomplete="new-password" required :class="fieldClass" @input="invalidateAutotracAccounts" />
          </label>
          <div class="flex flex-wrap items-center gap-3">
            <button type="button" :class="primaryButton" :disabled="testingConnection" @click="testAutotracConnection">
              {{ testingConnection ? 'Validando acesso…' : 'Testar conexão ATIC' }}
            </button>
            <span v-if="connectionMessage" role="status" class="text-sm text-success">{{ connectionMessage }}</span>
          </div>
          <p v-if="connectionError" role="alert" class="rounded-lg border border-danger/20 bg-danger-subtle p-3 text-sm text-danger">{{ connectionError }}</p>
          <label v-if="autotracAccounts.length" class="block">
            <span class="mb-1.5 block text-sm font-semibold text-ink">Conta Autotrac</span>
            <select v-model="accountCode" name="account_code" required :class="fieldClass">
              <option value="" disabled>Selecione a conta da empresa</option>
              <option v-for="account in autotracAccounts" :key="account.Code" :value="String(account.Code)">{{ account.Name }} · {{ account.Code }}</option>
            </select>
          </label>
          <p v-if="!autotracAccounts.length" class="rounded-xl border border-primary/20 bg-primary-subtle p-4 text-sm leading-6 text-ink">
            Informe a chave, o usuário e a senha ATIC e teste a conexão. Depois selecione a conta; os dados ficam cifrados para esta empresa.
          </p>
        </template>

        <div v-else class="rounded-xl border border-primary/20 bg-primary-subtle p-4 text-sm leading-6 text-ink">
          Al guardar, Wialon valida el token. Las coincidencias por patente se vinculan automáticamente; después podés revisar o completar las asociaciones desde la cuenta conectada.
        </div>

        <div class="flex flex-wrap gap-3">
          <button type="submit" :class="primaryButton" :disabled="provider === 'autotrac' && !accountCode">Guardar y conectar</button>
        </div>
      </form>
    </section>

    <section v-if="integrations.length" class="space-y-4">
      <h2 class="text-base font-semibold text-ink">Cuentas conectadas en esta empresa</h2>
      <article v-for="integration in integrations" :key="integration.id" class="rounded-2xl border border-border bg-surface-raised p-5 shadow-card sm:p-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
          <div>
            <p class="font-semibold text-ink">{{ integration.name }} <span class="font-normal text-ink-muted">· {{ providerLabel(integration.provider) }}</span></p>
            <p class="mt-1 text-sm text-ink-muted">{{ integration.linkedEquipmentCount }} equipos vinculados<span v-if="integration.lastSuccess"> · última consulta correcta {{ integration.lastSuccess }}</span></p>
          </div>
          <div class="flex flex-wrap items-center gap-3">
            <span class="rounded-full bg-success-subtle px-3 py-1 text-xs font-bold text-success">{{ integration.active ? 'Activo' : 'Inactivo' }}</span>
            <button
              type="button"
              class="ui-interactive inline-flex min-h-10 items-center justify-center rounded-lg border border-border px-3 py-2 text-sm font-semibold text-ink hover:bg-surface-muted disabled:cursor-wait disabled:opacity-60"
              :data-load-units="integration.id"
              :disabled="loadingIntegrationId === integration.id"
              :aria-expanded="openIntegrationId === integration.id"
              @click="loadUnits(integration)"
              v-if="integration.active"
            >
              {{ loadingIntegrationId === integration.id ? `Consultando ${providerLabel(integration.provider)}…` : openIntegrationId === integration.id ? 'Actualizar unidades' : 'Vincular equipos' }}
            </button>
          </div>
        </div>

        <div v-if="openIntegrationId === integration.id" class="mt-5 rounded-xl border border-border bg-surface-muted/40 p-4 sm:p-5">
          <p v-if="loadErrors[integration.id]" role="alert" class="rounded-lg border border-danger/20 bg-danger-subtle p-3 text-sm text-danger">
            {{ loadErrors[integration.id] }}
          </p>

          <template v-else-if="snapshots[integration.id]">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <h3 class="font-semibold text-ink">Unidades de {{ providerLabel(integration.provider) }}</h3>
                <p class="mt-1 text-sm text-ink-muted">
                  {{ snapshots[integration.id].units.length }} unidades disponibles · {{ snapshots[integration.id].equipment.length }} equipos activos
                </p>
              </div>
              <label class="w-full sm:max-w-xs">
                <span class="sr-only">Buscar equipo</span>
                <input v-model="searchTerms[integration.id]" type="search" placeholder="Buscar equipo por patente o código" :class="fieldClass" />
              </label>
            </div>

            <p v-if="snapshots[integration.id].units.length === 0" class="mt-4 rounded-lg border border-warning/30 bg-warning-subtle p-3 text-sm text-ink">
              La cuenta conectó, pero no devolvió unidades. Revisá los permisos del proveedor.
            </p>
            <p v-if="suggestedCount(integration.id)" class="mt-4 rounded-lg border border-success/25 bg-success-subtle p-3 text-sm leading-5 text-ink">
              {{ suggestedCount(integration.id) }} {{ suggestedCount(integration.id) === 1 ? 'coincidencia' : 'coincidencias' }} por patente ya están seleccionadas. Guardá una vez para vincularlas; podés ajustar cualquier unidad antes.
            </p>
            <form v-if="snapshots[integration.id].units.length > 0" method="post" :action="integration.saveLinksUrl" class="mt-4">
              <input type="hidden" :name="data.csrf.name" :value="data.csrf.hash" />
              <div v-if="snapshots[integration.id].equipment.length" class="overflow-x-auto rounded-lg border border-border">
                <table class="w-full min-w-[38rem] text-left text-sm">
                  <thead class="bg-surface-raised text-xs uppercase tracking-wide text-ink-muted">
                    <tr>
                      <th scope="col" class="px-3 py-3 font-semibold">Equipo</th>
                      <th scope="col" class="px-3 py-3 font-semibold">Unidad en {{ providerLabel(integration.provider) }}</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-border bg-surface-raised">
                    <tr v-for="equipment in visibleEquipment(integration.id)" :key="equipment.id">
                      <th scope="row" class="px-3 py-2.5 font-semibold text-ink">{{ equipmentLabel(equipment) }}</th>
                      <td class="px-3 py-2.5">
                        <TelemetryUnitCombobox
                          v-model="mappings[integration.id][equipment.id]"
                          :units="snapshots[integration.id].units"
                          :persisted-value="snapshots[integration.id].links?.[equipment.id] ?? ''"
                          :used-unit-ids="usedUnitIds(integration.id, equipment.id)"
                          :field-name="`mappings[${equipment.id}]`"
                          :label="`Unidad de ${providerLabel(integration.provider)} para ${equipmentLabel(equipment)}`"
                        />
                      </td>
                    </tr>
                    <tr v-if="visibleEquipment(integration.id).length === 0">
                      <td colspan="2" class="px-3 py-6 text-center text-ink-muted">No hay equipos que coincidan con esa búsqueda.</td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <p v-else class="rounded-lg border border-border bg-surface-raised p-4 text-sm text-ink-muted">No hay equipos activos en esta empresa para vincular.</p>

              <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                <p class="max-w-2xl text-xs leading-5 text-ink-muted">Cada unidad se puede asignar a un equipo en esta cuenta. Los cambios se validan contra {{ providerLabel(integration.provider) }} y quedan asociados a esta empresa.</p>
                <button type="submit" :class="primaryButton">Guardar vínculos</button>
              </div>
            </form>
          </template>
        </div>
      </article>
    </section>
  </div>
</template>
