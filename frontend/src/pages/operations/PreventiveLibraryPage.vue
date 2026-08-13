<script setup>
import { computed, ref } from 'vue'
import { ArrowDownTrayIcon } from '@heroicons/vue/24/outline'
import CsrfInput from './components/CsrfInput.vue'
import PageHeading from './components/PageHeading.vue'
import PanelCard from './components/PanelCard.vue'
import EmptyState from './components/EmptyState.vue'
import FormField from './components/FormField.vue'
import StatusBadge from './components/StatusBadge.vue'
import { fieldClass, primaryButton, secondaryButton } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })
const templates = computed(() => props.data.templates ?? [])
const services = computed(() => props.data.services ?? [])
const items = computed(() => props.data.items ?? [])
const searchQuery = ref('')
const valueOrBlank = (value) => (value === null || value === undefined ? '' : String(value))
const normalize = (value) => valueOrBlank(value).trim().toLowerCase()
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
</script>

<template>
  <div>
    <PageHeading
      eyebrow="Mantenimiento preventivo"
      title="Biblioteca preventiva"
      description="Servicios, tareas, materiales sugeridos y plantillas disponibles para tu empresa."
      :back="{ label: 'Volver a importaciones', href: data.routes.back }"
    />

    <div class="mb-6">
      <a :href="data.routes.downloadTemplate" :class="secondaryButton">
        <ArrowDownTrayIcon class="mr-2 size-4" aria-hidden="true" />Descargar plantilla general de camiones
      </a>
    </div>

    <section class="mb-6 grid gap-3 sm:grid-cols-2">
      <article class="rounded-xl border border-border bg-white p-5 shadow-card">
        <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Plantillas</p>
        <p class="mt-2 text-3xl font-bold text-ink">{{ templates.length }}</p>
      </article>
      <article class="rounded-xl border border-border bg-white p-5 shadow-card">
        <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Servicios</p>
        <p class="mt-2 text-3xl font-bold text-ink">{{ services.length }}</p>
      </article>
    </section>

    <PanelCard title="Planes de biblioteca" :count="filteredItems.length" class="mb-6">
      <div v-if="items.length > 0" class="mb-4 max-w-xl">
        <FormField label="Buscar" for-id="library-search">
          <input id="library-search" v-model="searchQuery" name="q" type="search" placeholder="Plantilla, servicio, código o tarea" :class="fieldClass" />
        </FormField>
      </div>
      <EmptyState v-if="items.length === 0" title="Todavía no hay planes importados" description="Importá la biblioteca preventiva desde Excel para crear el primer plan de plantilla." />
      <EmptyState v-else-if="filteredItems.length === 0" title="No hay resultados" description="Probá con otra plantilla, servicio, código o tarea." />
      <div v-else class="grid gap-4">
        <article v-for="item in filteredItems" :key="item.id" class="rounded-xl border border-border bg-white p-4 shadow-sm">
          <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
              <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ item.templateCode }} · {{ item.equipmentType }}</p>
              <h3 class="mt-1 text-base font-bold text-ink">{{ item.serviceName }}</h3>
              <p class="mt-1 text-sm text-ink-muted">{{ item.templateName }} · {{ item.serviceCode }}</p>
            </div>
            <StatusBadge :status="item.active ? 'ACTIVO' : 'INACTIVO'" />
          </div>

          <div class="mb-4 rounded-lg border border-border-subtle bg-surface-subtle p-3">
            <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Tareas</p>
            <ul v-if="item.tasks && item.tasks.length > 0" class="mt-2 grid gap-2 lg:grid-cols-2">
              <li v-for="task in item.tasks" :key="task.id" class="rounded-lg bg-white px-3 py-2 text-sm text-ink shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-2">
                  <div>
                    <p class="font-semibold">{{ task.name }}</p>
                    <p class="mt-0.5 font-mono text-xs text-ink-muted">{{ task.code }}</p>
                  </div>
                  <div class="flex flex-wrap items-center gap-1.5">
                    <span v-if="task.mandatory" class="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary">Obligatoria</span>
                    <span v-if="!task.active" class="rounded-full bg-surface-raised px-2 py-0.5 text-xs font-semibold text-ink-muted">Inactiva</span>
                  </div>
                </div>
                <p v-if="task.description" class="mt-2 text-xs text-ink-muted">{{ task.description }}</p>
                <div v-if="task.requiresPart || task.requiresControl || task.requiresPhoto" class="mt-2 flex flex-wrap gap-1.5">
                  <span v-if="task.requiresPart" class="rounded-full bg-surface-raised px-2 py-0.5 text-xs font-semibold text-ink-muted">Repuesto</span>
                  <span v-if="task.requiresControl" class="rounded-full bg-surface-raised px-2 py-0.5 text-xs font-semibold text-ink-muted">Control</span>
                  <span v-if="task.requiresPhoto" class="rounded-full bg-surface-raised px-2 py-0.5 text-xs font-semibold text-ink-muted">Foto</span>
                </div>
                <details v-if="data.canEdit && task.updateUrl" class="ui-details-animated mt-3 border-t border-border-subtle pt-2">
                  <summary class="cursor-pointer list-none text-xs font-semibold text-primary">Editar tarea</summary>
                  <form method="post" :action="task.updateUrl" data-confirm data-confirm-title="¿Guardar cambios en la tarea?" data-confirm-text="La tarea es un catálogo compartido: el cambio se aplicará a toda la biblioteca preventiva." data-confirm-button="Guardar tarea" data-confirm-icon="warning" class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <CsrfInput :csrf="data.csrf" />
                    <input type="hidden" name="tipo_servicio_id" :value="task.serviceTypeId" />
                    <FormField label="Nombre" :for-id="`task-${task.id}-name`"><input :id="`task-${task.id}-name`" name="nombre" type="text" maxlength="150" :value="task.name" :class="fieldClass" /></FormField>
                    <FormField label="Orden" :for-id="`task-${task.id}-order`"><input :id="`task-${task.id}-order`" name="orden" type="number" min="1" :value="task.order" :class="fieldClass" /></FormField>
                    <FormField label="Duración estimada (min)" :for-id="`task-${task.id}-duration`"><input :id="`task-${task.id}-duration`" name="duracion_estimada_min" type="number" min="0" :value="valueOrBlank(task.durationMinutes)" :class="fieldClass" /></FormField>
                    <label class="flex min-h-11 items-end gap-2 rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm font-semibold text-ink"><input name="obligatoria" type="checkbox" value="1" :checked="task.mandatory" class="size-4 rounded border-border text-primary focus:ring-primary/20" />Obligatoria</label>
                    <FormField label="Descripción" :for-id="`task-${task.id}-description`" class="md:col-span-2"><textarea :id="`task-${task.id}-description`" name="descripcion" rows="2" :value="task.description || ''" :class="fieldClass"></textarea></FormField>
                    <FormField label="Procedimiento" :for-id="`task-${task.id}-procedure`" class="md:col-span-2 xl:col-span-4"><textarea :id="`task-${task.id}-procedure`" name="procedimiento" rows="2" :value="task.procedure || ''" :class="fieldClass"></textarea></FormField>
                    <FormField label="Observaciones de la relación" :for-id="`task-${task.id}-observations`" class="md:col-span-2 xl:col-span-4"><textarea :id="`task-${task.id}-observations`" name="observaciones" maxlength="500" rows="2" :value="task.observations || ''" :class="fieldClass"></textarea></FormField>
                    <div class="flex flex-wrap items-center gap-4 md:col-span-2 xl:col-span-4">
                      <label class="flex items-center gap-2 text-sm font-semibold text-ink"><input name="requiere_repuesto" type="checkbox" value="1" :checked="task.requiresPart" class="size-4 rounded border-border text-primary focus:ring-primary/20" />Requiere repuesto</label>
                      <label class="flex items-center gap-2 text-sm font-semibold text-ink"><input name="requiere_control" type="checkbox" value="1" :checked="task.requiresControl" class="size-4 rounded border-border text-primary focus:ring-primary/20" />Requiere control</label>
                      <label class="flex items-center gap-2 text-sm font-semibold text-ink"><input name="requiere_foto" type="checkbox" value="1" :checked="task.requiresPhoto" class="size-4 rounded border-border text-primary focus:ring-primary/20" />Requiere foto</label>
                      <label class="flex items-center gap-2 text-sm font-semibold text-ink"><input name="activo" type="checkbox" value="1" :checked="task.active" class="size-4 rounded border-border text-primary focus:ring-primary/20" />Activa</label>
                    </div>
                    <p class="text-xs text-ink-muted md:col-span-2 xl:col-span-4">La tarea y su orden son catálogo compartido: el cambio se aplica a toda la biblioteca.</p>
                    <button type="submit" :class="`${primaryButton} md:justify-self-start`">Guardar tarea</button>
                  </form>
                </details>
              </li>
            </ul>
            <p v-else class="mt-2 text-sm text-ink-muted">Sin tareas cargadas</p>
          </div>

          <form method="post" :action="item.updateUrl" data-confirm data-confirm-title="¿Guardar cambios en el ítem de biblioteca?" data-confirm-text="El ítem es un catálogo compartido: el cambio afectará a los planes que lo usan." data-confirm-button="Guardar ítem" data-confirm-icon="warning" class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
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
        </article>
      </div>
    </PanelCard>

    <PanelCard title="Plantillas de la empresa" :count="templates.length" flush class="mb-6">
      <EmptyState v-if="templates.length === 0" title="Todavía no hay plantillas" description="Importá la biblioteca preventiva desde Excel para crear la primera plantilla." />
      <div v-else class="overflow-x-auto">
        <table class="w-full min-w-[46rem] text-left text-sm">
          <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted">
            <tr><th class="px-6 py-3">Código</th><th class="px-6 py-3">Plantilla</th><th class="px-6 py-3">Aplica a</th><th class="px-6 py-3">Servicios</th><th class="px-6 py-3">Estado</th></tr>
          </thead>
          <tbody class="divide-y divide-border-subtle">
            <tr v-for="item in templates" :key="item.id">
              <td class="px-6 py-4 font-mono text-xs font-semibold text-ink">{{ item.code }}</td>
              <td class="px-6 py-4"><p class="font-semibold text-ink">{{ item.name }}</p><p class="text-xs text-ink-muted">{{ item.scope }}</p></td>
              <td class="px-6 py-4 text-ink-muted">{{ item.equipmentType }}<span v-if="item.brand"> · {{ item.brand }}</span><span v-if="item.model"> {{ item.model }}</span></td>
              <td class="px-6 py-4 font-semibold text-ink">{{ item.itemCount }}</td>
              <td class="px-6 py-4"><StatusBadge :status="item.active ? 'ACTIVO' : 'INACTIVO'" /></td>
            </tr>
          </tbody>
        </table>
      </div>
    </PanelCard>

    <PanelCard title="Catálogo de servicios" :count="services.length" flush>
      <EmptyState v-if="services.length === 0" title="Todavía no hay servicios" description="Los servicios importados aparecerán acá con su cantidad de tareas y materiales sugeridos." />
      <div v-else class="overflow-x-auto">
        <table class="w-full min-w-[48rem] text-left text-sm">
          <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted">
            <tr><th class="px-6 py-3">Código</th><th class="px-6 py-3">Servicio</th><th class="px-6 py-3">Categoría</th><th class="px-6 py-3">Tareas</th><th class="px-6 py-3">Materiales</th><th class="px-6 py-3">Estado</th></tr>
          </thead>
          <tbody class="divide-y divide-border-subtle">
            <tr v-for="service in services" :key="service.id">
              <td class="px-6 py-4 font-mono text-xs font-semibold text-ink">{{ service.code }}</td>
              <td class="px-6 py-4 font-semibold text-ink">{{ service.name }}</td>
              <td class="px-6 py-4 text-ink-muted">{{ service.category || 'Sin categoría' }}</td>
              <td class="px-6 py-4 text-ink">{{ service.taskCount }}</td>
              <td class="px-6 py-4 text-ink">{{ service.materialCount }}</td>
              <td class="px-6 py-4"><StatusBadge :status="service.active ? 'ACTIVO' : 'INACTIVO'" /></td>
            </tr>
          </tbody>
        </table>
      </div>
    </PanelCard>
  </div>
</template>
