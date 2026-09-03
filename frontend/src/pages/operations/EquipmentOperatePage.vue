<script setup>
import { computed, reactive, ref } from 'vue'
import { CheckCircleIcon, ExclamationTriangleIcon, WrenchScrewdriverIcon } from '@heroicons/vue/24/outline'
import PanelCard from './components/PanelCard.vue'
import { fieldClass, formatHours, formatKilometers, normalizeDecimalInput, parseFlexibleNumber, parseKilometers, primaryButton } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })
const equipment = reactive({ ...props.data.equipment })
const csrf = reactive({ ...props.data.csrf })
const kilometers = ref('')
const hours = ref('')
const recordedAt = ref(props.data.recordedAtDefault)
const notes = ref('')
const readingSaving = ref(false)
const readingMessage = ref('')
const readingError = ref('')
const incident = ref('')
const incidentSaving = ref(false)
const incidentMessage = ref('')
const incidentError = ref('')
const readingPending = ref(Boolean(props.data.readingPending))
const lastReading = ref(props.data.lastReading)

const title = computed(() => [equipment.code, equipment.plate].filter(Boolean).join(' · '))
const vehicleDescription = computed(() => [equipment.brandName, equipment.modelName, equipment.typeName].filter(Boolean).join(' · '))
const currentReading = computed(() => {
  const parts = []
  if (equipment.controlsKm) parts.push(equipment.currentKm == null ? 'Sin km' : formatKilometers(equipment.currentKm))
  if (equipment.controlsHours) parts.push(equipment.currentHours == null ? 'Sin horómetro' : formatHours(equipment.currentHours))
  return parts.join(' · ') || 'Sin lectura registrada'
})
const lastReadingDate = computed(() => {
  if (!lastReading.value?.at) return 'Nunca'
  return new Intl.DateTimeFormat('es-AR', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(lastReading.value.at))
})

const validateReading = () => {
  if (equipment.controlsKm) {
    const next = parseKilometers(kilometers.value)
    if (next === null) return 'Ingresá un kilometraje válido.'
    if (equipment.currentKm != null && next < Number(equipment.currentKm)) return `El kilometraje no puede ser menor a ${formatKilometers(equipment.currentKm)}.`
  }
  if (equipment.controlsHours) {
    const next = parseFlexibleNumber(hours.value)
    if (next === null) return 'Ingresá un horómetro válido.'
    if (equipment.currentHours != null && next < Number(equipment.currentHours)) return `El horómetro no puede ser menor a ${formatHours(equipment.currentHours)}.`
  }
  return ''
}

const saveReading = async () => {
  readingError.value = validateReading()
  readingMessage.value = ''
  if (readingError.value || readingSaving.value) return
  readingSaving.value = true
  const body = new FormData()
  body.append(csrf.name, csrf.hash)
  body.append('equipmentId', String(equipment.id))
  body.append('kilometers', equipment.controlsKm ? kilometers.value : '')
  body.append('hours', equipment.controlsHours ? normalizeDecimalInput(hours.value) : '')
  body.append('recordedAt', recordedAt.value)
  body.append('notes', notes.value)

  try {
    const response = await fetch(props.data.routes.submitReading, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } })
    const payload = await response.json()
    if (payload.csrf) Object.assign(csrf, payload.csrf)
    if (!response.ok || !payload.result?.success) throw new Error(payload.error || payload.result?.message || 'No se pudo guardar la lectura.')
    if (payload.result.currentKilometers != null) equipment.currentKm = payload.result.currentKilometers
    if (payload.result.currentHours != null) equipment.currentHours = payload.result.currentHours
    lastReading.value = { at: new Date(recordedAt.value).toISOString(), kilometers: equipment.currentKm, hours: equipment.currentHours, userName: null }
    readingPending.value = false
    kilometers.value = ''
    hours.value = ''
    notes.value = ''
    readingMessage.value = 'Lectura registrada correctamente.'
  } catch (error) {
    readingError.value = error.message || 'No se pudo guardar la lectura.'
  } finally {
    readingSaving.value = false
  }
}

const reportIncident = async () => {
  incidentError.value = ''
  incidentMessage.value = ''
  if (incident.value.trim().length < 5) {
    incidentError.value = 'Contanos brevemente qué problema encontraste.'
    return
  }
  if (incidentSaving.value) return
  incidentSaving.value = true
  const body = new FormData()
  body.append(csrf.name, csrf.hash)
  body.append('description', incident.value.trim())

  try {
    const response = await fetch(props.data.routes.reportIncident, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } })
    const payload = await response.json()
    if (payload.csrf) Object.assign(csrf, payload.csrf)
    if (!response.ok) throw new Error(payload.error || 'No se pudo reportar la incidencia.')
    incident.value = ''
    incidentMessage.value = `${payload.message} Solicitud #${payload.requestId}.`
  } catch (error) {
    incidentError.value = error.message || 'No se pudo reportar la incidencia.'
  } finally {
    incidentSaving.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-3xl space-y-4 pb-8">
    <section class="rounded-2xl border border-border bg-surface-raised p-5 shadow-sm">
      <div class="flex items-start justify-between gap-3">
        <div>
          <p class="text-xs font-bold uppercase tracking-wider text-primary">Portal del chofer</p>
          <h1 class="mt-1 text-2xl font-bold text-ink">{{ title }}</h1>
          <p class="mt-1 text-sm text-ink-muted">{{ vehicleDescription }} · {{ equipment.branchName }}</p>
        </div>
        <span class="rounded-full px-3 py-1 text-xs font-bold" :class="readingPending ? 'bg-warning-subtle text-warning-strong' : 'bg-success-subtle text-success-strong'">
          {{ readingPending ? 'Lectura pendiente' : 'Lectura al día' }}
        </span>
      </div>

      <div class="mt-5 grid grid-cols-2 gap-3">
        <div class="rounded-xl bg-surface-subtle p-4">
          <span class="block text-xs font-semibold uppercase text-ink-muted">Actual</span>
          <strong class="mt-1 block text-lg text-ink">{{ currentReading }}</strong>
        </div>
        <div class="rounded-xl bg-surface-subtle p-4">
          <span class="block text-xs font-semibold uppercase text-ink-muted">Última carga</span>
          <strong class="mt-1 block text-sm text-ink">{{ lastReadingDate }}</strong>
          <span v-if="lastReading?.userName" class="mt-1 block text-xs text-ink-muted">por {{ lastReading.userName }}</span>
        </div>
      </div>

      <div v-if="readingPending" class="mt-4 flex gap-2 rounded-xl bg-warning-subtle p-3 text-sm font-medium text-warning-strong">
        <ExclamationTriangleIcon class="size-5 shrink-0" />
        Este equipo lleva 7 días o más sin una lectura. Registrá los km/horas actuales.
      </div>
    </section>

    <PanelCard v-if="data.can.registerReading" title="Registrar km / horas">
      <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="saveReading">
        <label v-if="equipment.controlsKm" class="block">
          <span class="mb-1.5 block text-sm font-semibold text-ink">Kilometraje actual</span>
          <input v-model="kilometers" type="text" inputmode="numeric" autocomplete="off" placeholder="Ej. 185420" :class="fieldClass" />
        </label>
        <label v-if="equipment.controlsHours" class="block">
          <span class="mb-1.5 block text-sm font-semibold text-ink">Horómetro actual</span>
          <input v-model="hours" type="text" inputmode="decimal" autocomplete="off" placeholder="Ej. 8420,5" :class="fieldClass" />
        </label>
        <label class="block">
          <span class="mb-1.5 block text-sm font-semibold text-ink">Fecha y hora</span>
          <input v-model="recordedAt" type="datetime-local" :class="fieldClass" />
        </label>
        <label class="block sm:col-span-2">
          <span class="mb-1.5 block text-sm font-semibold text-ink">Observación <span class="font-normal text-ink-muted">(opcional)</span></span>
          <textarea v-model="notes" rows="2" :class="fieldClass" placeholder="Ej. lectura semanal" />
        </label>
        <p v-if="readingError" class="sm:col-span-2 rounded-lg bg-danger-subtle px-4 py-3 text-sm font-medium text-danger-strong">{{ readingError }}</p>
        <p v-if="readingMessage" class="sm:col-span-2 flex gap-2 rounded-lg bg-success-subtle px-4 py-3 text-sm font-medium text-success-strong"><CheckCircleIcon class="size-5" />{{ readingMessage }}</p>
        <button type="submit" :disabled="readingSaving" :class="`${primaryButton} sm:col-span-2 sm:w-fit`">{{ readingSaving ? 'Guardando…' : 'Guardar lectura' }}</button>
      </form>
    </PanelCard>

    <PanelCard v-if="data.can.reportIncident" title="Reportar un problema">
      <p class="mb-3 text-sm text-ink-muted">No hace falta completar una orden de trabajo. Contá qué viste y mantenimiento lo revisará.</p>
      <form class="space-y-3" @submit.prevent="reportIncident">
        <textarea v-model="incident" rows="4" maxlength="2000" :class="fieldClass" placeholder="Ej. ruido fuerte en rueda delantera derecha al frenar" />
        <p v-if="incidentError" class="rounded-lg bg-danger-subtle px-4 py-3 text-sm font-medium text-danger-strong">{{ incidentError }}</p>
        <p v-if="incidentMessage" class="rounded-lg bg-success-subtle px-4 py-3 text-sm font-medium text-success-strong">{{ incidentMessage }}</p>
        <button type="submit" :disabled="incidentSaving" :class="primaryButton">{{ incidentSaving ? 'Enviando…' : 'Reportar incidencia' }}</button>
      </form>
    </PanelCard>

    <PanelCard title="Consultar">
      <div class="grid gap-2 sm:grid-cols-2">
        <a v-if="data.can.viewOrders" :href="data.routes.orders" class="flex min-h-12 items-center gap-2 rounded-xl border border-border px-4 py-3 font-semibold text-ink hover:border-primary/40"><WrenchScrewdriverIcon class="size-5 text-primary" />Órdenes de trabajo</a>
        <a v-if="data.can.viewPlans" :href="data.routes.plans" class="flex min-h-12 items-center rounded-xl border border-border px-4 py-3 font-semibold text-ink hover:border-primary/40">Mantenimientos</a>
        <a :href="data.routes.detail" class="flex min-h-12 items-center rounded-xl border border-border px-4 py-3 font-semibold text-ink hover:border-primary/40">Ver ficha completa</a>
      </div>
    </PanelCard>
  </div>
</template>
