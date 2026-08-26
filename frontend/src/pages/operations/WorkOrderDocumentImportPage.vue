<script setup>
import { computed, reactive } from 'vue'
import { ArrowLeftIcon, ArrowPathIcon, DocumentArrowUpIcon, DocumentTextIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline'
import CsrfInput from './components/CsrfInput.vue'
import PageHeading from './components/PageHeading.vue'
import { fieldClass, primaryButton, secondaryButton } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })
const proposal = reactive(structuredClone(props.data.import?.proposal ?? {}))
const analysis = computed(() => props.data.import?.analysis ?? proposal.analysis ?? {})
const works = computed(() => proposal.works ?? [])
const correctiveWorks = computed(() => works.value.filter((item) => item.included !== false && item.classification === 'correctivo'))
const preventiveWorks = computed(() => works.value.filter((item) => item.included !== false && item.classification === 'preventivo'))
const confidenceLabel = (value) => {
  const number = Number(value ?? 0)
  if (number >= 0.85) return 'Alta'
  if (number >= 0.6) return 'Media'
  return 'Revisar'
}
const formatReading = (value) => value === null || value === undefined || value === '' ? 'No detectada' : Number(value).toLocaleString('es-AR')
</script>

<template>
  <div>
    <PageHeading
      eyebrow="Órdenes de trabajo"
      :title="data.mode === 'upload' ? 'Importar orden de taller' : 'Revisar documento de taller'"
      description="Subí el comprobante, dejá que la IA extraiga los datos y confirmá solo lo que corresponda."
    >
      <template #actions>
        <a :href="data.routes.orders" :class="secondaryButton"><ArrowLeftIcon class="mr-2 size-4" />Volver a OT</a>
      </template>
    </PageHeading>

    <section v-if="data.mode === 'upload'" class="mx-auto max-w-3xl rounded-2xl border border-border bg-surface-raised p-6 shadow-sm">
      <div class="mb-5 flex items-start gap-4">
        <div class="rounded-xl bg-primary/10 p-3 text-primary"><DocumentArrowUpIcon class="size-7" /></div>
        <div><h2 class="text-lg font-bold text-ink">Foto o PDF de la orden del taller</h2><p class="mt-1 text-sm text-ink-muted">Formatos admitidos: JPG, PNG y PDF. El original queda guardado para auditoría.</p></div>
      </div>
      <form method="post" :action="data.routes.upload" enctype="multipart/form-data" class="space-y-5">
        <CsrfInput :csrf="data.csrf" />
        <input type="hidden" name="idempotency_key" :value="`ot-doc-${Date.now()}-${Math.random().toString(16).slice(2)}`" />
        <label class="block"><span class="mb-1 block text-sm font-semibold text-ink">Sucursal</span><select name="sucursal_id" required :class="fieldClass"><option value="">Seleccionar sucursal</option><option v-for="branch in data.branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option></select></label>
        <label class="block"><span class="mb-1 block text-sm font-semibold text-ink">Documento</span><input name="documento" type="file" required accept="image/jpeg,image/png,application/pdf" class="block min-h-12 w-full rounded-xl border border-dashed border-border-strong bg-surface px-4 py-3 text-sm text-ink file:mr-4 file:rounded-lg file:border-0 file:bg-primary file:px-4 file:py-2 file:font-semibold file:text-white hover:border-primary/50" /></label>
        <div class="rounded-xl bg-surface p-4 text-sm text-ink-muted"><strong class="text-ink">La IA no crea nada automáticamente.</strong> Primero vas a revisar patente/equipo, lectura, tareas y repuestos; después podrás elegir OT correctiva, preventiva o ambas.</div>
        <button type="submit" :class="primaryButton"><DocumentArrowUpIcon class="mr-2 size-4" />Subir y analizar</button>
      </form>
    </section>

    <div v-else class="space-y-5">
      <section class="grid gap-4 xl:grid-cols-[minmax(19rem,.85fr)_minmax(0,1.65fr)]">
        <article class="rounded-2xl border border-border bg-surface-raised p-5 shadow-sm">
          <div class="flex items-start justify-between gap-3"><div><p class="text-xs font-bold uppercase tracking-wide text-ink-muted">Documento original</p><h2 class="mt-1 font-bold text-ink">{{ data.import.originalName }}</h2></div><DocumentTextIcon class="size-7 text-primary" /></div>
          <dl class="mt-5 space-y-3 text-sm">
            <div class="flex justify-between gap-3"><dt class="text-ink-muted">Estado</dt><dd class="font-semibold text-ink">{{ data.import.status }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-ink-muted">Tipo</dt><dd class="font-semibold text-ink">{{ data.import.mimeType }}</dd></div>
          </dl>
          <div v-if="data.import.error" class="mt-4 rounded-xl border border-warning/30 bg-warning/10 p-3 text-sm text-ink"><ExclamationTriangleIcon class="mr-1 inline size-4" />{{ data.import.error }}</div>
          <div class="mt-5 flex flex-wrap gap-2"><a :href="data.routes.download" target="_blank" :class="secondaryButton">Ver original</a><form method="post" :action="data.routes.reanalyze"><CsrfInput :csrf="data.csrf" /><button :class="secondaryButton"><ArrowPathIcon class="mr-2 size-4" />Reanalizar</button></form></div>
        </article>

        <article class="rounded-2xl border border-border bg-surface-raised p-5 shadow-sm">
          <h2 class="text-lg font-bold text-ink">Datos detectados</h2>
          <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <div class="rounded-xl bg-surface p-3"><p class="text-xs font-bold uppercase text-ink-muted">Patente</p><p class="mt-1 text-lg font-bold text-ink">{{ analysis.plate || 'No detectada' }}</p><p class="text-xs text-ink-muted">Confianza: {{ confidenceLabel(analysis.confidence?.plate) }}</p></div>
            <div class="rounded-xl bg-surface p-3"><p class="text-xs font-bold uppercase text-ink-muted">Equipo resuelto</p><p class="mt-1 font-bold text-ink">{{ proposal.equipment?.codigo || 'Requiere revisión' }}</p><p class="text-xs text-ink-muted">{{ proposal.equipment?.patente || proposal.equipmentResolution }}</p></div>
            <div class="rounded-xl bg-surface p-3"><p class="text-xs font-bold uppercase text-ink-muted">Fecha</p><p class="mt-1 font-bold text-ink">{{ analysis.serviceDate || 'No detectada' }}</p><p class="text-xs text-ink-muted">Confianza: {{ confidenceLabel(analysis.confidence?.service_date) }}</p></div>
            <div class="rounded-xl bg-surface p-3"><p class="text-xs font-bold uppercase text-ink-muted">Lectura</p><p class="mt-1 font-bold text-ink">{{ formatReading(analysis.readingValue) }} {{ analysis.readingType || '' }}</p><p class="text-xs text-ink-muted">Actual: {{ proposal.equipment ? formatReading(analysis.readingType === 'horas' ? proposal.equipment.horas_actuales : proposal.equipment.km_actual) : '—' }}</p></div>
            <div class="rounded-xl bg-surface p-3"><p class="text-xs font-bold uppercase text-ink-muted">Taller</p><p class="mt-1 font-bold text-ink">{{ analysis.supplier || 'No detectado' }}</p></div>
            <div class="rounded-xl bg-surface p-3"><p class="text-xs font-bold uppercase text-ink-muted">Concepto</p><p class="mt-1 font-bold text-ink">{{ analysis.concept || 'No detectado' }}</p></div>
          </div>
          <p v-if="proposal.readingWarning" class="mt-4 rounded-xl border border-warning/30 bg-warning/10 p-3 text-sm font-semibold text-ink"><ExclamationTriangleIcon class="mr-1 inline size-4" />{{ proposal.readingWarning }}</p>
        </article>
      </section>

      <section class="rounded-2xl border border-border bg-surface-raised p-5 shadow-sm">
        <div class="flex flex-wrap items-end justify-between gap-3"><div><p class="text-xs font-bold uppercase tracking-wide text-ink-muted">Trabajos interpretados</p><h2 class="mt-1 text-lg font-bold text-ink">Revisá qué fue correctivo y qué fue preventivo</h2></div><div class="text-sm text-ink-muted"><strong class="text-ink">{{ correctiveWorks.length }}</strong> correctivos · <strong class="text-ink">{{ preventiveWorks.length }}</strong> preventivos</div></div>
        <div class="mt-4 overflow-x-auto">
          <table class="min-w-full text-left text-sm">
            <thead><tr class="border-b border-border text-xs uppercase tracking-wide text-ink-muted"><th class="px-2 py-3">Incluir</th><th class="px-2 py-3">Trabajo</th><th class="px-2 py-3">Clasificación</th><th class="px-2 py-3">Confianza</th></tr></thead>
            <tbody>
              <tr v-for="(item, index) in proposal.works" :key="index" class="border-b border-border/70 align-top">
                <td class="px-2 py-3"><input v-model="item.included" type="checkbox" class="size-4 rounded border-border-strong text-primary" /></td>
                <td class="px-2 py-3"><input v-model="item.description" :class="fieldClass" /><p v-if="item.source_text && item.source_text !== item.description" class="mt-1 max-w-xl text-xs text-ink-muted">Original: {{ item.source_text }}</p></td>
                <td class="px-2 py-3"><select v-model="item.classification" :class="fieldClass"><option value="correctivo">Correctivo</option><option value="preventivo">Preventivo</option><option value="revisar">Revisar</option></select></td>
                <td class="px-2 py-3"><span :class="item.confidence >= .85 ? 'font-semibold text-success' : item.confidence >= .6 ? 'font-semibold text-warning' : 'font-semibold text-danger'">{{ Math.round((item.confidence || 0) * 100) }}%</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="grid gap-4 lg:grid-cols-2">
        <article class="rounded-2xl border border-border bg-surface-raised p-5 shadow-sm"><h2 class="font-bold text-ink">Repuestos y consumibles detectados</h2><ul class="mt-3 space-y-2 text-sm"><li v-for="(item, index) in proposal.materials" :key="index" class="flex justify-between gap-4 rounded-lg bg-surface px-3 py-2"><span>{{ item.description }}</span><strong>{{ item.quantity ?? '—' }} {{ item.unit || '' }}</strong></li><li v-if="!proposal.materials?.length" class="text-ink-muted">No se detectaron materiales.</li></ul></article>
        <article class="rounded-2xl border border-border bg-surface-raised p-5 shadow-sm"><h2 class="font-bold text-ink">Planes preventivos del equipo</h2><ul class="mt-3 space-y-2 text-sm"><li v-for="plan in proposal.preventivePlans" :key="plan.id" class="rounded-lg bg-surface px-3 py-2"><strong>{{ plan.servicio_nombre || `Plan #${plan.id}` }}</strong><span class="ml-2 text-ink-muted">Próximo: {{ plan.proximo_km ? `${Number(plan.proximo_km).toLocaleString('es-AR')} km` : plan.proxima_fecha || plan.proximas_horas || 'sin objetivo' }}</span></li><li v-if="!proposal.preventivePlans?.length" class="text-ink-muted">No hay planes activos asociados al equipo detectado.</li></ul></article>
      </section>

      <section class="rounded-2xl border border-primary/20 bg-primary/5 p-5">
        <h2 class="text-lg font-bold text-ink">Siguiente paso</h2>
        <p class="mt-1 text-sm text-ink-muted">La propuesta ya separa correctivo y preventivo. La creación definitiva permanece bloqueada hasta confirmar el equipo, la lectura y el plan preventivo cuando corresponda.</p>
        <div class="mt-4 flex flex-wrap gap-2"><button type="button" :class="primaryButton" disabled title="Se habilita al completar la confirmación final">Crear OT correctiva</button><button type="button" :class="primaryButton" disabled>Crear OT preventiva</button><button type="button" :class="primaryButton" disabled>Crear ambas</button><a :href="data.routes.newImport" :class="secondaryButton">Importar otro documento</a></div>
      </section>
    </div>
  </div>
</template>
