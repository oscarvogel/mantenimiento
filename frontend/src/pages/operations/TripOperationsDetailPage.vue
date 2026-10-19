<script setup>
import { computed } from 'vue'
import { CheckCircleIcon, XCircleIcon } from '@heroicons/vue/24/outline'
import PageHeading from './components/PageHeading.vue'
import PanelCard from './components/PanelCard.vue'
import { fieldClass, primaryButton, dangerButton } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })

const trip = computed(() => props.data.trip ?? {})
const assignments = computed(() => props.data.assignments ?? [])
const stops = computed(() => props.data.stops ?? [])
const csrf = computed(() => props.data.csrf ?? { name: 'csrf_test_name', hash: '' })
const statusTone = {
  PLANIFICADO: 'bg-info-subtle text-info-strong',
  EN_CURSO: 'bg-success-subtle text-success-strong',
  FINALIZADO: 'bg-surface-muted text-ink-muted',
  CANCELADO: 'bg-danger-subtle text-danger-strong',
}

const fmtDateTime = (value) => {
  const match = String(value ?? '').match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/)
  return match ? `${match[3]}/${match[2]}/${match[1]} ${match[4]}:${match[5]}` : 'Sin fecha'
}
const number = (value) => value === null || value === undefined || value === '' ? '—' : Number(value).toLocaleString('es-AR')
const facts = computed(() => [
  ['Viaje', `#${trip.value.numero}`],
  ['Estado', trip.value.estado_label],
  ['Sucursal de origen', trip.value.sucursal],
  ['Salida planificada', fmtDateTime(trip.value.fecha_salida_planificada)],
  ['Regreso planificado', fmtDateTime(trip.value.fecha_regreso_planificada)],
  ['Salida real', trip.value.fecha_salida_real ? fmtDateTime(trip.value.fecha_salida_real) : null],
  ['Odómetro de salida', trip.value.km_salida === null ? null : `${number(trip.value.km_salida)} km`],
  ['Observaciones', trip.value.observaciones],
  ['Motivo de cancelación', trip.value.motivo_cancelacion],
])
</script>

<template>
  <div>
    <PageHeading eyebrow="Viajes · Operativo" :title="`Viaje #${trip.numero}`" description="Seguimiento del recorrido, el equipo asignado y los choferes. La planificación se conserva aunque el viaje avance o se cancele." :back="{ href: data.backUrl ?? '/viajes/operativos', label: 'Volver a viajes operativos' }">
      <template #actions>
        <span class="inline-flex rounded-full px-3 py-1.5 text-sm font-semibold" :class="statusTone[trip.estado] ?? 'bg-surface-muted text-ink-muted'">{{ trip.estado_label }}</span>
      </template>
    </PageHeading>

    <PanelCard title="Datos generales" class="mb-5">
      <dl class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <div v-for="([label, value]) in facts" :key="label" class="min-w-0 rounded-lg border border-border-subtle bg-surface p-3">
          <dt class="text-xs font-semibold text-ink-muted">{{ label }}</dt>
          <dd class="mt-1 break-words text-sm font-semibold text-ink">{{ value === null || value === undefined || value === '' ? '—' : value }}</dd>
        </div>
      </dl>
    </PanelCard>

    <div class="mb-5 grid gap-5 xl:grid-cols-2">
      <PanelCard title="Equipo y choferes" :count="assignments.length">
        <p v-if="assignments.length === 0" class="text-sm text-ink-muted">El viaje no tiene asignaciones registradas.</p>
        <ul v-else class="divide-y divide-border-subtle">
          <li v-for="(assignment, index) in assignments" :key="`${assignment.rol}-${index}`" class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
            <div>
              <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ assignment.rol_label }}</p>
              <p class="mt-0.5 text-sm font-semibold text-ink">{{ assignment.label || 'Sin dato' }}</p>
            </div>
            <p class="text-xs text-ink-muted">{{ fmtDateTime(assignment.desde) }} — {{ assignment.hasta ? fmtDateTime(assignment.hasta) : 'sin fin' }}</p>
          </li>
        </ul>
      </PanelCard>

      <PanelCard title="Acciones" v-if="data.canStart || data.canCancel">
        <form v-if="data.canStart" method="post" :action="`${data.links?.detail ?? ''}/iniciar`" class="mb-5 rounded-xl border border-border-subtle bg-surface p-4">
          <input type="hidden" :name="csrf.name" :value="csrf.hash" />
          <h3 class="flex items-center gap-2 text-sm font-bold text-ink"><CheckCircleIcon class="size-4 text-success" aria-hidden="true" />Registrar salida real</h3>
          <p class="mt-1 text-xs text-ink-muted">Al iniciar, el viaje pasa a En curso. Si no indicás la fecha y hora, se usa el momento actual.</p>
          <div class="mt-3 grid gap-3 sm:grid-cols-2">
            <label class="block">
              <span class="mb-1.5 block text-sm font-semibold text-ink">Fecha y hora de salida</span>
              <input type="datetime-local" name="fecha_salida_real" :class="fieldClass" />
            </label>
            <label class="block">
              <span class="mb-1.5 block text-sm font-semibold text-ink">Odómetro de salida</span>
              <input type="number" name="km_salida" min="0" step="1" required :class="fieldClass" />
            </label>
          </div>
          <button type="submit" :class="[primaryButton, 'mt-3']">Iniciar viaje</button>
        </form>

        <form v-if="data.canCancel" method="post" :action="`${data.links?.detail ?? ''}/cancelar`" class="rounded-xl border border-danger/30 bg-danger-subtle/40 p-4">
          <input type="hidden" :name="csrf.name" :value="csrf.hash" />
          <h3 class="flex items-center gap-2 text-sm font-bold text-danger-strong"><XCircleIcon class="size-4" aria-hidden="true" />Cancelar viaje</h3>
          <p class="mt-1 text-xs text-ink-muted">El viaje cancelado conserva su recorrido y asignaciones; el motivo queda registrado.</p>
          <label class="mt-3 block">
            <span class="mb-1.5 block text-sm font-semibold text-ink">Motivo de cancelación</span>
            <textarea name="motivo_cancelacion" rows="3" maxlength="500" required :class="fieldClass"></textarea>
          </label>
          <button type="submit" :class="[dangerButton, 'mt-3']">Cancelar viaje</button>
        </form>
      </PanelCard>
    </div>

    <PanelCard title="Recorrido" :count="stops.length">
      <p v-if="stops.length === 0" class="text-sm text-ink-muted">El viaje no tiene paradas registradas.</p>
      <ol v-else class="space-y-4">
        <li v-for="stop in stops" :key="stop.orden" class="rounded-xl border border-border-subtle bg-surface p-4">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Parada {{ stop.orden }} · {{ stop.tipo_label }}</p>
              <p class="mt-1 text-sm font-bold text-ink">{{ stop.ubicacion }}</p>
              <p v-if="stop.cliente" class="mt-0.5 text-sm text-ink-muted">Cliente: {{ stop.cliente }}</p>
              <p v-if="stop.domicilio" class="mt-0.5 text-xs text-ink-muted">{{ stop.domicilio }}</p>
            </div>
            <p class="text-xs text-ink-muted">Estimada: {{ stop.fecha_estimada ? fmtDateTime(stop.fecha_estimada) : 'sin fecha' }}</p>
          </div>
          <dl v-if="stop.descripcion_carga || stop.cantidad || stop.observaciones" class="mt-3 grid gap-2 border-t border-border-subtle pt-3 text-sm sm:grid-cols-3">
            <div v-if="stop.descripcion_carga"><dt class="text-xs text-ink-muted">Carga</dt><dd class="font-semibold text-ink">{{ stop.descripcion_carga }}</dd></div>
            <div v-if="stop.cantidad"><dt class="text-xs text-ink-muted">Cantidad</dt><dd class="font-semibold text-ink">{{ number(stop.cantidad) }} {{ stop.unidad ?? '' }}</dd></div>
            <div v-if="stop.observaciones"><dt class="text-xs text-ink-muted">Observaciones</dt><dd class="font-semibold text-ink">{{ stop.observaciones }}</dd></div>
          </dl>
        </li>
      </ol>
    </PanelCard>
  </div>
</template>
