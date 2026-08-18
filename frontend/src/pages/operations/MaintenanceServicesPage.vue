<script setup>
import { computed, ref } from 'vue'
import { PlusIcon, PencilSquareIcon } from '@heroicons/vue/24/outline'

const props = defineProps({ data: { type: Object, required: true } })
const query = ref('')
const showForm = ref(false)
const editing = ref(null)
const services = ref((props.data.services ?? []).map((service) => ({ ...service, tasks: [...(service.tasks ?? [])] })))
const taskForm = ref({ code: '', name: '', order: 1, mandatory: true })
const taskBusy = ref(false)
const taskError = ref('')

const filtered = computed(() => {
  const q = query.value.trim().toLowerCase()
  if (!q) return services.value
  return services.value.filter((item) =>
    [item.codigo, item.nombre, item.descripcion, item.categoria].some((v) => String(v ?? '').toLowerCase().includes(q)),
  )
})

const formAction = computed(() => editing.value
  ? `${props.data.urls.base}/${editing.value.id}`
  : props.data.urls.create)

function startCreate() {
  editing.value = null
  showForm.value = true
  taskError.value = ''
}
function startEdit(service) {
  editing.value = { ...service, tasks: [...(service.tasks ?? [])] }
  taskForm.value = { code: '', name: '', order: (service.tasks?.length ?? 0) + 1, mandatory: true }
  taskError.value = ''
  showForm.value = true
}
function value(field) { return editing.value?.[field] ?? '' }
function taskCreateUrl() { return `${props.data.urls.base}/${editing.value.id}/tareas/nueva` }
async function createTask() {
  if (!editing.value || taskBusy.value) return
  taskBusy.value = true
  taskError.value = ''
  const form = new FormData()
  form.append(props.data.csrf.name, props.data.csrf.hash)
  form.append('codigo', taskForm.value.code)
  form.append('nombre', taskForm.value.name)
  form.append('orden', String(taskForm.value.order))
  if (taskForm.value.mandatory) form.append('obligatoria', '1')
  form.append('activo', '1')

  try {
    const response = await fetch(taskCreateUrl(), {
      method: 'POST',
      body: form,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
    })
    const payload = await response.json()
    if (!response.ok || !payload.ok) throw new Error(payload.message || 'No se pudo agregar la tarea.')
    editing.value.tasks.push(payload.task)
    editing.value.tareas_count = editing.value.tasks.length
    const service = services.value.find((item) => Number(item.id) === Number(editing.value.id))
    if (service) {
      service.tasks = [...editing.value.tasks]
      service.tareas_count = editing.value.tasks.length
    }
    taskForm.value = { code: '', name: '', order: editing.value.tasks.length + 1, mandatory: true }
  } catch (error) {
    taskError.value = error instanceof Error ? error.message : 'No se pudo agregar la tarea.'
  } finally {
    taskBusy.value = false
  }
}
function frequency(service) {
  const parts = []
  if (service.intervalo_km) parts.push(`cada ${Number(service.intervalo_km).toLocaleString('es-AR')} km`)
  if (service.intervalo_horas) parts.push(`cada ${service.intervalo_horas} h`)
  if (service.intervalo_dias) parts.push(`cada ${service.intervalo_dias} días`)
  return parts.join(' · ') || 'Sin frecuencia'
}
function advance(service) {
  const parts = []
  if (service.anticipacion_km) parts.push(`${Number(service.anticipacion_km).toLocaleString('es-AR')} km`)
  if (service.anticipacion_horas) parts.push(`${service.anticipacion_horas} h`)
  if (service.anticipacion_dias) parts.push(`${service.anticipacion_dias} días`)
  return parts.length ? `Avisar ${parts.join(' / ')} antes` : 'Sin anticipación'
}
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <p class="text-sm font-semibold text-primary">Mantenimiento preventivo</p>
        <h1 class="mt-1 text-2xl font-bold tracking-tight text-ink sm:text-3xl">Servicios de mantenimiento</h1>
        <p class="mt-2 max-w-3xl text-sm leading-6 text-ink-muted">
          Acá se define qué se hace, cada cuánto, con cuánto aviso y qué tareas o repuestos requiere. Después estos servicios se asignan a los equipos.
        </p>
      </div>
      <button v-if="data.canEdit" type="button" class="inline-flex min-h-11 items-center gap-2 self-start rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground hover:bg-primary-hover sm:self-auto" @click="startCreate">
        <PlusIcon class="size-5" /> Nuevo servicio
      </button>
    </header>

    <section v-if="showForm" class="rounded-xl border border-primary/20 bg-surface p-5 shadow-sm">
      <div class="flex items-start justify-between gap-4">
        <div>
          <h2 class="text-lg font-bold text-ink">{{ editing ? `Editar ${editing.nombre}` : 'Nuevo servicio de mantenimiento' }}</h2>
          <p class="mt-1 text-sm text-ink-muted">La frecuencia pertenece al servicio y será la misma para los equipos que lo usen.</p>
        </div>
        <button type="button" class="text-sm font-semibold text-ink-muted hover:text-ink" @click="showForm = false">Cerrar</button>
      </div>
      <form :action="formAction" method="post" class="mt-5 space-y-5">
        <input type="hidden" :name="data.csrf.name" :value="data.csrf.hash" />
        <div class="grid gap-4 md:grid-cols-2">
          <label class="text-sm font-semibold text-ink">Código
            <input name="codigo" required maxlength="50" :value="value('codigo')" class="mt-1.5 min-h-11 w-full rounded-lg border border-border bg-white px-3 py-2 font-normal" placeholder="SERV-MOTOR" />
          </label>
          <label class="text-sm font-semibold text-ink">Nombre
            <input name="nombre" required maxlength="150" :value="value('nombre')" class="mt-1.5 min-h-11 w-full rounded-lg border border-border bg-white px-3 py-2 font-normal" placeholder="Servicio motor" />
          </label>
          <label class="text-sm font-semibold text-ink">Categoría <span class="font-normal text-ink-muted">(opcional)</span>
            <input name="categoria" :value="value('categoria')" class="mt-1.5 min-h-11 w-full rounded-lg border border-border bg-white px-3 py-2 font-normal" placeholder="Motor" />
          </label>
          <label class="text-sm font-semibold text-ink">Prioridad
            <select name="prioridad" :value="value('prioridad') || 'MEDIA'" class="mt-1.5 min-h-11 w-full rounded-lg border border-border bg-white px-3 py-2 font-normal">
              <option>BAJA</option><option>MEDIA</option><option>ALTA</option><option>CRITICA</option>
            </select>
          </label>
        </div>
        <label class="block text-sm font-semibold text-ink">Descripción
          <textarea name="descripcion" rows="2" :value="value('descripcion')" class="mt-1.5 w-full rounded-lg border border-border bg-white px-3 py-2 font-normal" placeholder="Qué mantenimiento representa este servicio"></textarea>
        </label>

        <div>
          <h3 class="font-bold text-ink">Frecuencia</h3>
          <p class="mt-1 text-sm text-ink-muted">Informá al menos un criterio. Si hay varios, vence cuando se alcanza el primero.</p>
          <div class="mt-3 grid gap-4 md:grid-cols-3">
            <label class="text-sm font-semibold text-ink">Cada km<input name="intervalo_km" type="number" min="1" :value="value('intervalo_km')" class="mt-1.5 min-h-11 w-full rounded-lg border border-border px-3 py-2 font-normal" /></label>
            <label class="text-sm font-semibold text-ink">Cada horas<input name="intervalo_horas" inputmode="decimal" :value="value('intervalo_horas')" class="mt-1.5 min-h-11 w-full rounded-lg border border-border px-3 py-2 font-normal" /></label>
            <label class="text-sm font-semibold text-ink">Cada días<input name="intervalo_dias" type="number" min="1" :value="value('intervalo_dias')" class="mt-1.5 min-h-11 w-full rounded-lg border border-border px-3 py-2 font-normal" /></label>
          </div>
        </div>

        <div>
          <h3 class="font-bold text-ink">Avisar antes</h3>
          <div class="mt-3 grid gap-4 md:grid-cols-3">
            <label class="text-sm font-semibold text-ink">Km antes<input name="anticipacion_km" type="number" min="0" :value="value('anticipacion_km')" class="mt-1.5 min-h-11 w-full rounded-lg border border-border px-3 py-2 font-normal" /></label>
            <label class="text-sm font-semibold text-ink">Horas antes<input name="anticipacion_horas" inputmode="decimal" :value="value('anticipacion_horas')" class="mt-1.5 min-h-11 w-full rounded-lg border border-border px-3 py-2 font-normal" /></label>
            <label class="text-sm font-semibold text-ink">Días antes<input name="anticipacion_dias" type="number" min="0" :value="value('anticipacion_dias')" class="mt-1.5 min-h-11 w-full rounded-lg border border-border px-3 py-2 font-normal" /></label>
          </div>
        </div>
        <div class="flex justify-end gap-2">
          <button type="button" class="min-h-11 rounded-lg border border-border px-4 py-2 text-sm font-semibold" @click="showForm = false">Cancelar</button>
          <button type="submit" class="min-h-11 rounded-lg bg-primary px-5 py-2 text-sm font-semibold text-primary-foreground">{{ editing ? 'Guardar cambios' : 'Crear servicio' }}</button>
        </div>
      </form>
    </section>

    <section>
      <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div><h2 class="text-lg font-bold text-ink">Catálogo</h2><p class="text-sm text-ink-muted">{{ data.services?.length ?? 0 }} servicios disponibles.</p></div>
        <input v-model="query" type="search" class="min-h-11 w-full rounded-lg border border-border bg-white px-3 py-2 text-sm sm:max-w-sm" placeholder="Buscar por código, nombre o categoría" />
      </div>

      <div v-if="filtered.length" class="mt-4 grid gap-3 lg:grid-cols-2">
        <article v-for="service in filtered" :key="service.id" class="rounded-xl border border-border bg-surface p-5 shadow-sm" :class="!service.activo && 'opacity-65'">
          <div class="flex items-start justify-between gap-4">
            <div>
              <div class="flex flex-wrap items-center gap-2"><h3 class="font-bold text-ink">{{ service.nombre }}</h3><span class="rounded-full bg-surface-muted px-2 py-0.5 text-xs font-semibold text-ink-muted">{{ service.codigo }}</span></div>
              <p v-if="service.descripcion" class="mt-1 text-sm text-ink-muted">{{ service.descripcion }}</p>
              <p class="mt-3 text-sm font-semibold text-ink">{{ frequency(service) }}</p>
              <p class="mt-1 text-sm text-ink-muted">{{ advance(service) }}</p>
              <p class="mt-3 text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ service.tareas_count }} tareas · {{ service.materiales_count }} repuestos/insumos · {{ service.prioridad }}</p>
            </div>
            <button v-if="data.canEdit" type="button" :aria-label="`Editar ${service.nombre}`" class="inline-flex min-h-10 items-center gap-1.5 rounded-lg border border-border px-3 py-2 text-sm font-semibold" @click="startEdit(service)"><PencilSquareIcon class="size-4" /> Editar</button>
          </div>
          <form v-if="data.canEdit" :action="`${data.urls.base}/${service.id}/estado`" method="post" class="mt-4 border-t border-border pt-3">
            <input type="hidden" :name="data.csrf.name" :value="data.csrf.hash" /><input type="hidden" name="activo" :value="service.activo ? '0' : '1'" />
            <button class="text-sm font-semibold" :class="service.activo ? 'text-danger' : 'text-success'">{{ service.activo ? 'Inactivar servicio' : 'Activar servicio' }}</button>
      </form>

      <section v-if="editing" class="mt-6 border-t border-border pt-5">
        <div class="flex flex-wrap items-end justify-between gap-3">
          <div>
            <h3 class="font-bold text-ink">Tareas del servicio</h3>
            <p class="mt-1 text-sm text-ink-muted">La OT preventiva necesita al menos una tarea activa.</p>
          </div>
          <span class="text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ editing.tasks.length }} tarea(s)</span>
        </div>
        <ul v-if="editing.tasks.length" class="mt-3 space-y-2">
          <li v-for="task in editing.tasks" :key="task.id" class="rounded-lg border border-border bg-white px-3 py-2 text-sm">
            <strong>{{ task.name }}</strong><span class="ml-2 text-xs text-ink-muted">{{ task.code }} · orden {{ task.order }}</span>
          </li>
        </ul>
        <form data-task-form class="mt-4 grid gap-3 rounded-lg border border-dashed border-border p-3 sm:grid-cols-[1fr_1.5fr_7rem_auto] sm:items-end" @submit.prevent="createTask">
          <label class="text-xs font-semibold text-ink">Código<input v-model="taskForm.code" name="tarea_codigo" required maxlength="50" class="mt-1 min-h-10 w-full rounded-lg border border-border px-3 py-2 text-sm font-normal" /></label>
          <label class="text-xs font-semibold text-ink">Nombre<input v-model="taskForm.name" name="tarea_nombre" required maxlength="150" class="mt-1 min-h-10 w-full rounded-lg border border-border px-3 py-2 text-sm font-normal" /></label>
          <label class="text-xs font-semibold text-ink">Orden<input v-model="taskForm.order" name="tarea_orden" type="number" min="1" required class="mt-1 min-h-10 w-full rounded-lg border border-border px-3 py-2 text-sm font-normal" /></label>
          <button type="submit" :disabled="taskBusy" class="min-h-10 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-primary-foreground">{{ taskBusy ? 'Agregando…' : 'Agregar tarea' }}</button>
        </form>
        <p v-if="taskError" class="mt-2 text-sm font-semibold text-danger-strong">{{ taskError }}</p>
      </section>
        </article>
      </div>
      <div v-else class="mt-4 rounded-xl border border-dashed border-border bg-surface p-8 text-center">
        <h3 class="font-bold text-ink">{{ data.services?.length ? 'No hay coincidencias' : 'Todavía no hay servicios de mantenimiento' }}</h3>
        <p class="mt-2 text-sm text-ink-muted">{{ data.services?.length ? 'Probá con otra búsqueda.' : 'Creá el primero directamente desde el sistema.' }}</p>
        <button v-if="data.canEdit && !data.services?.length" type="button" class="mt-4 rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground" @click="startCreate">Crear primer servicio</button>
      </div>
    </section>
  </div>
</template>
