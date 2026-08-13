<script setup>
import { computed, ref } from 'vue'
import {
  ArrowDownTrayIcon,
  ChevronRightIcon,
  MagnifyingGlassIcon,
  PencilSquareIcon,
} from '@heroicons/vue/24/outline'
import CsrfInput from './components/CsrfInput.vue'
import EmptyState from './components/EmptyState.vue'
import FormField from './components/FormField.vue'
import PageHeading from './components/PageHeading.vue'
import PanelCard from './components/PanelCard.vue'
import StatusBadge from './components/StatusBadge.vue'
import { fieldClass, primaryButton, secondaryButton } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })

const templates = computed(() => props.data.templates ?? [])
const services = computed(() => props.data.services ?? [])
const items = computed(() => props.data.items ?? [])
const searchQuery = ref('')

const valueOrBlank = (value) => (value === null || value === undefined ? '' : String(value))
const normalize = (value) => valueOrBlank(value).trim().toLowerCase()

const formatNumber = (value) => {
  if (value === null || value === undefined || value === '') return null
  const number = Number(value)
  if (Number.isNaN(number)) return null
  return number.toLocaleString('es-AR', { maximumFractionDigits: 1 })
}

const formatPriority = (priority) => {
  if (!priority) return '—'
  return priority === 'CRITICA' ? 'CRÍTICA' : priority
}

const formatInterval = (item) => {
  const parts = []
  if (formatNumber(item.intervalKm)) parts.push(`${formatNumber(item.intervalKm)} km`)
  if (formatNumber(item.intervalHours)) parts.push(`${formatNumber(item.intervalHours)} h`)
  if (formatNumber(item.intervalDays)) parts.push(`${formatNumber(item.intervalDays)} d`)
  return parts.length > 0 ? `Cada ${parts.join(' / ')}` : ''
}

const formatWarning = (item) => {
  const parts = []
  if (formatNumber(item.warningKm)) parts.push(`${formatNumber(item.warningKm)} km`)
  if (formatNumber(item.warningHours)) parts.push(`${formatNumber(item.warningHours)} h`)
  if (formatNumber(item.warningDays)) parts.push(`${formatNumber(item.warningDays)} d`)
  return parts.length > 0 ? `Avisar ${parts.join(' / ')} antes` : ''
}

const taskChips = (task) => {
  const chips = []
  if (task.mandatory) chips.push('Obligatoria')
  if (!task.active) chips.push('Inactiva')
  if (task.requiresPart) chips.push('Repuesto')
  if (task.requiresControl) chips.push('Control')
  if (task.requiresPhoto) chips.push('Foto')
  return chips
}

const itemSearchText = (item) => [
  item.templateCode,
  item.templateName,
  item.equipmentType,
  item.serviceCode,
  item.serviceName,
  item.priority,
  item.notes,
  ...(item.tasks ?? []).flatMap((task) => [
    task.code,
    task.name,
    task.description,
    task.observations,
  ]),
].map(normalize).join(' ')

const filteredItems = computed(() => {
  const query = normalize(searchQuery.value)
  if (query === '') return items.value
  return items.value.filter((item) => itemSearchText(item).includes(query))
})

const servicesWithItems = computed(() => {
  const grouped = new Map()
  for (const item of items.value) {
    const list = grouped.get(item.serviceTypeId) ?? []
    list.push(item)
    grouped.set(item.serviceTypeId, list)
  }
  return services.value.map((service) => ({
    ...service,
    items: grouped.get(service.id) ?? [],
  }))
})
</script>

<template>
  <div>
    <PageHeading
      eyebrow="Mantenimiento preventivo"
      title="Biblioteca preventiva"
      description="Servicios, tareas, materiales sugeridos y plantillas disponibles para tu empresa."
      :back="{ label: 'Volver a importaciones', href: data.routes.back }"
    />

    <div class="mb-5 flex flex-wrap items-center gap-3">
      <a :href="data.routes.downloadTemplate" :class="secondaryButton">
        <ArrowDownTrayIcon class="mr-2 size-4" aria-hidden="true" />Descargar plantilla general de camiones
      </a>
      <div class="flex flex-wrap items-center gap-2 text-xs">
        <span class="inline-flex items-center gap-1.5 rounded-full bg-surface-muted px-2.5 py-1 font-semibold text-ink-muted">
          Plantillas<strong class="text-ink">{{ templates.length }}</strong>
        </span>
        <span class="inline-flex items-center gap-1.5 rounded-full bg-surface-muted px-2.5 py-1 font-semibold text-ink-muted">
          Servicios<strong class="text-ink">{{ services.length }}</strong>
        </span>
        <span class="inline-flex items-center gap-1.5 rounded-full bg-surface-muted px-2.5 py-1 font-semibold text-ink-muted">
          Ítems<strong class="text-ink">{{ filteredItems.length }}</strong>
        </span>
      </div>
    </div>

    <PanelCard title="Planes de biblioteca" :count="filteredItems.length" class="mb-5">
      <div v-if="items.length > 0" class="mb-3 max-w-md">
        <label for="library-search" class="relative block">
          <span class="sr-only">Buscar</span>
          <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-ink-subtle" aria-hidden="true" />
          <input
            id="library-search"
            v-model="searchQuery"
            name="q"
            type="search"
            placeholder="Plantilla, servicio, código o tarea"
            :class="`${fieldClass} pl-9`"
          />
        </label>
      </div>
      <EmptyState
        v-if="items.length === 0"
        title="Todavía no hay planes importados"
        description="Importá la biblioteca preventiva desde Excel para crear el primer plan de plantilla."
      />
      <EmptyState
        v-else-if="filteredItems.length === 0"
        title="No hay resultados"
        description="Probá con otra plantilla, servicio, código o tarea."
      />
      <ul v-else class="-mx-5 divide-y divide-border-subtle sm:-mx-6">
        <li v-for="item in filteredItems" :key="item.id" class="px-5 py-3 sm:px-6">
          <div class="grid grid-cols-1 gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)_auto] lg:items-center">
            <div class="min-w-0">
              <div class="flex flex-wrap items-center gap-2">
                <StatusBadge :status="item.active ? 'ACTIVO' : 'INACTIVO'" />
                <h3 class="truncate text-sm font-bold text-ink">{{ item.serviceName }}</h3>
                <span class="font-mono text-xs text-ink-muted">{{ item.serviceCode }}</span>
                <span class="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary">{{ formatPriority(item.priority) }}</span>
              </div>
              <p class="mt-0.5 truncate text-xs text-ink-muted">
                {{ item.templateName }} · {{ item.equipmentType }}<span v-if="item.notes"> · {{ item.notes }}</span>
              </p>
            </div>
            <p class="font-mono text-xs text-ink">
              <span v-if="formatInterval(item)">{{ formatInterval(item) }}</span>
              <span v-if="formatInterval(item) && formatWarning(item)"> · </span>
              <span v-if="formatWarning(item)">{{ formatWarning(item) }}</span>
              <span v-if="!formatInterval(item) && !formatWarning(item)">Sin frecuencia definida</span>
            </p>
            <div class="flex flex-wrap items-center gap-2">
              <details v-if="item.tasks && item.tasks.length > 0" class="relative">
                <summary :class="`${secondaryButton} list-none`">
                  <ChevronRightIcon class="mr-1.5 size-4" aria-hidden="true" />Tareas ({{ item.tasks.length }})
                </summary>
                <div class="absolute right-0 z-20 mt-1 w-[min(40rem,calc(100vw-2rem))] overflow-hidden rounded-xl border border-border bg-white shadow-lg">
                  <ul class="max-h-96 divide-y divide-border-subtle overflow-y-auto">
                    <li v-for="task in item.tasks" :key="task.id" class="px-4 py-2.5">
                      <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                          <p class="truncate text-sm font-semibold text-ink">{{ task.name }}</p>
                          <p class="font-mono text-xs text-ink-muted">
                            {{ task.code }}<span v-if="task.durationMinutes"> · {{ task.durationMinutes }} min</span>
                          </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-1">
                          <span
                            v-for="chip in taskChips(task)"
                            :key="`${task.id}-${chip}`"
                            class="rounded-full bg-surface-muted px-2 py-0.5 text-[11px] font-semibold text-ink-muted"
                          >{{ chip }}</span>
                        </div>
                      </div>
                      <details v-if="data.canEdit && task.updateUrl" class="mt-2 border-t border-border-subtle pt-2">
                        <summary class="cursor-pointer list-none text-xs font-semibold text-primary">Editar tarea</summary>
                        <form
                          method="post"
                          :action="task.updateUrl"
                          data-confirm
                          data-confirm-title="¿Guardar cambios en la tarea?"
                          data-confirm-text="La tarea es un catálogo compartido: el cambio se aplicará a toda la biblioteca preventiva."
                          data-confirm-button="Guardar tarea"
                          data-confirm-icon="warning"
                          class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-4"
                        >
                          <CsrfInput :csrf="data.csrf" />
                          <input type="hidden" name="tipo_servicio_id" :value="task.serviceTypeId" />
                          <FormField label="Nombre" :for-id="`task-${task.id}-name`">
                            <input :id="`task-${task.id}-name`" name="nombre" type="text" maxlength="150" :value="task.name" :class="fieldClass" />
                          </FormField>
                          <FormField label="Orden" :for-id="`task-${task.id}-order`">
                            <input :id="`task-${task.id}-order`" name="orden" type="number" min="1" :value="task.order" :class="fieldClass" />
                          </FormField>
                          <FormField label="Duración estimada (min)" :for-id="`task-${task.id}-duration`">
                            <input :id="`task-${task.id}-duration`" name="duracion_estimada_min" type="number" min="0" :value="valueOrBlank(task.durationMinutes)" :class="fieldClass" />
                          </FormField>
                          <label class="flex min-h-11 items-end gap-2 rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm font-semibold text-ink">
                            <input name="obligatoria" type="checkbox" value="1" :checked="task.mandatory" class="size-4 rounded border-border text-primary focus:ring-primary/20" />Obligatoria
                          </label>
                          <FormField label="Descripción" :for-id="`task-${task.id}-description`" class="md:col-span-2">
                            <textarea :id="`task-${task.id}-description`" name="descripcion" rows="2" :value="task.description || ''" :class="fieldClass"></textarea>
                          </FormField>
                          <FormField label="Procedimiento" :for-id="`task-${task.id}-procedure`" class="md:col-span-2 xl:col-span-4">
                            <textarea :id="`task-${task.id}-procedure`" name="procedimiento" rows="2" :value="task.procedure || ''" :class="fieldClass"></textarea>
                          </FormField>
                          <FormField label="Observaciones de la relación" :for-id="`task-${task.id}-observations`" class="md:col-span-2 xl:col-span-4">
                            <textarea :id="`task-${task.id}-observations`" name="observaciones" maxlength="500" rows="2" :value="task.observations || ''" :class="fieldClass"></textarea>
                          </FormField>
                          <div class="flex flex-wrap items-center gap-4 md:col-span-2 xl:col-span-4">
                            <label class="flex items-center gap-2 text-sm font-semibold text-ink">
                              <input name="requiere_repuesto" type="checkbox" value="1" :checked="task.requiresPart" class="size-4 rounded border-border text-primary focus:ring-primary/20" />Requiere repuesto
                            </label>
                            <label class="flex items-center gap-2 text-sm font-semibold text-ink">
                              <input name="requiere_control" type="checkbox" value="1" :checked="task.requiresControl" class="size-4 rounded border-border text-primary focus:ring-primary/20" />Requiere control
                            </label>
                            <label class="flex items-center gap-2 text-sm font-semibold text-ink">
                              <input name="requiere_foto" type="checkbox" value="1" :checked="task.requiresPhoto" class="size-4 rounded border-border text-primary focus:ring-primary/20" />Requiere foto
                            </label>
                            <label class="flex items-center gap-2 text-sm font-semibold text-ink">
                              <input name="activo" type="checkbox" value="1" :checked="task.active" class="size-4 rounded border-border text-primary focus:ring-primary/20" />Activa
                            </label>
                          </div>
                          <p class="text-xs text-ink-muted md:col-span-2 xl:col-span-4">La tarea y su orden son catálogo compartido: el cambio se aplica a toda la biblioteca.</p>
                          <button type="submit" :class="`${primaryButton} md:justify-self-start`">Guardar tarea</button>
                        </form>
                      </details>
                    </li>
                  </ul>
                </div>
              </details>
              <details v-if="item.updateUrl" class="relative">
                <summary :class="`${secondaryButton} list-none`">
                  <PencilSquareIcon class="mr-1.5 size-4" aria-hidden="true" />Editar ítem
                </summary>
                <div class="absolute right-0 z-20 mt-1 w-[min(40rem,calc(100vw-2rem))] overflow-hidden rounded-xl border border-border bg-white p-4 shadow-lg">
                  <form
                    method="post"
                    :action="item.updateUrl"
                    data-confirm
                    data-confirm-title="¿Guardar cambios en el ítem de biblioteca?"
                    data-confirm-text="El ítem es un catálogo compartido: el cambio afectará a los planes que lo usan."
                    data-confirm-button="Guardar ítem"
                    data-confirm-icon="warning"
                    class="grid gap-3 md:grid-cols-2 xl:grid-cols-4"
                  >
                    <CsrfInput :csrf="data.csrf" />
                    <FormField label="Cada km" :for-id="`library-${item.id}-interval-km`">
                      <input :id="`library-${item.id}-interval-km`" name="intervalo_km" type="number" min="1" :value="valueOrBlank(item.intervalKm)" :disabled="!data.canEdit" :class="fieldClass" />
                    </FormField>
                    <FormField label="Avisar antes (km)" :for-id="`library-${item.id}-warning-km`">
                      <input :id="`library-${item.id}-warning-km`" name="anticipacion_km" type="number" min="0" :value="valueOrBlank(item.warningKm)" :disabled="!data.canEdit" :class="fieldClass" />
                    </FormField>
                    <FormField label="Cada horas" :for-id="`library-${item.id}-interval-hours`">
                      <input :id="`library-${item.id}-interval-hours`" name="intervalo_horas" type="number" min="0.1" step="0.1" :value="valueOrBlank(item.intervalHours)" :disabled="!data.canEdit" :class="fieldClass" />
                    </FormField>
                    <FormField label="Avisar antes (horas)" :for-id="`library-${item.id}-warning-hours`">
                      <input :id="`library-${item.id}-warning-hours`" name="anticipacion_horas" type="number" min="0" step="0.1" :value="valueOrBlank(item.warningHours)" :disabled="!data.canEdit" :class="fieldClass" />
                    </FormField>
                    <FormField label="Cada días" :for-id="`library-${item.id}-interval-days`">
                      <input :id="`library-${item.id}-interval-days`" name="intervalo_dias" type="number" min="1" :value="valueOrBlank(item.intervalDays)" :disabled="!data.canEdit" :class="fieldClass" />
                    </FormField>
                    <FormField label="Avisar antes (días)" :for-id="`library-${item.id}-warning-days`">
                      <input :id="`library-${item.id}-warning-days`" name="anticipacion_dias" type="number" min="0" :value="valueOrBlank(item.warningDays)" :disabled="!data.canEdit" :class="fieldClass" />
                    </FormField>
                    <FormField label="Prioridad" :for-id="`library-${item.id}-priority`">
                      <select :id="`library-${item.id}-priority`" name="prioridad" :disabled="!data.canEdit" :class="fieldClass">
                        <option v-for="priority in ['BAJA', 'MEDIA', 'ALTA', 'CRITICA']" :key="priority" :value="priority" :selected="item.priority === priority">{{ priority === 'CRITICA' ? 'CRÍTICA' : priority }}</option>
                      </select>
                    </FormField>
                    <label class="flex min-h-11 items-end gap-2 rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm font-semibold text-ink">
                      <input name="activo" type="checkbox" value="1" :checked="item.active" :disabled="!data.canEdit" class="size-4 rounded border-border text-primary focus:ring-primary/20" />
                      Activo
                    </label>
                    <FormField label="Observaciones" :for-id="`library-${item.id}-notes`" class="md:col-span-2 xl:col-span-4">
                      <textarea :id="`library-${item.id}-notes`" name="observaciones" maxlength="1000" rows="2" :value="item.notes || ''" :disabled="!data.canEdit" :class="fieldClass"></textarea>
                    </FormField>
                    <button v-if="data.canEdit" type="submit" :class="`${primaryButton} md:justify-self-start`">Guardar</button>
                  </form>
                </div>
              </details>
            </div>
          </div>
        </li>
      </ul>
    </PanelCard>

    <PanelCard title="Plantillas de la empresa" :count="templates.length" flush class="mb-5">
      <EmptyState v-if="templates.length === 0" title="Todavía no hay plantillas" description="Importá la biblioteca preventiva desde Excel para crear la primera plantilla." />
      <div v-else class="overflow-x-auto">
        <table class="w-full min-w-[40rem] text-left text-sm">
          <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted">
            <tr>
              <th class="px-5 py-2.5 sm:px-6">Código</th>
              <th class="px-5 py-2.5 sm:px-6">Plantilla</th>
              <th class="px-5 py-2.5 sm:px-6">Aplica a</th>
              <th class="px-5 py-2.5 sm:px-6 text-right">Servicios</th>
              <th class="px-5 py-2.5 sm:px-6">Estado</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border-subtle">
            <tr v-for="item in templates" :key="item.id">
              <td class="px-5 py-3 font-mono text-xs font-semibold text-ink sm:px-6">{{ item.code }}</td>
              <td class="px-5 py-3 sm:px-6">
                <p class="font-semibold text-ink">{{ item.name }}</p>
                <p class="text-xs text-ink-muted">{{ item.scope }}</p>
              </td>
              <td class="px-5 py-3 text-ink-muted sm:px-6">
                {{ item.equipmentType }}<span v-if="item.brand"> · {{ item.brand }}</span><span v-if="item.model"> {{ item.model }}</span>
              </td>
              <td class="px-5 py-3 text-right font-semibold text-ink sm:px-6">{{ item.itemCount }}</td>
              <td class="px-5 py-3 sm:px-6"><StatusBadge :status="item.active ? 'ACTIVO' : 'INACTIVO'" /></td>
            </tr>
          </tbody>
        </table>
      </div>
    </PanelCard>

    <PanelCard title="Catálogo de servicios" :count="servicesWithItems.length" flush>
      <EmptyState v-if="servicesWithItems.length === 0" title="Todavía no hay servicios" description="Los servicios importados aparecerán acá con su cantidad de tareas y materiales sugeridos." />
      <div v-else class="overflow-x-auto">
        <table class="w-full min-w-[40rem] text-left text-sm">
          <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted">
            <tr>
              <th class="px-5 py-2.5 sm:px-6">Código</th>
              <th class="px-5 py-2.5 sm:px-6">Servicio</th>
              <th class="px-5 py-2.5 sm:px-6">Categoría</th>
              <th class="px-5 py-2.5 sm:px-6 text-right">Tareas</th>
              <th class="px-5 py-2.5 sm:px-6 text-right">Materiales</th>
              <th class="px-5 py-2.5 sm:px-6">Estado</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border-subtle">
            <tr v-for="service in servicesWithItems" :key="service.id">
              <td class="px-5 py-3 font-mono text-xs font-semibold text-ink sm:px-6">{{ service.code }}</td>
              <td class="px-5 py-3 font-semibold text-ink sm:px-6">{{ service.name }}</td>
              <td class="px-5 py-3 text-ink-muted sm:px-6">{{ service.category || 'Sin categoría' }}</td>
              <td class="px-5 py-3 text-right text-ink sm:px-6">{{ service.taskCount }}</td>
              <td class="px-5 py-3 text-right text-ink sm:px-6">{{ service.materialCount }}</td>
              <td class="px-5 py-3 sm:px-6"><StatusBadge :status="service.active ? 'ACTIVO' : 'INACTIVO'" /></td>
            </tr>
          </tbody>
        </table>
      </div>
    </PanelCard>
  </div>
</template>
