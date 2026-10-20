<script setup>
import { computed, ref } from 'vue'
import { PlusIcon, TrashIcon } from '@heroicons/vue/24/outline'
import PageHeading from './components/PageHeading.vue'
import AutocompleteSelect from './components/AutocompleteSelect.vue'
import PanelCard from './components/PanelCard.vue'
import { fieldClass, primaryButton, secondaryButton } from './helpers.js'

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
const trailerId = ref(old.value.acoplado_id ?? '')
const selectedDrivers = ref(Array.isArray(old.value.choferes) ? old.value.choferes.map(String) : [])
const addStop = () => stops.value.push(newStop('HITO'))
const removeStop = (index) => {
  if (stops.value.length > 2) stops.value.splice(index, 1)
}
const addressesFor = (customerId) => addresses.value.filter((address) => !customerId || Number(address.customerId) === Number(customerId))
const tractorOptions = computed(() => tractors.value.map((equipment) => ({ value: equipment.id, label: equipment.label, hint: equipment.type })))
const trailerOptions = computed(() => trailers.value.map((equipment) => ({ value: equipment.id, label: equipment.label, hint: equipment.type })))
const driverOptions = computed(() => drivers.value.map((driver) => ({
  value: driver.id,
  label: driver.label,
  hint: driver.employeeNumber ? `Legajo ${driver.employeeNumber}` : '',
})))
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
    <PageHeading eyebrow="Viajes" title="Nuevo viaje" description="Planificá el recorrido, asigná el equipo y los choferes. El viaje queda en estado Planificado hasta que registres la salida real." :back="{ href: links.index, label: 'Volver a viajes operativos' }" />

    <form method="post" :action="links.store" class="space-y-5">
      <input type="hidden" :name="csrf.name" :value="csrf.hash" />

      <PanelCard title="Datos del viaje">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          <label class="block">
            <span class="mb-1.5 block text-sm font-semibold text-ink">Sucursal de origen</span>
            <select name="sucursal_id" required :class="fieldClass">
              <option value="">Elegí una sucursal</option>
              <option v-for="branch in branches" :key="branch.id" :value="branch.id" :selected="String(branch.id) === String(old.sucursal_id ?? '')">{{ branch.code }} · {{ branch.name }}</option>
            </select>
          </label>
          <label class="block">
            <span class="mb-1.5 block text-sm font-semibold text-ink">Salida planificada</span>
            <input type="datetime-local" name="fecha_salida_planificada" required :value="old.fecha_salida_planificada ?? ''" :class="fieldClass" />
          </label>
          <label class="block">
            <span class="mb-1.5 block text-sm font-semibold text-ink">Regreso planificado</span>
            <input type="datetime-local" name="fecha_regreso_planificada" required :value="old.fecha_regreso_planificada ?? ''" :class="fieldClass" />
          </label>
        </div>
        <label class="mt-4 block">
          <span class="mb-1.5 block text-sm font-semibold text-ink">Observaciones</span>
          <textarea name="observaciones" rows="3" maxlength="1000" placeholder="Indicaciones de carga, contactos o condiciones del viaje" :value="old.observaciones ?? ''" :class="fieldClass"></textarea>
        </label>
      </PanelCard>

      <PanelCard title="Equipo y choferes" allow-overflow>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          <label class="block">
            <span class="mb-1.5 block text-sm font-semibold text-ink">Tractor o camión</span>
            <AutocompleteSelect v-model="tractorId" name="tractor_id" :options="tractorOptions" placeholder="Elegí el tractor o camión" required />
          </label>
          <label class="block">
            <span class="mb-1.5 block text-sm font-semibold text-ink">Acoplado o semirremolque</span>
            <AutocompleteSelect v-model="trailerId" name="acoplado_id" :options="trailerOptions" placeholder="Sin acoplado" clearable />
          </label>
          <label class="block">
            <span class="mb-1.5 block text-sm font-semibold text-ink">Choferes</span>
            <AutocompleteSelect v-model="selectedDrivers" name="choferes[]" :options="driverOptions" placeholder="Elegí uno o más choferes" multiple required />
            <span class="mt-1 block text-xs text-ink-muted">Buscá por nombre o legajo. Los seleccionados quedan como etiquetas.</span>
          </label>
        </div>
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
