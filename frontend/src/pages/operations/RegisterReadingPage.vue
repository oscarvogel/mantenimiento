<script setup>
import { computed, reactive, ref } from 'vue'
import { ArrowPathIcon, CheckCircleIcon, MagnifyingGlassIcon } from '@heroicons/vue/24/outline'
import QuickReadingsPage from './QuickReadingsPage.vue'
import PageHeading from './components/PageHeading.vue'
import PanelCard from './components/PanelCard.vue'
import { fieldClass, formatHours, formatKilometers, normalizeDecimalInput, parseFlexibleNumber, parseKilometers, primaryButton } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })
const massiveMode = new URLSearchParams(window.location.search).get('modo') === 'masivo'
const search = ref(props.data.filters?.q || '')
const selectedId = ref(props.data.equipment.items.length === 1 ? props.data.equipment.items[0].id : null)
const kilometers = ref('')
const hours = ref('')
const recordedAt = ref(props.data.recordedAtDefault)
const notes = ref('')
const saving = ref(false)
const message = ref('')
const error = ref('')
const csrf = reactive({ ...props.data.csrf })
const maintenance = reactive(Object.fromEntries(props.data.equipment.items.map((item) => [item.id, item.maintenance])))

const selected = computed(() => props.data.equipment.items.find((item) => item.id === selectedId.value) || null)
const searchUrl = computed(() => {
  const url = new URL(props.data.routes.index, window.location.origin)
  if (search.value.trim()) url.searchParams.set('q', search.value.trim())
  return url.toString()
})
const submitSearch = () => window.location.assign(searchUrl.value)
const currentLabel = computed(() => {
  if (!selected.value) return '—'
  const values = []
  if (selected.value.controlsKm) values.push(selected.value.currentKm == null ? 'Sin km' : formatKilometers(selected.value.currentKm))
  if (selected.value.controlsHours) values.push(selected.value.currentHours == null ? 'Sin horómetro' : formatHours(selected.value.currentHours))
  return values.join(' · ') || 'Sin lectura previa'
})
const preventiveLabel = computed(() => {
  const item = selected.value ? maintenance[selected.value.id] : null
  if (!item) return ''
  if (item.state === 'SIN_PLAN') return 'Lectura guardada. El equipo no tiene un servicio preventivo aplicable.'
  const critical = item.primaryPlan?.critical
  if (!critical) return `Estado preventivo: ${item.state}.`
  const amount = Math.abs(Number(critical.value)).toLocaleString('es-AR', { maximumFractionDigits: critical.unit === 'h' ? 1 : 0 })
  return item.state === 'VENCIDO'
    ? `Atención: mantenimiento vencido por ${amount} ${critical.unit}.`
    : item.state === 'PROXIMO'
      ? `Próximo servicio: faltan ${amount} ${critical.unit}.`
      : `Estado preventivo: ${item.state}.`
})

const validate = () => {
  if (!selected.value) return 'Seleccioná un equipo.'
  if (selected.value.controlsKm) {
    const next = parseKilometers(kilometers.value)
    if (next === null) return 'Ingresá un kilometraje válido.'
    if (selected.value.currentKm != null && next < Number(selected.value.currentKm)) return `El kilometraje no puede ser menor a ${formatKilometers(selected.value.currentKm)}.`
  }
  if (selected.value.controlsHours) {
    const next = parseFlexibleNumber(hours.value)
    if (next === null) return 'Ingresá un horómetro válido.'
    if (selected.value.currentHours != null && next < Number(selected.value.currentHours)) return `El horómetro no puede ser menor a ${formatHours(selected.value.currentHours)}.`
  }
  return ''
}

const save = async () => {
  error.value = validate()
  message.value = ''
  if (error.value || saving.value) return
  saving.value = true
  const body = new FormData()
  body.append(csrf.name, csrf.hash)
  body.append('equipmentId', String(selected.value.id))
  body.append('kilometers', selected.value.controlsKm ? kilometers.value : '')
  body.append('hours', selected.value.controlsHours ? normalizeDecimalInput(hours.value) : '')
  body.append('recordedAt', recordedAt.value)
  body.append('notes', notes.value)
  try {
    const response = await fetch(props.data.routes.submitRow, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } })
    const payload = await response.json()
    if (payload.csrf) Object.assign(csrf, payload.csrf)
    if (!response.ok || !payload.result?.success) throw new Error(payload.error || payload.result?.message || 'No se pudo guardar la lectura.')
    if (payload.maintenance) maintenance[selected.value.id] = payload.maintenance
    if (payload.result.currentKilometers != null) selected.value.currentKm = payload.result.currentKilometers
    if (payload.result.currentHours != null) selected.value.currentHours = payload.result.currentHours
    kilometers.value = ''
    hours.value = ''
    notes.value = ''
    message.value = `Lectura registrada correctamente. ${preventiveLabel.value}`
  } catch (e) {
    error.value = e.message || 'No se pudo guardar la lectura.'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <QuickReadingsPage v-if="massiveMode" :data="data" />
  <template v-else>
    <PageHeading
      title="Registrar km/horas"
      eyebrow="Carga individual"
      description="Buscá un equipo, ingresá su valor actual y guardá. El sistema recalcula el mantenimiento automáticamente."
    />

    <PanelCard title="1. Buscar equipo">
      <form method="get" :action="data.routes.index" class="flex flex-col gap-3 sm:flex-row" @submit.prevent="submitSearch">
        <label class="min-w-0 flex-1">
          <span class="sr-only">Buscar equipo</span>
          <span class="relative block">
            <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-ink-subtle" />
            <input v-model="search" type="search" autocomplete="off" placeholder="Patente, interno, descripción o chasis…" :class="`${fieldClass} pl-10`" />
          </span>
        </label>
        <button type="submit" :class="primaryButton">Buscar</button>
      </form>

      <div v-if="data.equipment.items.length" class="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
        <button
          v-for="item in data.equipment.items"
          :key="item.id"
          type="button"
          class="rounded-xl border p-4 text-left transition"
          :class="selectedId === item.id ? 'border-primary bg-primary-subtle' : 'border-border bg-surface-raised hover:border-primary/40'"
          @click="selectedId = item.id; kilometers = ''; hours = ''; error = ''; message = ''"
        >
          <strong class="block text-ink">{{ item.code }}<span v-if="item.plate"> · {{ item.plate }}</span></strong>
          <span class="mt-1 block text-sm text-ink-muted">{{ item.typeName }} · {{ item.branchName }}</span>
          <span class="mt-2 block text-xs font-semibold text-ink-muted">
            Actual: {{ item.controlsKm && item.currentKm != null ? formatKilometers(item.currentKm) : '' }}
            <span v-if="item.controlsKm && item.controlsHours"> · </span>
            {{ item.controlsHours && item.currentHours != null ? formatHours(item.currentHours) : '' }}
          </span>
        </button>
      </div>
      <p v-else class="mt-4 text-sm text-ink-muted">No encontramos equipos con ese criterio.</p>
    </PanelCard>

    <PanelCard v-if="selected" title="2. Cargar valor actual">
      <div class="mb-4 rounded-lg border border-border bg-surface-subtle p-4">
        <strong class="text-ink">{{ selected.code }}<span v-if="selected.plate"> · {{ selected.plate }}</span></strong>
        <p class="mt-1 text-sm text-ink-muted">Última lectura: {{ currentLabel }}</p>
      </div>

      <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="save">
        <label v-if="selected.controlsKm" class="block">
          <span class="mb-1.5 block text-sm font-semibold text-ink">Kilometraje actual</span>
          <input v-model="kilometers" type="text" inputmode="numeric" autocomplete="off" placeholder="Ej. 185420" :class="fieldClass" />
        </label>
        <label v-if="selected.controlsHours" class="block">
          <span class="mb-1.5 block text-sm font-semibold text-ink">Horómetro actual</span>
          <input v-model="hours" type="text" inputmode="decimal" autocomplete="off" placeholder="Ej. 8420,5" :class="fieldClass" />
        </label>
        <label class="block">
          <span class="mb-1.5 block text-sm font-semibold text-ink">Fecha y hora</span>
          <input v-model="recordedAt" type="datetime-local" :class="fieldClass" />
        </label>
        <label class="block sm:col-span-2">
          <span class="mb-1.5 block text-sm font-semibold text-ink">Observación <span class="font-normal text-ink-muted">(opcional)</span></span>
          <textarea v-model="notes" rows="2" :class="fieldClass" placeholder="Ej. lectura informada por chofer" />
        </label>

        <p v-if="error" class="sm:col-span-2 rounded-lg bg-danger-subtle px-4 py-3 text-sm font-medium text-danger-strong">{{ error }}</p>
        <p v-if="message" class="sm:col-span-2 flex items-start gap-2 rounded-lg bg-success-subtle px-4 py-3 text-sm font-medium text-success-strong">
          <CheckCircleIcon class="mt-0.5 size-5 shrink-0" />{{ message }}
        </p>

        <div class="sm:col-span-2 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <button type="submit" :disabled="saving" :class="primaryButton">
            <ArrowPathIcon class="size-5" />
            {{ saving ? 'Guardando…' : 'Guardar lectura' }}
          </button>
          <a :href="`${data.routes.index}?modo=masivo`" class="text-sm font-semibold text-primary hover:underline">Ir a carga masiva</a>
        </div>
      </form>
    </PanelCard>
  </template>
</template>
