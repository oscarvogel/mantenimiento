<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { ArrowLeftIcon, ArrowPathIcon, ArrowUpTrayIcon, DocumentTextIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline'
import CsrfInput from './components/CsrfInput.vue'
import PageHeading from './components/PageHeading.vue'
import { fieldClass, primaryButton, secondaryButton } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })
const proposal = reactive(JSON.parse(JSON.stringify(props.data.import?.proposal ?? {})))
const contextLoading = ref(false)
const contextError = ref('')
const analysis = computed(() => props.data.import?.analysis ?? proposal.analysis ?? {})
const works = computed(() => proposal.works ?? [])
const correctiveWorks = computed(() => works.value.filter((item) => item.included !== false && item.classification === 'correctivo'))
const preventiveWorks = computed(() => works.value.filter((item) => item.included !== false && item.classification === 'preventivo'))
const isConfirmed = computed(() => props.data.import?.status === 'CONFIRMADO')
const selectedEquipment = computed(() => (props.data.equipmentOptions ?? []).find((item) => Number(item.id) === Number(proposal.selectedEquipmentId)) ?? null)
const selectedPlan = computed(() => (proposal.preventivePlans ?? []).find((item) => Number(item.id) === Number(proposal.selectedPlanId)) ?? null)
const readingRegression = computed(() => {
  if (!selectedEquipment.value || proposal.readingValue === null || proposal.readingValue === undefined || proposal.readingValue === '') return false
  const value = Number(String(proposal.readingValue).replace(',', '.'))
  if (!Number.isFinite(value)) return false
  if (proposal.readingType === 'horas') return selectedEquipment.value.currentHours !== null && selectedEquipment.value.currentHours !== undefined && value < Number(selectedEquipment.value.currentHours)
  return selectedEquipment.value.currentKm !== null && selectedEquipment.value.currentKm !== undefined && value < Number(selectedEquipment.value.currentKm)
})
const readingPermissionOk = computed(() => proposal.readingValue === null || proposal.readingValue === undefined || proposal.readingValue === '' || props.data.can?.registerReading)
const partialPreventive = computed(() => Boolean(selectedPlan.value && selectedPlan.value.requiredTasksEvidenced === false))
const readingConfirmed = computed(() => !readingRegression.value || proposal.confirmReadingRollback === true)
const partialConfirmed = computed(() => !partialPreventive.value || proposal.confirmPartialPreventive === true)
const canCorrective = computed(() => !isConfirmed.value && readingPermissionOk.value && readingConfirmed.value && Number(proposal.selectedEquipmentId || 0) > 0 && correctiveWorks.value.length > 0)
const canPreventive = computed(() => !isConfirmed.value && readingPermissionOk.value && readingConfirmed.value && partialConfirmed.value && props.data.can?.closePreventive && Number(proposal.selectedEquipmentId || 0) > 0 && Number(proposal.selectedPlanId || 0) > 0 && preventiveWorks.value.length > 0)
const canBoth = computed(() => canCorrective.value && canPreventive.value)
const confidenceLabel = (value) => {
  const number = Number(value ?? 0)
  if (number >= 0.85) return 'Alta'
  if (number >= 0.6) return 'Media'
  return 'Revisar'
}
const formatReading = (value) => value === null || value === undefined || value === '' ? 'No detectada' : Number(value).toLocaleString('es-AR')
const confirmLabel = (action) => ({ corrective: 'Crear OT correctiva', preventive: 'Crear OT preventiva', both: 'Crear ambas OT' }[action])
const submitConfirmation = (event, action) => {
  const question = action === 'both'
    ? 'Se crearán una OT correctiva y una preventiva y se registrará la realización del plan. ¿Confirmás?'
    : `Se creará la ${action === 'corrective' ? 'OT correctiva' : 'OT preventiva'} con los datos revisados. ¿Confirmás?`
  if (!window.confirm(question)) event.preventDefault()
}

watch(() => proposal.selectedEquipmentId, async (equipmentId, previous) => {
  if (isConfirmed.value || !equipmentId || Number(equipmentId) === Number(previous) || !props.data.routes?.equipmentContext) return
  contextLoading.value = true
  contextError.value = ''
  try {
    const response = await fetch(`${props.data.routes.equipmentContext}?equipment_id=${encodeURIComponent(equipmentId)}`, {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    })
    const payload = await response.json()
    if (!response.ok) throw new Error(payload.error || 'No se pudo actualizar el equipo.')
    proposal.preventivePlans = payload.preventivePlans ?? []
    proposal.selectedPlanId = payload.selectedPlanId ?? null
    proposal.confirmPartialPreventive = false
    proposal.confirmReadingRollback = false
  } catch (error) {
    proposal.preventivePlans = []
    proposal.selectedPlanId = null
    contextError.value = error instanceof Error ? error.message : 'No se pudo actualizar el contexto del equipo.'
  } finally {
    contextLoading.value = false
  }
})
</script>

<template>
  <div>
    <PageHeading eyebrow="Órdenes de trabajo" :title="data.mode === 'upload' ? 'Importar orden de taller' : 'Revisar documento de taller'" description="Subí el comprobante, dejá que la IA extraiga los datos y confirmá solo lo que corresponda.">
      <template #actions><a :href="data.routes.orders" :class="secondaryButton"><ArrowLeftIcon class="mr-2 size-4" />Volver a OT</a></template>
    </PageHeading>

    <section v-if="data.mode === 'upload'" class="mx-auto max-w-3xl rounded-2xl border border-border bg-surface-raised p-6 shadow-sm">
      <div class="mb-5 flex items-start gap-4"><div class="rounded-xl bg-primary/10 p-3 text-primary"><ArrowUpTrayIcon class="size-7" /></div><div><h2 class="text-lg font-bold text-ink">Foto o PDF de la orden del taller</h2><p class="mt-1 text-sm text-ink-muted">Formatos admitidos: JPG, PNG y PDF. El original queda guardado para auditoría.</p></div></div>
      <form method="post" :action="data.routes.upload" enctype="multipart/form-data" class="space-y-5">
        <CsrfInput :csrf="data.csrf" />
        <input type="hidden" name="idempotency_key" :value="`ot-doc-${Date.now()}-${Math.random().toString(16).slice(2)}`" />
        <label class="block"><span class="mb-1 block text-sm font-semibold text-ink">Sucursal</span><select name="sucursal_id" required :class="fieldClass"><option value="">Seleccionar sucursal</option><option v-for="branch in data.branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option></select></label>
        <label class="block"><span class="mb-1 block text-sm font-semibold text-ink">Documento</span><input name="documento" type="file" required accept="image/jpeg,image/png,application/pdf" class="block min-h-12 w-full rounded-xl border border-dashed border-border-strong bg-surface px-4 py-3 text-sm text-ink file:mr-4 file:rounded-lg file:border-0 file:bg-primary file:px-4 file:py-2 file:font-semibold file:text-white hover:border-primary/50" /></label>
        <div class="rounded-xl bg-surface p-4 text-sm text-ink-muted"><strong class="text-ink">La IA no crea nada automáticamente.</strong> Primero vas a revisar patente/equipo, lectura, tareas y repuestos; después podrás elegir OT correctiva, preventiva o ambas.</div>
        <button type="submit" :class="primaryButton"><ArrowUpTrayIcon class="mr-2 size-4" />Subir y analizar</button>
      </form>
    </section>

    <div v-else class="space-y-5">
      <section class="grid gap-4 xl:grid-cols-[minmax(19rem,.85fr)_minmax(0,1.65fr)]">
        <article class="rounded-2xl border border-border bg-surface-raised p-5 shadow-sm">
          <div class="flex items-start justify-between gap-3"><div><p class="text-xs font-bold uppercase tracking-wide text-ink-muted">Documento original</p><h2 class="mt-1 font-bold text-ink">{{ data.import.originalName }}</h2></div><DocumentTextIcon class="size-7 text-primary" /></div>
          <dl class="mt-5 space-y-3 text-sm"><div class="flex justify-between gap-3"><dt class="text-ink-muted">Estado</dt><dd class="font-semibold text-ink">{{ data.import.status }}</dd></div><div class="flex justify-between gap-3"><dt class="text-ink-muted">Tipo</dt><dd class="font-semibold text-ink">{{ data.import.mimeType }}</dd></div></dl>
          <div v-if="data.import.error" class="mt-4 rounded-xl border border-warning/30 bg-warning/10 p-3 text-sm text-ink"><ExclamationTriangleIcon class="mr-1 inline size-4" />{{ data.import.error }}</div>
          <div class="mt-5 flex flex-wrap gap-2"><a :href="data.routes.download" target="_blank" :class="secondaryButton">Ver original</a><form v-if="!isConfirmed" method="post" :action="data.routes.reanalyze"><CsrfInput :csrf="data.csrf" /><button :class="secondaryButton"><ArrowPathIcon class="mr-2 size-4" />Reanalizar</button></form></div>
        </article>

        <article class="rounded-2xl border border-border bg-surface-raised p-5 shadow-sm">
          <h2 class="text-lg font-bold text-ink">Datos a confirmar</h2>
          <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <label class="rounded-xl bg-surface p-3"><span class="text-xs font-bold uppercase text-ink-muted">Equipo</span><select v-model.number="proposal.selectedEquipmentId" :disabled="isConfirmed || contextLoading" :class="`${fieldClass} mt-2`"><option :value="null">Seleccionar equipo</option><option v-for="equipment in data.equipmentOptions" :key="equipment.id" :value="equipment.id">{{ equipment.code }}{{ equipment.plate ? ` · ${equipment.plate}` : '' }}</option></select><span class="mt-1 block text-xs text-ink-muted">Patente IA: {{ analysis.plate || 'no detectada' }} · confianza {{ confidenceLabel(analysis.confidence?.plate) }}</span><span v-if="contextLoading" class="mt-1 block text-xs text-primary">Actualizando planes del equipo…</span></label>
            <label class="rounded-xl bg-surface p-3"><span class="text-xs font-bold uppercase text-ink-muted">Fecha del trabajo</span><input v-model="proposal.serviceDate" type="date" :disabled="isConfirmed" :class="`${fieldClass} mt-2`" /></label>
            <label class="rounded-xl bg-surface p-3"><span class="text-xs font-bold uppercase text-ink-muted">Lectura</span><div class="mt-2 grid grid-cols-[7rem_1fr] gap-2"><select v-model="proposal.readingType" :disabled="isConfirmed" :class="fieldClass"><option value="km">km</option><option value="horas">horas</option></select><input v-model="proposal.readingValue" inputmode="decimal" :disabled="isConfirmed" :class="fieldClass" /></div><span class="mt-1 block text-xs text-ink-muted">Detectada: {{ formatReading(analysis.readingValue) }} {{ analysis.readingType || '' }}</span><span v-if="selectedEquipment" class="mt-1 block text-xs text-ink-muted">Actual del equipo: {{ proposal.readingType === 'horas' ? `${formatReading(selectedEquipment.currentHours)} h` : `${formatReading(selectedEquipment.currentKm)} km` }}</span></label>
            <label class="rounded-xl bg-surface p-3"><span class="text-xs font-bold uppercase text-ink-muted">Taller/proveedor</span><input v-model="proposal.supplier" :disabled="isConfirmed" :class="`${fieldClass} mt-2`" /></label>
            <label class="rounded-xl bg-surface p-3 sm:col-span-2"><span class="text-xs font-bold uppercase text-ink-muted">Concepto / diagnóstico</span><input v-model="proposal.concept" :disabled="isConfirmed" :class="`${fieldClass} mt-2`" /></label>
          </div>
          <p v-if="contextError" class="mt-4 rounded-xl border border-danger/30 bg-danger/10 p-3 text-sm text-danger-strong">{{ contextError }}</p>
          <div v-if="readingRegression" class="mt-4 rounded-xl border border-warning/30 bg-warning/10 p-3 text-sm text-ink">
            <p class="font-semibold"><ExclamationTriangleIcon class="mr-1 inline size-4" />La lectura ingresada es menor que la lectura actual del equipo.</p>
            <label v-if="!isConfirmed" class="mt-2 flex items-start gap-2"><input v-model="proposal.confirmReadingRollback" type="checkbox" class="mt-1 size-4 rounded border-border-strong text-primary" /><span>Revisé la lectura y confirmo que deseo registrarla de esta manera.</span></label>
          </div>
          <p v-if="proposal.readingValue && !data.can?.registerReading" class="mt-3 text-sm text-warning">Tu perfil no tiene permiso para cargar lecturas; quitá la lectura o solicitá ese permiso antes de confirmar.</p>
        </article>
      </section>

      <section class="rounded-2xl border border-border bg-surface-raised p-5 shadow-sm">
        <div class="flex flex-wrap items-end justify-between gap-3"><div><p class="text-xs font-bold uppercase tracking-wide text-ink-muted">Trabajos interpretados</p><h2 class="mt-1 text-lg font-bold text-ink">Revisá qué fue correctivo y qué fue preventivo</h2></div><div class="text-sm text-ink-muted"><strong class="text-ink">{{ correctiveWorks.length }}</strong> correctivos · <strong class="text-ink">{{ preventiveWorks.length }}</strong> preventivos</div></div>
        <div class="mt-4 overflow-x-auto"><table class="min-w-full text-left text-sm"><thead><tr class="border-b border-border text-xs uppercase tracking-wide text-ink-muted"><th class="px-2 py-3">Incluir</th><th class="px-2 py-3">Trabajo</th><th class="px-2 py-3">Clasificación</th><th class="px-2 py-3">Confianza</th></tr></thead><tbody><tr v-for="(item, index) in proposal.works" :key="index" class="border-b border-border/70 align-top"><td class="px-2 py-3"><input v-model="item.included" :disabled="isConfirmed" type="checkbox" class="size-4 rounded border-border-strong text-primary" /></td><td class="px-2 py-3"><input v-model="item.description" :disabled="isConfirmed" :class="fieldClass" /><p v-if="item.source_text && item.source_text !== item.description" class="mt-1 max-w-xl text-xs text-ink-muted">Original: {{ item.source_text }}</p></td><td class="px-2 py-3"><select v-model="item.classification" :disabled="isConfirmed" :class="fieldClass"><option value="correctivo">Correctivo</option><option value="preventivo">Preventivo</option><option value="revisar">Revisar</option></select></td><td class="px-2 py-3"><span :class="item.confidence >= .85 ? 'font-semibold text-success' : item.confidence >= .6 ? 'font-semibold text-warning' : 'font-semibold text-danger'">{{ Math.round((item.confidence || 0) * 100) }}%</span></td></tr></tbody></table></div>
      </section>

      <section class="grid gap-4 lg:grid-cols-2">
        <article class="rounded-2xl border border-border bg-surface-raised p-5 shadow-sm"><h2 class="font-bold text-ink">Repuestos y consumibles detectados</h2><ul class="mt-3 space-y-2 text-sm"><li v-for="(item, index) in proposal.materials" :key="index" class="flex justify-between gap-4 rounded-lg bg-surface px-3 py-2"><span>{{ item.description }}</span><strong>{{ item.quantity ?? '—' }} {{ item.unit || '' }}</strong></li><li v-if="!proposal.materials?.length" class="text-ink-muted">No se detectaron materiales.</li></ul></article>
        <article class="rounded-2xl border border-border bg-surface-raised p-5 shadow-sm">
          <h2 class="font-bold text-ink">Plan preventivo a registrar</h2>
          <select v-model.number="proposal.selectedPlanId" :disabled="isConfirmed || contextLoading" :class="`${fieldClass} mt-3`"><option :value="null">No seleccionar plan</option><option v-for="plan in proposal.preventivePlans" :key="plan.id" :value="plan.id">{{ plan.servicio_nombre || `Plan #${plan.id}` }} · coincidencia {{ plan.matchScore || 0 }}%</option></select>
          <div v-if="selectedPlan" class="mt-3 text-sm text-ink-muted">
            <p><strong class="text-ink">Coincidencia:</strong> {{ selectedPlan.matchScore || 0 }}% · {{ selectedPlan.evidencedTaskCount || 0 }} tareas evidenciadas</p>
            <ul v-if="selectedPlan.taskMatches?.length" class="mt-3 space-y-2">
              <li v-for="task in selectedPlan.taskMatches" :key="task.taskId" class="rounded-lg bg-surface px-3 py-2">
                <div class="flex items-start justify-between gap-3"><span class="font-semibold text-ink">{{ task.taskName }}</span><span :class="task.evidenced ? 'text-success' : 'text-warning'">{{ task.evidenced ? 'Evidenciada' : 'Sin evidencia' }}</span></div>
                <p v-if="task.matchedDescription" class="mt-1 text-xs">Documento: {{ task.matchedDescription }}</p>
                <p v-if="task.required && !task.evidenced" class="mt-1 text-xs font-semibold text-warning">Tarea obligatoria del plan.</p>
              </li>
            </ul>
            <div v-if="partialPreventive && !isConfirmed" class="mt-3 rounded-xl border border-warning/30 bg-warning/10 p-3 text-sm text-ink">
              <p class="font-semibold">El documento no evidencia todas las tareas obligatorias del plan.</p>
              <label class="mt-2 flex items-start gap-2"><input v-model="proposal.confirmPartialPreventive" type="checkbox" class="mt-1 size-4 rounded border-border-strong text-primary" /><span>Confirmo que corresponde registrar igualmente esta realización preventiva parcial. Las tareas sin evidencia quedarán pendientes.</span></label>
            </div>
          </div>
          <p v-if="!data.can?.closePreventive" class="mt-3 text-sm text-warning">Tu perfil puede revisar la propuesta, pero necesita permiso de cierre de OT para registrar un preventivo ya realizado.</p>
        </article>
      </section>

      <section class="rounded-2xl border border-primary/20 bg-primary/5 p-5">
        <h2 class="text-lg font-bold text-ink">Crear desde este documento</h2>
        <p v-if="isConfirmed" class="mt-1 text-sm font-semibold text-success">Este documento ya fue confirmado. Las acciones de creación quedaron bloqueadas para evitar duplicados.</p>
        <p v-else class="mt-1 text-sm text-ink-muted">Nada se graba hasta que elijas una de estas acciones. La misma lectura se registra una sola vez aunque se creen ambas OT.</p>
        <form v-if="!isConfirmed" method="post" :action="data.routes.confirm" class="mt-4 flex flex-wrap gap-2"><CsrfInput :csrf="data.csrf" /><input type="hidden" name="proposal_json" :value="JSON.stringify(proposal)" /><button v-for="action in ['corrective','preventive','both']" :key="action" type="submit" name="action" :value="action" :disabled="contextLoading || (action === 'corrective' ? !canCorrective : action === 'preventive' ? !canPreventive : !canBoth)" :class="primaryButton" @click="submitConfirmation($event, action)">{{ confirmLabel(action) }}</button></form>
        <a :href="data.routes.newImport" :class="`${secondaryButton} mt-4`">Importar otro documento</a>
      </section>
    </div>
  </div>
</template>
