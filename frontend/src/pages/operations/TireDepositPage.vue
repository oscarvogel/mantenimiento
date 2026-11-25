<script setup>
import { computed, ref, watch } from 'vue'
import { ArrowsRightLeftIcon } from '@heroicons/vue/20/solid'
import AutocompleteSelect from './components/AutocompleteSelect.vue'
import CsrfInput from './components/CsrfInput.vue'
import FormField from './components/FormField.vue'
import PageHeading from './components/PageHeading.vue'
import { fieldClass, primaryButton, secondaryButton } from './helpers.js'
import { formatDateTimeDmy, nowLocalMinutes, tireStatusTone } from './tireStatus.js'

const props = defineProps({ data: { type: Object, required: true } })

const deposits = computed(() => props.data.deposits ?? [])
const stock = computed(() => props.data.stock ?? [])
const movements = computed(() => props.data.movements ?? [])
const old = computed(() => props.data.oldInput ?? {})
const now = nowLocalMinutes()

const TYPE_LABELS = { ENTRADA: 'Entrada', SALIDA: 'Salida', AJUSTE: 'Ajuste' }

const depositUrl = (id) => (id ? `${props.data.urls.index}?deposito=${id}` : props.data.urls.index)

const wantsForm = () => Object.keys(old.value).length > 0
  || (typeof window !== 'undefined' && window.location.hash === '#movimiento')
const open = ref(Boolean(props.data.canOperate) && deposits.value.length > 0 && wantsForm())

const defaultDeposit = () => {
  if (old.value.deposito_id) return old.value.deposito_id
  if (props.data.selectedDeposit) return String(props.data.selectedDeposit)
  return deposits.value.length === 1 ? String(deposits.value[0].id) : ''
}

const form = ref({
  type: old.value.tipo ?? 'ENTRADA',
  deposit: defaultDeposit(),
  tire: old.value.neumatico_id ?? '',
  quantity: old.value.cantidad ?? '1',
})

// Lo que se ofrece depende del tipo: entra lo que puede ingresar, sale lo que
// hay en ese depósito, y un ajuste puede tocar cualquiera. El servidor vuelve
// a validar todo.
const tireOptions = computed(() => {
  const tires = props.data.tires ?? []
  if (form.value.type === 'SALIDA') {
    const inDeposit = new Map(stock.value
      .filter((row) => String(row.depositId) === String(form.value.deposit) && row.quantity > 0)
      .map((row) => [row.neumaticId, row.quantity]))
    return tires
      .filter((tire) => inDeposit.has(tire.id))
      .map((tire) => ({ value: tire.id, label: tire.number, hint: `${tire.description || tire.statusLabel} · hay ${inDeposit.get(tire.id)}` }))
  }
  return tires
    .filter((tire) => form.value.type === 'AJUSTE' || tire.canEnter)
    .map((tire) => ({ value: tire.id, label: tire.number, hint: tire.description || tire.statusLabel }))
})

watch(() => [form.value.type, form.value.deposit], () => {
  if (!tireOptions.value.some((option) => String(option.value) === String(form.value.tire))) form.value.tire = ''
  if (form.value.type !== 'AJUSTE' && Number(form.value.quantity) < 1) form.value.quantity = '1'
})

const isAdjustment = computed(() => form.value.type === 'AJUSTE')

const signed = (movement) => {
  if (movement.type === 'SALIDA') return `−${movement.quantity}`
  if (movement.quantity > 0) return `+${movement.quantity}`
  return movement.quantity < 0 ? `−${Math.abs(movement.quantity)}` : '0'
}
</script>

<template>
  <div class="space-y-6">
    <PageHeading
      eyebrow="Neumáticos"
      title="Depósito"
      description="Qué neumáticos hay en cada depósito, y sus entradas, salidas y ajustes."
    >
      <template #actions>
        <button v-if="data.canOperate && deposits.length" type="button" :class="primaryButton" @click="open = true">
          <ArrowsRightLeftIcon class="mr-1.5 size-5" aria-hidden="true" />
          Registrar movimiento
        </button>
      </template>
    </PageHeading>

    <p v-if="deposits.length === 0" class="rounded-xl border border-border bg-surface-raised p-5 text-sm text-ink-muted">
      Todavía no hay depósitos activos.
      <a :href="data.urls.masters" class="font-semibold text-primary hover:text-primary-hover">Cargalos en Maestros</a>
      para poder registrar entradas y salidas.
    </p>

    <template v-else>
      <nav class="flex gap-2 overflow-x-auto pb-1" aria-label="Depósitos">
        <a :href="depositUrl(null)" :class="data.selectedDeposit === null ? primaryButton : secondaryButton" class="shrink-0" :aria-current="data.selectedDeposit === null ? 'page' : undefined">Todos</a>
        <a
          v-for="deposit in deposits"
          :key="deposit.id"
          :href="depositUrl(deposit.id)"
          :class="data.selectedDeposit === deposit.id ? primaryButton : secondaryButton"
          class="shrink-0"
          :aria-current="data.selectedDeposit === deposit.id ? 'page' : undefined"
        >
          {{ deposit.name }}
          <span class="ml-2 rounded-full bg-black/10 px-2 text-xs">{{ deposit.tires }}</span>
        </a>
      </nav>

      <section class="overflow-hidden rounded-xl border border-border bg-surface-raised" aria-labelledby="tire-deposit-stock">
        <div class="border-b border-border-subtle px-4 py-3 sm:px-5">
          <h2 id="tire-deposit-stock" class="text-base font-bold text-ink">Existencias</h2>
        </div>
        <p v-if="stock.length === 0" class="p-5 text-sm text-ink-muted">No hay neumáticos en {{ data.selectedDeposit ? 'este depósito' : 'los depósitos' }}.</p>
        <ul v-else class="divide-y divide-border-subtle">
          <li v-for="row in stock" :key="`${row.depositId}-${row.neumaticId}`" class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5">
            <div class="min-w-0">
              <a :href="row.tireUrl" class="font-semibold text-primary hover:text-primary-hover">{{ row.number }}</a>
              <p class="truncate text-sm text-ink-muted">{{ [row.description, data.selectedDeposit ? '' : row.depositName].filter(Boolean).join(' · ') || row.depositName }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
              <span :class="tireStatusTone(row.status)" class="rounded-full px-2.5 py-1 text-xs font-semibold">{{ row.statusLabel }}</span>
              <span v-if="row.quantity < 0" class="rounded-full bg-danger-subtle px-2.5 py-1 text-xs font-semibold text-danger-strong" title="Quedó negativa por un ajuste documentado">Existencia {{ row.quantity }}</span>
              <span v-else-if="row.quantity > 1" class="rounded-full bg-surface-subtle px-2.5 py-1 text-xs font-semibold text-ink">{{ row.quantity }} unidades</span>
            </div>
          </li>
        </ul>
      </section>

      <section class="overflow-hidden rounded-xl border border-border bg-surface-raised" aria-labelledby="tire-deposit-movements">
        <div class="border-b border-border-subtle px-4 py-3 sm:px-5">
          <h2 id="tire-deposit-movements" class="text-base font-bold text-ink">Últimos movimientos</h2>
          <p v-if="movements.length >= data.movementsLimit" class="mt-1 text-sm text-ink-muted">Se muestran los últimos {{ data.movementsLimit }}.</p>
        </div>
        <p v-if="movements.length === 0" class="p-5 text-sm text-ink-muted">Todavía no hay movimientos registrados.</p>
        <ul v-else class="divide-y divide-border-subtle">
          <li v-for="(movement, index) in movements" :key="index" class="flex flex-col gap-1 px-4 py-3 sm:flex-row sm:items-start sm:justify-between sm:px-5">
            <div class="min-w-0">
              <p class="text-sm font-semibold text-ink">
                {{ TYPE_LABELS[movement.type] ?? movement.type }} ·
                <a :href="movement.tireUrl" class="text-primary hover:text-primary-hover">{{ movement.number }}</a>
                <span class="font-normal text-ink-muted"> · {{ movement.depositName }}</span>
              </p>
              <p class="text-sm text-ink-muted">
                <template v-if="movement.sheetNumber">Planilla {{ movement.sheetNumber }}</template>
                <template v-if="movement.sheetNumber && movement.notes"> · </template>
                <template v-if="movement.notes">{{ movement.notes }}</template>
              </p>
              <p class="text-xs text-ink-subtle">{{ formatDateTimeDmy(movement.at) }}<template v-if="movement.userName"> · {{ movement.userName }}</template></p>
            </div>
            <span class="text-sm font-bold text-ink sm:pl-4">{{ signed(movement) }}</span>
          </li>
        </ul>
      </section>
    </template>

    <Teleport to="body">
      <div v-if="open" class="fixed inset-0 z-[80] flex items-start justify-center overflow-y-auto bg-black/45 p-4 sm:items-center" role="presentation" @mousedown.self="open = false" @keydown.esc="open = false">
        <section id="movimiento" role="dialog" aria-modal="true" aria-labelledby="tire-movement-title" class="w-full max-w-xl rounded-xl bg-surface-raised shadow-2xl">
          <header class="border-b border-border-subtle px-6 py-4">
            <h2 id="tire-movement-title" class="text-lg font-bold text-ink">Registrar movimiento</h2>
          </header>
          <form method="post" :action="data.urls.movement" class="grid gap-4 p-6">
            <CsrfInput :csrf="data.csrf" />

            <fieldset>
              <legend class="mb-1.5 block text-sm font-semibold text-ink">Tipo</legend>
              <div class="grid grid-cols-3 gap-2">
                <label v-for="type in data.types" :key="type.value" :class="form.type === type.value ? primaryButton : secondaryButton" class="cursor-pointer">
                  <input v-model="form.type" type="radio" name="tipo" :value="type.value" class="sr-only" />
                  {{ type.label }}
                </label>
              </div>
            </fieldset>

            <FormField label="Depósito" for-id="tire-movement-deposit">
              <select id="tire-movement-deposit" v-model="form.deposit" name="deposito_id" required :class="fieldClass">
                <option v-if="deposits.length !== 1" value="" disabled>Elegí un depósito</option>
                <option v-for="deposit in deposits" :key="deposit.id" :value="String(deposit.id)">{{ deposit.name }} · {{ deposit.branchName }}</option>
              </select>
            </FormField>

            <div>
              <span class="mb-1.5 block text-sm font-semibold text-ink">Neumático</span>
              <AutocompleteSelect
                v-model="form.tire"
                name="neumatico_id"
                :options="tireOptions"
                required
                placeholder="Buscá por número"
                :empty-label="form.type === 'SALIDA' ? 'No hay neumáticos en este depósito' : 'Sin resultados'"
              />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
              <FormField label="Cantidad" for-id="tire-movement-quantity" :hint="isAdjustment ? 'Negativa para descontar, positiva para sumar.' : ''">
                <input id="tire-movement-quantity" v-model="form.quantity" name="cantidad" type="number" step="1" :min="isAdjustment ? undefined : 1" required :class="fieldClass" />
              </FormField>
              <FormField v-if="!isAdjustment" label="Número de planilla" for-id="tire-movement-sheet">
                <input id="tire-movement-sheet" name="numero_planilla" maxlength="30" required autocomplete="off" :value="old.numero_planilla" :class="fieldClass" />
              </FormField>
            </div>

            <FormField :label="isAdjustment ? 'Motivo del ajuste' : 'Observaciones (opcional)'" for-id="tire-movement-notes" :hint="isAdjustment ? 'Un ajuste sin motivo no se registra.' : ''">
              <input id="tire-movement-notes" name="observaciones" maxlength="500" :required="isAdjustment" autocomplete="off" :value="old.observaciones" :class="fieldClass" />
            </FormField>

            <FormField label="Fecha y hora" for-id="tire-movement-date">
              <input id="tire-movement-date" name="fecha" type="datetime-local" required :max="now" :value="old.fecha ?? now" :class="fieldClass" />
            </FormField>

            <div class="flex justify-end gap-2 border-t border-border-subtle pt-4">
              <button type="button" :class="secondaryButton" @click="open = false">Cancelar</button>
              <button type="submit" :class="primaryButton">Registrar</button>
            </div>
          </form>
        </section>
      </div>
    </Teleport>
  </div>
</template>
