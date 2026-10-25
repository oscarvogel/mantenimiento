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
const stopTypes = computed(() => props.data.stopTypes ?? [
  { value: 'CARGA', label: 'Carga' },
  { value: 'DESCARGA', label: 'Descarga' },
  { value: 'HITO', label: 'Hito operativo' },
])
const branches = computed(() => options.value.branches ?? [])
const tractors = computed(() => options.value.tractors ?? [])
const trailers = computed(() => options.value.trailers ?? [])
const drivers = computed(() => options.value.drivers ?? [])
const customers = computed(() => options.value.customers ?? [])
const addresses = computed(() => options.value.addresses ?? [])
const loadUnits = computed(() => options.value.loadUnits ?? [])
const company = computed(() => options.value.company ?? {})
const nextNumber = computed(() => options.value.nextNumber ?? null)

const old = computed(() => props.data.old ?? {})
const newStop = (type = 'CARGA') => ({
  tipo: type,
  ubicacion: '',
  cliente_id: '',
  domicilio_id: '',
  fecha_estimada: '',
  descripcion_carga: '',
  cantidad: '',
  unidad_carga_id: '',
  observaciones: '',
})
const initialStops = () => {
  const rows = Array.isArray(old.value.paradas)
    ? old.value.paradas.filter((row) => row && Object.values(row).some((value) => String(value ?? '').trim() !== ''))
    : []
  if (rows.length < 2) return [newStop('CARGA'), newStop('DESCARGA')]

  return rows.map((row) => ({ ...newStop(row.tipo ?? 'CARGA'), ...row }))
}
const stops = ref(initialStops())
const tractorId = ref(old.value.tractor_id ?? '')
const trailerIds = ref(old.value.acoplado_id
  ? (Array.isArray(old.value.acoplado_id) ? old.value.acoplado_id.map(String) : [String(old.value.acoplado_id)])
  : [])
const branchId = ref(old.value.sucursal_id === undefined || old.value.sucursal_id === null ? '' : String(old.value.sucursal_id))
const departure = ref(old.value.fecha_salida_planificada ?? nowLocal())
const returnAt = ref(old.value.fecha_regreso_planificada ?? '')
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
    ? 'Sin lectura previa. Podés completarlo ahora o al iniciar el viaje.'
    : `Última lectura del camión: ${Number(lastKm).toLocaleString('es-AR')} km.`
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

const addStop = () => stops.value.push(newStop('HITO'))
const removeStop = (index) => {
  if (stops.value.length > 2) stops.value.splice(index, 1)
}
const addressesFor = (customerId) => addresses.value.filter((address) => !customerId || Number(address.customerId) === Number(customerId))
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
  hint: customer.taxId ?? '',
})))
const loadUnitOptions = computed(() => loadUnits.value.map((unit) => ({ value: unit.id, label: unit.label })))
const addressOptionsFor = (customerId) => addressesFor(customerId).map((address) => ({ value: address.id, label: address.label }))
const updateCustomer = (stop, value) => {
  stop.cliente_id = value ?? ''
  if (stop.domicilio_id && !addressesFor(stop.cliente_id).some((address) => String(address.id) === String(stop.domicilio_id))) {
    stop.domicilio_id = ''
  }
}
</script>

<template>
  <div>
    <PageHeading eyebrow="Viajes" title="Nuevo viaje" description="Elegí el camión y completamos el dominio, el chofer y el último km. Después de guardar, cargá los tramos con el flete de facturación y el de liquidación. El viaje queda Planificado hasta que registres la salida real." :back="{ href: links.index, label: 'Volver a viajes operativos' }" />

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
            <span class="mb-1.5 block text-sm font-semibold text-ink">Fecha de salida</span>
            <input v-model="departure" type="datetime-local" name="fecha_salida_planificada" required :class="fieldClass" />
          </label>
          <label class="block">
            <span class="mb-1.5 block text-sm font-semibold text-ink">Fecha de regreso</span>
            <input v-model="returnAt" type="datetime-local" name="fecha_regreso_planificada" required :class="fieldClass" />
          </label>
          <label class="block">
            <span class="mb-1.5 block text-sm font-semibold text-ink">Km de salida</span>
            <input v-model="kmSalida" type="number" min="0" step="1" name="km_salida" placeholder="0" :class="fieldClass" />
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
            <p class="mt-1 text-xs text-ink-muted">Se guarda el porcentaje vigente al planificar y después podés ajustarlo.</p>
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

      <PanelCard title="Recorrido y paradas" :count="stops.length" allow-overflow>
        <div class="space-y-4">
          <article v-for="(stop, index) in stops" :key="index" class="rounded-xl border border-border-subtle bg-surface p-4">
            <div class="mb-3 flex items-center justify-between gap-3">
              <h3 class="text-sm font-bold text-ink">Parada {{ index + 1 }}</h3>
              <button v-if="stops.length > 2" type="button" :class="secondaryButton" @click="removeStop(index)">
                <TrashIcon class="mr-1.5 size-4" aria-hidden="true" />Quitar
              </button>
            </div>
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
              <label class="block">
                <span class="mb-1.5 block text-sm font-semibold text-ink">Tipo</span>
                <select :name="`paradas[${index}][tipo]`" v-model="stop.tipo" :class="fieldClass">
                  <option v-for="type in stopTypes" :key="type.value" :value="type.value">{{ type.label }}</option>
                </select>
              </label>
              <label class="block md:col-span-2">
                <span class="mb-1.5 block text-sm font-semibold text-ink">Ubicación</span>
                <input type="text" :name="`paradas[${index}][ubicacion]`" v-model="stop.ubicacion" required maxlength="200" placeholder="Ciudad, planta, depósito o punto de encuentro" :class="fieldClass" />
              </label>
              <label class="block">
                <span class="mb-1.5 block text-sm font-semibold text-ink">Cliente</span>
                <AutocompleteSelect
                  :model-value="stop.cliente_id"
                  :name="`paradas[${index}][cliente_id]`"
                  :options="customerOptions"
                  placeholder="Sin cliente"
                  clearable
                  @update:model-value="(value) => updateCustomer(stop, value)"
                />
              </label>
              <label class="block">
                <span class="mb-1.5 block text-sm font-semibold text-ink">Domicilio guardado</span>
                <AutocompleteSelect
                  v-model="stop.domicilio_id"
                  :name="`paradas[${index}][domicilio_id]`"
                  :options="addressOptionsFor(stop.cliente_id)"
                  placeholder="Sin domicilio"
                  clearable
                />
              </label>
              <label class="block">
                <span class="mb-1.5 block text-sm font-semibold text-ink">Fecha estimada</span>
                <input type="datetime-local" :name="`paradas[${index}][fecha_estimada]`" v-model="stop.fecha_estimada" :class="fieldClass" />
              </label>
              <label class="block md:col-span-2">
                <span class="mb-1.5 block text-sm font-semibold text-ink">Carga</span>
                <input type="text" :name="`paradas[${index}][descripcion_carga]`" v-model="stop.descripcion_carga" maxlength="300" placeholder="Descripción de la carga o del hito" :class="fieldClass" />
              </label>
              <label class="block">
                <span class="mb-1.5 block text-sm font-semibold text-ink">Cantidad</span>
                <input type="number" step="0.01" min="0" :name="`paradas[${index}][cantidad]`" v-model="stop.cantidad" :class="fieldClass" />
              </label>
              <label class="block">
                <span class="mb-1.5 block text-sm font-semibold text-ink">Unidad</span>
                <AutocompleteSelect
                  v-model="stop.unidad_carga_id"
                  :name="`paradas[${index}][unidad_carga_id]`"
                  :options="loadUnitOptions"
                  placeholder="Sin unidad"
                  clearable
                />
              </label>
              <label class="block md:col-span-2 xl:col-span-3">
                <span class="mb-1.5 block text-sm font-semibold text-ink">Observaciones de la parada</span>
                <input type="text" :name="`paradas[${index}][observaciones]`" v-model="stop.observaciones" maxlength="500" :class="fieldClass" />
              </label>
            </div>
          </article>
        </div>
        <button type="button" :class="[secondaryButton, 'mt-4']" @click="addStop"><PlusIcon class="mr-1.5 size-4" aria-hidden="true" />Agregar otra parada</button>
      </PanelCard>

      <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
        <a :href="links.index" :class="secondaryButton">Cancelar</a>
        <button type="submit" :class="primaryButton">Planificar viaje</button>
      </div>
    </form>
  </div>
</template>
