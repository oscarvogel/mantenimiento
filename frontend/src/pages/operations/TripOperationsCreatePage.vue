<script setup>
import { computed, ref, watch } from 'vue'
import { PlusIcon, TrashIcon } from '@heroicons/vue/24/outline'
import PageHeading from './components/PageHeading.vue'
import AutocompleteSelect from './components/AutocompleteSelect.vue'
import PanelCard from './components/PanelCard.vue'
import { fieldClass, primaryButton, secondaryButton, nowLocal } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })

const options = computed(() => props.data.options ?? props.data)
const links = computed(() => props.data.links ?? { index: '/viajes/operativos', store: '/viajes/operativos' })
const csrf = computed(() => props.data.csrf ?? { name: 'csrf_test_name', hash: '' })
const branches = computed(() => options.value.branches ?? [])
const tractors = computed(() => options.value.tractors ?? [])
const trailers = computed(() => options.value.trailers ?? [])
const drivers = computed(() => options.value.drivers ?? [])
const customers = computed(() => options.value.customers ?? [])
const currencies = computed(() => options.value.currencies?.length ? options.value.currencies : [{ code: 'ARS', label: 'Peso argentino' }])
const localities = computed(() => options.value.localities ?? [])
const company = computed(() => options.value.company ?? {})
const nextNumber = computed(() => options.value.nextNumber ?? null)

const old = computed(() => props.data.old ?? {})
const newLeg = () => ({ codigo_origen_destino: '', localidad_origen_id: '', localidad_destino_id: '', cliente_id: '', km: '', toneladas: '', flete_facturacion: '', flete_liquidacion: '', moneda: 'ARS', cotizacion: '1', observaciones: '' })
const initialLegs = () => Array.isArray(old.value.tramos) && old.value.tramos.length
  ? old.value.tramos.map((row) => ({ ...newLeg(), ...row }))
  : [newLeg()]
const legs = ref(initialLegs())
const tractorId = ref(old.value.tractor_id ?? '')
const trailerIds = ref(old.value.acoplado_id
  ? (Array.isArray(old.value.acoplado_id) ? old.value.acoplado_id.map(String) : [String(old.value.acoplado_id)])
  : [])
const branchId = ref(old.value.sucursal_id === undefined || old.value.sucursal_id === null ? '' : String(old.value.sucursal_id))
const departure = ref(old.value.fecha_salida_real ?? nowLocal())
const returnAt = ref(old.value.fecha_regreso_estimado ?? '')
const kmSalida = ref(old.value.km_salida ?? '')
const selectedDrivers = ref(Array.isArray(old.value.choferes) ? old.value.choferes.map(String) : [])
const autoDrivers = ref([])
const percentages = ref({})

const selectedTractor = computed(() => tractors.value.find((equipment) => String(equipment.id) === String(tractorId.value)) ?? null)
const domain = computed(() => selectedTractor.value?.plate ?? '')
const linkedTrailerIdsFor = (equipmentId) => {
  if (!equipmentId) return []
  const tractor = tractors.value.find((equipment) => String(equipment.id) === String(equipmentId))
  if (!tractor) return []

  return (tractor.linkedTrailerIds ?? []).map(String)
}
const linkedTrailersFor = (equipmentId) => {
  const ids = linkedTrailerIdsFor(equipmentId)

  return trailers.value.filter((trailer) => ids.includes(String(trailer.id)))
}
const trailerHint = computed(() => {
  const linked = linkedTrailersFor(tractorId.value)
  if (linked.length === 0) return ''
  if (linked.length === 1) return `Acoplado actual del camión: ${linked[0].label}.`

  return `Bitrén: este camión tiene ${linked.length} semirremolques enganchados (${linked.map((trailer) => trailer.label).join(', ')}). Se preseleccionaron todos: el viaje registra la composición completa.`
})
const companyLabel = computed(() => company.value.name || company.value.legalName || 'Empresa actual')
const kmSalidaHint = computed(() => {
  const lastKm = selectedTractor.value?.lastKm
  return lastKm === null || lastKm === undefined
    ? 'Sin lectura previa. Completá el odómetro actual del tablero para iniciar.'
    : `Última lectura registrada: ${Number(lastKm).toLocaleString('es-AR')} km. Confirmá la lectura actual en el tablero.`
})
const assignedDriversFor = (equipmentId) => {
  if (!equipmentId) return []
  const id = String(equipmentId)
  return drivers.value
    .filter((driver) => String(driver.equipmentId ?? '') === id)
    .map((driver) => String(driver.id))
}
const assignedDriverNames = computed(() => {
  if (!tractorId.value) return []
  const id = String(tractorId.value)
  return drivers.value
    .filter((driver) => String(driver.equipmentId ?? '') === id)
    .map((driver) => driver.label)
})
const driverFor = (driverId) => drivers.value.find((driver) => String(driver.id) === String(driverId)) ?? null
const defaultPercentageFor = (driverId) => driverFor(driverId)?.liquidationPercentage ?? ''
const selectedDriverRows = computed(() => selectedDrivers.value.map((driverId) => ({
  id: String(driverId),
  label: driverFor(driverId)?.label ?? `Chofer ${driverId}`,
})))
const seedPercentages = (saved = {}) => {
  const next = {}
  for (const driverId of selectedDrivers.value) {
    const key = String(driverId)
    next[key] = saved[key] ?? defaultPercentageFor(key)
  }
  percentages.value = next
}
const syncPercentages = () => seedPercentages(percentages.value)

const initialAssignedDrivers = assignedDriversFor(tractorId.value)
if (selectedDrivers.value.length === 0 && initialAssignedDrivers.length > 0) {
  selectedDrivers.value = initialAssignedDrivers
  autoDrivers.value = [...initialAssignedDrivers]
}
seedPercentages(old.value.choferes_porcentajes ?? {})
if (branchId.value === '' && selectedTractor.value) branchId.value = String(selectedTractor.value.branchId)
if (kmSalida.value === '' && selectedTractor.value?.lastKm !== null && selectedTractor.value?.lastKm !== undefined) {
  kmSalida.value = String(selectedTractor.value.lastKm)
}
if (!trailerIds.value.length) {
  trailerIds.value = linkedTrailerIdsFor(tractorId.value)
}

watch(tractorId, (value) => {
  const tractor = selectedTractor.value
  if (tractor) {
    branchId.value = String(tractor.branchId)
    if (tractor.lastKm !== null && tractor.lastKm !== undefined) kmSalida.value = String(tractor.lastKm)
  }
  // La composición arranca con los semirremolques que el camión tiene
  // enganchados. Con uno se preselecciona; con varios (bitrén) se
  // preselecciona la composición completa. El operador puede cambiarla.
  trailerIds.value = linkedTrailerIdsFor(value)
  const assigned = assignedDriversFor(value)
  const previousAuto = autoDrivers.value
  const manual = selectedDrivers.value.map(String).filter((id) => !previousAuto.includes(id))
  selectedDrivers.value = Array.from(new Set([...manual, ...assigned]))
  autoDrivers.value = assigned
  syncPercentages()
})
watch(selectedDrivers, syncPercentages, { deep: true })

const addLeg = () => legs.value.push(newLeg())
const removeLeg = (index) => {
  if (legs.value.length > 1) legs.value.splice(index, 1)
}
const tractorOptions = computed(() => tractors.value.map((equipment) => {
  const hints = [equipment.type]
  if (equipment.lastKm !== null && equipment.lastKm !== undefined) hints.push(`Último km ${Number(equipment.lastKm).toLocaleString('es-AR')}`)
  return { value: equipment.id, label: equipment.label, hint: hints.filter(Boolean).join(' · ') }
}))
const trailerOptions = computed(() => {
  const linked = linkedTrailerIdsFor(tractorId.value)

  return trailers.value.map((equipment) => {
    const hints = [equipment.type]
    if (linked.includes(String(equipment.id))) hints.push('Acoplado actual')

    return { value: equipment.id, label: equipment.label, hint: hints.filter(Boolean).join(' · ') }
  })
})
const driverOptions = computed(() => drivers.value.map((driver) => {
  const hints = []
  if (driver.employeeNumber) hints.push(`Legajo ${driver.employeeNumber}`)
  if (driver.assignedEquipmentLabel) hints.push(`Asignado a ${driver.assignedEquipmentLabel}`)

  return {
    value: driver.id,
    label: driver.label,
    hint: hints.join(' · '),
  }
}))
const customerOptions = computed(() => customers.value.map((customer) => ({
  value: customer.id,
  label: customer.label,
  hint: [customer.code ? `Código ${customer.code}` : '', customer.taxId ?? ''].filter(Boolean).join(' · '),
})))
const localityOptions = computed(() => localities.value.map((locality) => ({ value: locality.id, label: locality.label })))
const localityName = (localityId) => localities.value.find((locality) => String(locality.id) === String(localityId))?.name ?? ''
</script>

<template>
  <div>
    <PageHeading eyebrow="Viajes aprobados" title="Iniciar viaje" description="El viaje ya está aprobado. Completá equipo, salida real, odómetro, chofer y recorrido; al guardar queda En curso para cargar sus tramos, gastos y combustible." :back="{ href: links.index, label: 'Volver a viajes operativos' }" />

    <form method="post" :action="links.store" class="space-y-5">
      <input type="hidden" :name="csrf.name" :value="csrf.hash" />

      <PanelCard title="Datos del viaje" allow-overflow>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          <label class="block xl:col-span-2">
            <span class="mb-1.5 block text-sm font-semibold text-ink">Tractor o camión</span>
            <AutocompleteSelect v-model="tractorId" name="tractor_id" :options="tractorOptions" placeholder="Elegí el tractor o camión" required />
            <span class="mt-1 block text-xs text-ink-muted">Al elegirlo se completan empresa, dominio, chofer, porcentaje, último km y el semirremolque que tiene enganchado.</span>
          </label>
          <label class="block">
            <span class="mb-1.5 block text-sm font-semibold text-ink">Semirremolques del viaje</span>
            <AutocompleteSelect v-model="trailerIds" name="acoplado_id[]" :options="trailerOptions" placeholder="Sin semirremolque" multiple />
            <span v-if="trailerHint" class="mt-1 block text-xs text-ink-muted">{{ trailerHint }}</span>
            <span v-else class="mt-1 block text-xs text-ink-muted">Podés cargar más de uno si el camión va en bitrén.</span>
          </label>
          <label class="block">
            <span class="mb-1.5 block text-sm font-semibold text-ink">Sucursal de origen</span>
            <select v-model="branchId" name="sucursal_id" required :class="fieldClass">
              <option value="">Elegí una sucursal</option>
              <option v-for="branch in branches" :key="branch.id" :value="String(branch.id)">{{ branch.code }} · {{ branch.name }}</option>
            </select>
          </label>
        </div>

        <div class="mt-4 grid gap-3 sm:grid-cols-3">
          <div class="rounded-xl border border-border-subtle bg-surface p-3">
            <span class="block text-xs font-semibold uppercase tracking-wide text-ink-muted">Empresa</span>
            <p class="mt-1 text-sm font-bold text-ink">{{ companyLabel }}</p>
            <p v-if="company.taxId" class="mt-0.5 text-xs text-ink-muted">{{ company.taxId }}</p>
          </div>
          <div class="rounded-xl border border-border-subtle bg-surface p-3">
            <span class="block text-xs font-semibold uppercase tracking-wide text-ink-muted">Dominio</span>
            <p class="mt-1 text-sm font-bold text-ink">{{ domain || 'Elegí el camión' }}</p>
            <p class="mt-0.5 text-xs text-ink-muted">{{ selectedTractor?.type ?? 'Patente del tractor' }}</p>
          </div>
          <div class="rounded-xl border border-border-subtle bg-surface p-3">
            <span class="block text-xs font-semibold uppercase tracking-wide text-ink-muted">Nro. de viaje</span>
            <p class="mt-1 text-sm font-bold text-ink">{{ nextNumber === null ? 'Automático' : `#${nextNumber}` }}</p>
            <p class="mt-0.5 text-xs text-ink-muted">Se confirma al guardar.</p>
          </div>
        </div>

        <div class="mt-4 grid gap-4 md:grid-cols-3">
          <label class="block">
            <span class="mb-1.5 block text-sm font-semibold text-ink">Fecha y hora real de salida</span>
            <input v-model="departure" type="datetime-local" name="fecha_salida_real" required :class="fieldClass" />
          </label>
          <label class="block">
            <span class="mb-1.5 block text-sm font-semibold text-ink">Fecha estimada de regreso (opcional)</span>
            <input v-model="returnAt" type="datetime-local" name="fecha_regreso_estimado" :class="fieldClass" />
            <span class="mt-1 block text-xs text-ink-muted">Si todavía no la sabés, dejala vacía. El equipo y el chofer quedan ocupados hasta cargar el regreso real.</span>
          </label>
          <label class="block">
            <span class="mb-1.5 block text-sm font-semibold text-ink">Odómetro real de salida (km)</span>
            <input v-model="kmSalida" type="number" min="0" step="1" name="km_salida" placeholder="Lectura actual del tablero" required :class="fieldClass" />
            <span class="mt-1 block text-xs text-ink-muted">{{ kmSalidaHint }}</span>
          </label>
        </div>

        <div class="mt-4 grid gap-4 md:grid-cols-2">
          <label class="block">
            <span class="mb-1.5 block text-sm font-semibold text-ink">Choferes</span>
            <AutocompleteSelect v-model="selectedDrivers" name="choferes[]" :options="driverOptions" placeholder="Elegí uno o más choferes" multiple required />
            <span v-if="assignedDriverNames.length" class="mt-1 block text-xs font-semibold text-primary">Chofer asignado al equipo: {{ assignedDriverNames.join(', ') }}.</span>
            <span v-else class="mt-1 block text-xs text-ink-muted">Buscá por nombre o legajo. Los seleccionados quedan como etiquetas.</span>
          </label>
          <div class="rounded-xl border border-border-subtle bg-surface p-3">
            <p class="text-sm font-semibold text-ink">% de liquidación del chofer</p>
            <p class="mt-1 text-xs text-ink-muted">Se guarda el porcentaje vigente al iniciar y después podés ajustarlo.</p>
            <p v-if="selectedDriverRows.length === 0" class="mt-3 text-sm text-ink-muted">Elegí un chofer para ver su porcentaje.</p>
            <div v-for="driver in selectedDriverRows" :key="driver.id" class="mt-3 flex flex-wrap items-center justify-between gap-2">
              <span class="text-sm font-semibold text-ink">{{ driver.label }}</span>
              <label class="flex items-center gap-1.5">
                <input v-model="percentages[driver.id]" type="number" min="0" max="100" step="0.01" :name="`choferes_porcentajes[${driver.id}]`" :class="[fieldClass, 'w-24 text-right']" />
                <span class="text-sm font-semibold text-ink-muted">%</span>
              </label>
            </div>
          </div>
        </div>

        <label class="mt-4 block">
          <span class="mb-1.5 block text-sm font-semibold text-ink">Observaciones</span>
          <textarea name="observaciones" rows="3" maxlength="1000" placeholder="Indicaciones de carga, contactos o condiciones del viaje" :value="old.observaciones ?? ''" :class="fieldClass"></textarea>
        </label>
      </PanelCard>

      <PanelCard title="Tramos de flete" :count="legs.length" allow-overflow>
        <p class="mb-4 text-sm text-ink-muted">Completá origen, destino y un solo cliente por tramo. Si tenés el código de ruta del sistema anterior, podés guardarlo también. El flete de facturación y el de liquidación se cargan por separado.</p>
        <div class="space-y-4">
          <article v-for="(leg, index) in legs" :key="index" class="rounded-xl border border-border-subtle bg-surface p-4">
            <div class="mb-3 flex items-center justify-between gap-3">
              <h3 class="text-sm font-bold text-ink">Tramo {{ index + 1 }}</h3>
              <button v-if="legs.length > 1" type="button" :class="secondaryButton" @click="removeLeg(index)">
                <TrashIcon class="mr-1.5 size-4" aria-hidden="true" />Quitar
              </button>
            </div>
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
              <label class="block"><span class="mb-1.5 block text-sm font-semibold text-ink">Código de ruta anterior (opcional)</span><input v-model="leg.codigo_origen_destino" type="number" min="1" step="1" :name="`tramos[${index}][codigo_origen_destino]`" placeholder="Ej.: 947" :class="fieldClass" /></label>
              <label class="block"><span class="mb-1.5 block text-sm font-semibold text-ink">Localidad de origen</span><AutocompleteSelect v-model="leg.localidad_origen_id" :name="`tramos[${index}][localidad_origen_id]`" :options="localityOptions" :search-url="links.localities" placeholder="Buscá la localidad" required /></label>
              <label class="block"><span class="mb-1.5 block text-sm font-semibold text-ink">Localidad de destino</span><AutocompleteSelect v-model="leg.localidad_destino_id" :name="`tramos[${index}][localidad_destino_id]`" :options="localityOptions" :search-url="links.localities" placeholder="Buscá la localidad" required /></label>
              <label class="block"><span class="mb-1.5 block text-sm font-semibold text-ink">Cliente del tramo</span><AutocompleteSelect v-model="leg.cliente_id" :name="`tramos[${index}][cliente_id]`" :options="customerOptions" placeholder="Elegí el cliente" required /></label>
              <label class="block"><span class="mb-1.5 block text-sm font-semibold text-ink">Km del tramo (opcional)</span><input v-model="leg.km" type="number" min="0" step="0.01" :name="`tramos[${index}][km]`" :class="fieldClass" /></label>
              <label class="block"><span class="mb-1.5 block text-sm font-semibold text-ink">Toneladas (opcional)</span><input v-model="leg.toneladas" type="number" min="0" step="0.01" :name="`tramos[${index}][toneladas]`" :class="fieldClass" /></label>
              <label class="block"><span class="mb-1.5 block text-sm font-semibold text-ink">Flete de facturación</span><input v-model="leg.flete_facturacion" type="number" min="0" step="0.01" :name="`tramos[${index}][flete_facturacion]`" required :class="fieldClass" /></label>
              <label class="block"><span class="mb-1.5 block text-sm font-semibold text-ink">Flete de liquidación</span><input v-model="leg.flete_liquidacion" type="number" min="0" step="0.01" :name="`tramos[${index}][flete_liquidacion]`" required :class="fieldClass" /></label>
              <label class="block"><span class="mb-1.5 block text-sm font-semibold text-ink">Moneda</span><select v-model="leg.moneda" :name="`tramos[${index}][moneda]`" :class="fieldClass"><option v-for="currency in currencies" :key="currency.code" :value="currency.code">{{ currency.code }} · {{ currency.label }}</option></select></label>
              <label class="block"><span class="mb-1.5 block text-sm font-semibold text-ink">Cotización a ARS</span><input v-model="leg.cotizacion" type="number" min="0.000001" step="0.000001" :name="`tramos[${index}][cotizacion]`" required :class="fieldClass" /></label>
              <label class="block md:col-span-2 xl:col-span-2"><span class="mb-1.5 block text-sm font-semibold text-ink">Observaciones del tramo</span><input v-model="leg.observaciones" type="text" maxlength="500" :name="`tramos[${index}][observaciones]`" :class="fieldClass" /></label>
            </div>
          </article>
        </div>
        <button type="button" :class="[secondaryButton, 'mt-4']" @click="addLeg"><PlusIcon class="mr-1.5 size-4" aria-hidden="true" />Agregar tramo</button>
      </PanelCard>

      <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
        <a :href="links.index" :class="secondaryButton">Cancelar</a>
        <button type="submit" :class="primaryButton">Iniciar viaje</button>
      </div>
    </form>
  </div>
</template>
