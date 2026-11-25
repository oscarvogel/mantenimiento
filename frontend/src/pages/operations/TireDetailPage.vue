<script setup>
import { computed, ref } from 'vue'
import { PencilSquareIcon } from '@heroicons/vue/24/outline'
import AutocompleteSelect from './components/AutocompleteSelect.vue'
import CsrfInput from './components/CsrfInput.vue'
import FormField from './components/FormField.vue'
import PageHeading from './components/PageHeading.vue'
import { fieldClass, formatKilometers, primaryButton, secondaryButton } from './helpers.js'
import { formatDateTimeDmy, nowLocalMinutes, tireStatusTone } from './tireStatus.js'

const props = defineProps({ data: { type: Object, required: true } })

const location = computed(() => props.data.location ?? null)
const mounting = computed(() => props.data.mounting ?? { canMount: false, canRemove: false, equipment: [], sides: [], slots: [], maxAxle: 10 })
const old = computed(() => props.data.oldInput ?? {})
const equipmentOptions = computed(() => (mounting.value.equipment ?? []).map((item) => ({ value: item.id, label: item.label })))
const axles = computed(() => Array.from({ length: mounting.value.maxAxle ?? 10 }, (_, index) => index + 1))
const mountForm = ref({ equipment: old.value.equipo_id ?? '' })

const tire = computed(() => props.data.neumatic)
const options = computed(() => props.data.options ?? { brands: [], models: [], coverTypes: [] })
const nextStatuses = computed(() => props.data.nextStatuses ?? [])
const log = computed(() => props.data.log ?? [])
const performance = computed(() => props.data.performance ?? { mountings: [] })
const now = nowLocalMinutes()

const editing = ref(false)
const form = ref({
  brand: tire.value.brandId ? String(tire.value.brandId) : '',
  model: tire.value.modelId ? String(tire.value.modelId) : '',
})

// Si la ficha ya usa una marca o un modelo que hoy está inactivo, se sigue
// ofreciendo para no perderlo al guardar otro dato.
const brandOptions = computed(() => {
  const list = [...options.value.brands]
  if (tire.value.brandId && !list.some((brand) => brand.id === tire.value.brandId)) {
    list.unshift({ id: tire.value.brandId, name: `${tire.value.brandName} (inactiva)` })
  }
  return list
})
const modelOptions = computed(() => {
  const list = options.value.models.filter((model) => String(model.brandId) === form.value.brand)
  if (tire.value.modelId && String(tire.value.brandId) === form.value.brand && !list.some((model) => model.id === tire.value.modelId)) {
    list.unshift({ id: tire.value.modelId, name: `${tire.value.modelName} (inactivo)`, brandId: tire.value.brandId })
  }
  return list
})
const coverOptions = computed(() => {
  const list = [...options.value.coverTypes]
  if (tire.value.coverTypeId && !list.some((cover) => cover.id === tire.value.coverTypeId)) {
    list.unshift({ id: tire.value.coverTypeId, name: `${tire.value.coverTypeName} (inactivo)` })
  }
  return list
})

const onBrandChange = () => {
  if (!modelOptions.value.some((model) => String(model.id) === form.value.model)) form.value.model = ''
}

const facts = computed(() => [
  ['Marca', tire.value.brandName],
  ['Modelo', tire.value.modelName],
  ['Tipo de cubierta', tire.value.coverTypeName],
  ['Medida', tire.value.size],
  ['Año de fabricación', tire.value.year],
  ['Sucursal', tire.value.branchName],
])
</script>

<template>
  <div class="space-y-6">
    <PageHeading
      eyebrow="Neumáticos · Ficha"
      :title="tire.number"
      :description="`${tire.branchName}${tire.createdAt ? ` · alta ${formatDateTimeDmy(tire.createdAt.slice(0, 10))}` : ''}`"
      :back="{ href: data.urls.index, label: 'Volver al padrón' }"
    >
      <template #actions>
        <span :class="tireStatusTone(tire.status)" class="self-start rounded-full px-3 py-1.5 text-sm font-semibold sm:self-auto">{{ tire.statusLabel }}</span>
      </template>
    </PageHeading>

    <div class="grid gap-6 lg:grid-cols-2">
      <section class="rounded-xl border border-border bg-surface-raised p-4 sm:p-5" aria-labelledby="tire-detail-data">
        <div class="flex items-center justify-between gap-3">
          <h2 id="tire-detail-data" class="text-base font-bold text-ink">Datos</h2>
          <button v-if="data.canOperate" type="button" :class="secondaryButton" @click="editing = true">
            <PencilSquareIcon class="mr-1.5 size-4" aria-hidden="true" />
            Editar datos
          </button>
        </div>
        <dl class="mt-4 grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
          <div v-for="[label, value] in facts" :key="label">
            <dt class="text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ label }}</dt>
            <dd class="mt-0.5 text-sm text-ink">{{ value || 'Sin cargar' }}</dd>
          </div>
        </dl>
      </section>

      <section class="rounded-xl border border-border bg-surface-raised p-4 sm:p-5" aria-labelledby="tire-detail-status">
        <h2 id="tire-detail-status" class="text-base font-bold text-ink">Estado</h2>
        <p class="mt-2 text-sm text-ink-muted">
          Actual: <span :class="tireStatusTone(tire.status)" class="rounded-full px-2.5 py-1 text-xs font-semibold">{{ tire.statusLabel }}</span>
        </p>

        <p v-if="tire.isFinal" class="mt-4 text-sm text-ink-muted">Es un estado final: el neumático no admite más cambios de estado. Su historial se conserva.</p>

        <form v-else-if="data.canOperate && nextStatuses.length" method="post" :action="data.urls.status" class="mt-4 grid gap-4">
          <CsrfInput :csrf="data.csrf" />
          <FormField label="Nuevo estado" for-id="tire-status-target">
            <select id="tire-status-target" name="estado" required :class="fieldClass">
              <option value="" disabled selected>Elegí el nuevo estado</option>
              <option v-for="status in nextStatuses" :key="status.value" :value="status.value">{{ status.label }}</option>
            </select>
          </FormField>
          <FormField label="Motivo" for-id="tire-status-reason" hint="Queda en el historial del neumático.">
            <input id="tire-status-reason" name="motivo" required maxlength="300" autocomplete="off" placeholder="Ej.: pinchadura en ruta" :class="fieldClass" />
          </FormField>
          <FormField label="Fecha y hora" for-id="tire-status-date">
            <input id="tire-status-date" name="fecha" type="datetime-local" required :max="now" :value="now" :class="fieldClass" />
          </FormField>
          <div>
            <button type="submit" :class="primaryButton">Cambiar estado</button>
          </div>
        </form>
      </section>
    </div>

    <section id="ubicacion" class="rounded-xl border border-border bg-surface-raised p-4 sm:p-5" aria-labelledby="tire-detail-location">
      <h2 id="tire-detail-location" class="text-base font-bold text-ink">Ubicación</h2>

      <template v-if="location">
        <p class="mt-2 text-sm text-ink">
          Colocado en <strong>{{ location.equipmentLabel }}</strong>, {{ location.position }}.
        </p>
        <p class="text-sm text-ink-muted">Desde el {{ formatDateTimeDmy(location.mountedAt) }}, con el equipo en {{ formatKilometers(location.mountedKm) }}.</p>

        <form v-if="mounting.canRemove" id="extraer" method="post" :action="data.urls.remove" class="mt-4 grid gap-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end">
          <CsrfInput :csrf="data.csrf" />
          <FormField label="Km del equipo al extraer" for-id="tire-remove-km" hint="Si es menor al de colocación, el rendimiento queda sin datos suficientes.">
            <input id="tire-remove-km" name="km" type="number" inputmode="numeric" min="0" step="1" required :value="old.km" :class="fieldClass" />
          </FormField>
          <FormField label="Fecha y hora" for-id="tire-remove-date">
            <input id="tire-remove-date" name="fecha" type="datetime-local" required :max="now" :value="old.fecha ?? now" :class="fieldClass" />
          </FormField>
          <div>
            <button type="submit" :class="primaryButton">Extraer</button>
          </div>
        </form>
      </template>

      <template v-else>
        <p class="mt-2 text-sm text-ink-muted">No está colocado en ningún equipo.</p>
        <p v-if="mounting.blockedReason" class="mt-2 text-sm text-ink-muted">{{ mounting.blockedReason }}</p>
        <p v-else-if="mounting.canMount && equipmentOptions.length === 0" class="mt-2 text-sm text-ink-muted">No hay equipos activos en tus sucursales para colocarlo.</p>

        <form v-else-if="mounting.canMount" id="colocar" method="post" :action="data.urls.mount" class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <CsrfInput :csrf="data.csrf" />
          <div class="sm:col-span-2">
            <span class="mb-1.5 block text-sm font-semibold text-ink">Equipo</span>
            <AutocompleteSelect v-model="mountForm.equipment" name="equipo_id" :options="equipmentOptions" required placeholder="Buscá por patente o código" />
          </div>
          <FormField label="Eje" for-id="tire-mount-axle">
            <select id="tire-mount-axle" name="eje" required :class="fieldClass">
              <option v-for="axle in axles" :key="axle" :value="axle" :selected="String(axle) === (old.eje ?? '1')">Eje {{ axle }}</option>
            </select>
          </FormField>
          <FormField label="Lado" for-id="tire-mount-side">
            <select id="tire-mount-side" name="lado" required :class="fieldClass">
              <option v-for="side in mounting.sides" :key="side.value" :value="side.value" :selected="side.value === old.lado">{{ side.label }}</option>
            </select>
          </FormField>
          <FormField label="Rueda" for-id="tire-mount-slot" hint="Simple: la única rueda de ese lado del eje.">
            <select id="tire-mount-slot" name="lugar" required :class="fieldClass">
              <option v-for="slot in mounting.slots" :key="slot.value" :value="slot.value" :selected="slot.value === old.lugar">{{ slot.label }}</option>
            </select>
          </FormField>
          <FormField label="Botón (opcional)" for-id="tire-mount-button">
            <input id="tire-mount-button" name="boton" maxlength="10" autocomplete="off" :value="old.boton" :class="fieldClass" />
          </FormField>
          <FormField label="Km del equipo" for-id="tire-mount-km">
            <input id="tire-mount-km" name="km" type="number" inputmode="numeric" min="0" step="1" required :value="old.km" :class="fieldClass" />
          </FormField>
          <FormField label="Fecha y hora" for-id="tire-mount-date">
            <input id="tire-mount-date" name="fecha" type="datetime-local" required :max="now" :value="old.fecha ?? now" :class="fieldClass" />
          </FormField>
          <p class="text-sm text-ink-muted sm:col-span-2 lg:col-span-4">
            Se registra una lectura de km del equipo. Si el neumático está en un depósito, se le da salida; si es de otra sucursal, pasa a la del equipo.
          </p>
          <div class="sm:col-span-2 lg:col-span-4">
            <button type="submit" :class="primaryButton">Colocar</button>
          </div>
        </form>
      </template>
    </section>

    <section class="rounded-xl border border-border bg-surface-raised p-4 sm:p-5" aria-labelledby="tire-detail-performance">
      <h2 id="tire-detail-performance" class="text-base font-bold text-ink">Rendimiento</h2>
      <p class="mt-2 text-sm text-ink">
        Kilómetros recorridos: <strong>{{ performance.total_label }}</strong>
      </p>
      <p v-if="performance.mountings.length === 0" class="mt-1 text-sm text-ink-muted">Todavía no tiene colocaciones en equipos registradas.</p>
      <ul v-else class="mt-3 divide-y divide-border-subtle">
        <li v-for="(item, index) in performance.mountings" :key="index" class="flex flex-col gap-1 py-2 text-sm sm:flex-row sm:justify-between">
          <span class="min-w-0 text-ink">
            <span class="block font-semibold">{{ item.equipment_label }} · {{ item.position_label }}</span>
            <span class="text-ink-muted">{{ formatDateTimeDmy(item.mounted_at) }} → {{ item.removed_at ? formatDateTimeDmy(item.removed_at) : 'colocado' }}</span>
          </span>
          <span class="font-semibold text-ink">{{ item.label }}</span>
        </li>
      </ul>
    </section>

    <section class="rounded-xl border border-border bg-surface-raised p-4 sm:p-5" aria-labelledby="tire-detail-log">
      <h2 id="tire-detail-log" class="text-base font-bold text-ink">Historial de estados</h2>
      <ol class="mt-4 space-y-4 border-l-2 border-border-subtle pl-4">
        <li v-for="(entry, index) in log" :key="index" class="relative">
          <span class="absolute -left-[1.4rem] top-1.5 size-2.5 rounded-full bg-primary" aria-hidden="true"></span>
          <p class="text-sm font-semibold text-ink">
            <template v-if="entry.fromLabel">{{ entry.fromLabel }} → </template>{{ entry.toLabel }}
          </p>
          <p class="text-sm text-ink-muted">{{ entry.reason }}</p>
          <p class="mt-0.5 text-xs text-ink-subtle">
            {{ formatDateTimeDmy(entry.at) }}<template v-if="entry.userName"> · {{ entry.userName }}</template>
          </p>
        </li>
      </ol>
    </section>

    <Teleport to="body">
      <div v-if="editing" class="fixed inset-0 z-[80] flex items-start justify-center overflow-y-auto bg-black/45 p-4 sm:items-center" role="presentation" @mousedown.self="editing = false" @keydown.esc="editing = false">
        <section role="dialog" aria-modal="true" aria-labelledby="tire-edit-title" class="w-full max-w-2xl rounded-xl bg-surface-raised shadow-2xl">
          <header class="border-b border-border-subtle px-6 py-4">
            <h2 id="tire-edit-title" class="text-lg font-bold text-ink">Editar datos de {{ tire.number }}</h2>
            <p class="mt-1 text-sm text-ink-muted">El número y la sucursal no se cambian desde acá.</p>
          </header>
          <form method="post" :action="data.urls.update" class="grid gap-4 p-6 sm:grid-cols-2">
            <CsrfInput :csrf="data.csrf" />
            <FormField label="Marca" for-id="tire-edit-brand">
              <select id="tire-edit-brand" v-model="form.brand" name="marca_id" :class="fieldClass" @change="onBrandChange">
                <option value="">Sin marca</option>
                <option v-for="brand in brandOptions" :key="brand.id" :value="String(brand.id)">{{ brand.name }}</option>
              </select>
            </FormField>
            <FormField label="Modelo" for-id="tire-edit-model">
              <select id="tire-edit-model" v-model="form.model" name="modelo_id" :disabled="!form.brand" :class="fieldClass">
                <option value="">Sin modelo</option>
                <option v-for="model in modelOptions" :key="model.id" :value="String(model.id)">{{ model.name }}</option>
              </select>
            </FormField>
            <FormField label="Tipo de cubierta" for-id="tire-edit-cover">
              <select id="tire-edit-cover" name="tipo_cubierta_id" :class="fieldClass">
                <option value="">Sin tipo</option>
                <option v-for="cover in coverOptions" :key="cover.id" :value="cover.id" :selected="cover.id === tire.coverTypeId">{{ cover.name }}</option>
              </select>
            </FormField>
            <FormField label="Medida" for-id="tire-edit-size">
              <input id="tire-edit-size" name="medida" maxlength="30" autocomplete="off" :value="tire.size" :class="fieldClass" />
            </FormField>
            <FormField label="Año de fabricación" for-id="tire-edit-year">
              <input id="tire-edit-year" name="anio_fabricacion" type="number" inputmode="numeric" min="1900" :max="new Date().getFullYear()" :value="tire.year ?? ''" :class="fieldClass" />
            </FormField>
            <div class="flex justify-end gap-2 border-t border-border-subtle pt-4 sm:col-span-2">
              <button type="button" :class="secondaryButton" @click="editing = false">Cancelar</button>
              <button type="submit" :class="primaryButton">Guardar cambios</button>
            </div>
          </form>
        </section>
      </div>
    </Teleport>
  </div>
</template>
