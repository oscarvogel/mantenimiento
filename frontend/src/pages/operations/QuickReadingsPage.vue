<script setup>
import { reactive, ref } from 'vue'
import { ArrowPathIcon, CheckCircleIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline'
import CsrfInput from './components/CsrfInput.vue'
import EmptyState from './components/EmptyState.vue'
import FlashMessages from './components/FlashMessages.vue'
import PageHeading from './components/PageHeading.vue'
import PaginationBar from './components/PaginationBar.vue'
import PanelCard from './components/PanelCard.vue'
import EquipmentThumbnail from './components/EquipmentThumbnail.vue'
import { fieldClass, primaryButton, secondaryButton } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })
const results = ref([...props.data.results])
const saving = ref(false)
const rows = reactive(Object.fromEntries(props.data.equipment.items.map((equipment) => [equipment.id, {
  kilometers: '', hours: '', recordedAt: props.data.recordedAtDefault, notes: '', currentKm: equipment.currentKm, currentHours: equipment.currentHours,
}])) )
const csrf = reactive({ ...props.data.csrf })

const submitRows = async () => {
  const pending = props.data.equipment.items.filter(({ id }) => rows[id].kilometers !== '' || rows[id].hours !== '')
  if (!pending.length || saving.value) return
  saving.value = true
  results.value = []
  for (const equipment of pending) {
    const values = rows[equipment.id]
    const body = new FormData()
    body.append(csrf.name, csrf.hash)
    body.append('equipmentId', String(equipment.id))
    body.append('kilometers', values.kilometers)
    body.append('hours', values.hours)
    body.append('recordedAt', values.recordedAt)
    body.append('notes', values.notes)
    try {
      const response = await fetch(props.data.routes.submitRow, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } })
      const payload = await response.json()
      if (payload.csrf) Object.assign(csrf, payload.csrf)
      const result = payload.result ?? { rowNumber: results.value.length + 1, equipmentId: equipment.id, success: false, message: payload.error ?? 'No se pudo guardar la fila.', plansEvaluated: 0, overduePlans: 0 }
      results.value.push(result)
      if (result.success) {
        values.currentKm = result.currentKilometers
        values.currentHours = result.currentHours
        values.kilometers = ''
        values.hours = ''
        values.notes = ''
      }
    } catch {
      results.value.push({ rowNumber: results.value.length + 1, equipmentId: equipment.id, success: false, message: 'No se pudo conectar para guardar la fila.', plansEvaluated: 0, overduePlans: 0 })
    }
  }
  saving.value = false
}
</script>

<template>
  <FlashMessages :flash="data.flash" />
  <PageHeading title="Carga rápida de lecturas" eyebrow="Medición de uso" description="Actualizá varios equipos sin salir de la grilla. Cada fila se valida y procesa de manera independiente.">
    <template #actions><a :href="data.routes.assets" :class="secondaryButton">Ver equipos</a></template>
  </PageHeading>

  <PanelCard title="Buscar equipos" class="mb-6">
    <form method="get" :action="data.routes.index" class="grid gap-4 md:grid-cols-4">
      <label class="text-sm font-semibold text-ink">Código, patente o chasis<input name="q" :value="data.filters.q" :class="`${fieldClass} mt-1`" /></label>
      <label class="text-sm font-semibold text-ink">Sucursal<select name="sucursal_id" :value="data.filters.branchId" :class="`${fieldClass} mt-1`"><option value="">Todas</option><option v-for="branch in data.catalogs.branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option></select></label>
      <label class="text-sm font-semibold text-ink">Tipo<select name="tipo_id" :value="data.filters.typeId" :class="`${fieldClass} mt-1`"><option value="">Todos</option><option v-for="type in data.catalogs.types" :key="type.id" :value="type.id">{{ type.name }}</option></select></label>
      <button type="submit" :class="`${secondaryButton} self-end`">Filtrar</button>
    </form>
  </PanelCard>

  <PanelCard v-if="results.length" title="Resultado de la última carga" :count="results.length" class="mb-6" aria-live="polite">
    <ul class="divide-y divide-border-subtle">
      <li v-for="row in results" :key="`${row.rowNumber}-${row.equipmentId}`" class="flex gap-3 py-3 text-sm">
        <CheckCircleIcon v-if="row.success" class="size-5 shrink-0 text-success" aria-hidden="true" />
        <ExclamationTriangleIcon v-else class="size-5 shrink-0 text-danger" aria-hidden="true" />
        <span><strong>Equipo #{{ row.equipmentId }}:</strong> {{ row.message }}<template v-if="row.success"> · {{ row.plansEvaluated }} planes reevaluados · {{ row.overduePlans }} vencidos</template></span>
      </li>
    </ul>
  </PanelCard>

  <form method="post" :action="data.routes.submit" @submit.prevent="submitRows">
    <CsrfInput :csrf="data.csrf" />
    <PanelCard title="Equipos activos" :count="data.equipment.total">
      <EmptyState v-if="data.equipment.items.length === 0" title="No hay equipos para los filtros seleccionados" />
      <div v-else class="grid gap-4 lg:grid-cols-2 2xl:grid-cols-3">
        <fieldset v-for="equipment in data.equipment.items" :key="equipment.id" class="rounded-xl border border-border bg-white p-4 shadow-sm">
          <legend class="sr-only">Lectura para {{ equipment.code }}</legend>
          <div class="flex items-start gap-3">
            <EquipmentThumbnail :url="equipment.photoUrl" :code="equipment.code" size="lg" />
            <div class="min-w-0"><a :href="equipment.detailUrl" class="font-bold text-primary hover:underline">{{ equipment.code }}</a><p class="truncate text-sm text-ink-muted">{{ equipment.plate || 'Sin patente' }} · {{ equipment.typeName }}</p><p class="text-xs text-ink-subtle">{{ equipment.branchName }} · Última lectura: {{ equipment.lastReadingAt || 'sin lecturas válidas' }}</p></div>
          </div>
          <div class="mt-4 grid gap-3 sm:grid-cols-2">
            <label class="text-sm font-semibold text-ink">Kilómetros <span class="block text-xs font-normal text-ink-muted">Último: {{ rows[equipment.id].currentKm ?? 'sin datos' }}</span><input v-model="rows[equipment.id].kilometers" type="number" min="0" :name="`readings[${equipment.id}][kilometers]`" :disabled="!equipment.controlsKm || !data.canRegister || saving" :placeholder="equipment.controlsKm ? 'Nuevo valor' : 'No aplica'" :class="`${fieldClass} mt-1`" /></label>
            <label class="text-sm font-semibold text-ink">Horómetro <span class="block text-xs font-normal text-ink-muted">Último: {{ rows[equipment.id].currentHours ?? 'sin datos' }}</span><input v-model="rows[equipment.id].hours" type="number" min="0" step="0.1" :name="`readings[${equipment.id}][hours]`" :disabled="!equipment.controlsHours || !data.canRegister || saving" :placeholder="equipment.controlsHours ? 'Nuevo valor' : 'No aplica'" :class="`${fieldClass} mt-1`" /></label>
            <label class="text-sm font-semibold text-ink sm:col-span-2">Fecha y hora<input v-model="rows[equipment.id].recordedAt" type="datetime-local" :name="`readings[${equipment.id}][recordedAt]`" :disabled="!data.canRegister || saving" :class="`${fieldClass} mt-1`" /></label>
            <label class="text-sm font-semibold text-ink sm:col-span-2">Observación opcional<input v-model="rows[equipment.id].notes" :name="`readings[${equipment.id}][notes]`" maxlength="1000" :disabled="!data.canRegister || saving" :class="`${fieldClass} mt-1`" /></label>
          </div>
        </fieldset>
      </div>
      <template #footer><div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"><PaginationBar :pagination="data.equipment.pagination" /><button v-if="data.canRegister && data.equipment.items.length" type="submit" :disabled="saving" :class="primaryButton"><ArrowPathIcon class="mr-2 size-5" :class="saving ? 'animate-spin' : ''" aria-hidden="true" />{{ saving ? 'Guardando por fila…' : 'Guardar lecturas ingresadas' }}</button></div></template>
    </PanelCard>
  </form>
</template>
