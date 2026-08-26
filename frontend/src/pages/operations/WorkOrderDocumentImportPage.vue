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
const pendingAction = ref(null)
const submitting = ref(false)
const confirmForm = ref(null)
const analysis = computed(() => props.data.import?.analysis ?? proposal.analysis ?? {})
const works = computed(() => proposal.works ?? [])
const correctiveWorks = computed(() => works.value.filter((item) => item.included !== false && item.classification === 'correctivo'))
const preventiveWorks = computed(() => works.value.filter((item) => item.included !== false && item.classification === 'preventivo'))
const isConfirmed = computed(() => props.data.import?.status === 'CONFIRMADO')
const selectedEquipment = computed(() => (props.data.equipmentOptions ?? []).find((item) => Number(item.id) === Number(proposal.selectedEquipmentId)) ?? null)
const selectedPlan = computed(() => (proposal.preventivePlans ?? []).find((item) => Number(item.id) === Number(proposal.selectedPlanId)) ?? null)
const possibleDuplicates = computed(() => proposal.possibleDuplicates ?? [])
const duplicateConfirmed = computed(() => possibleDuplicates.value.length === 0 || proposal.confirmPossibleDuplicate === true)
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
const canCorrective = computed(() => !isConfirmed.value && duplicateConfirmed.value && readingPermissionOk.value && readingConfirmed.value && Number(proposal.selectedEquipmentId || 0) > 0 && correctiveWorks.value.length > 0)
const canPreventive = computed(() => !isConfirmed.value && duplicateConfirmed.value && readingPermissionOk.value && readingConfirmed.value && partialConfirmed.value && props.data.can?.closePreventive && Number(proposal.selectedEquipmentId || 0) > 0 && Number(proposal.selectedPlanId || 0) > 0 && preventiveWorks.value.length > 0)
const canBoth = computed(() => canCorrective.value && canPreventive.value)
const confidenceLabel = (value) => {
  const number = Number(value ?? 0)
  if (number >= 0.85) return 'Alta'
  if (number >= 0.6) return 'Media'
  return 'Revisar'
}
const formatReading = (value) => value === null || value === undefined || value === '' ? 'No detectada' : Number(value).toLocaleString('es-AR')
const parseMoney = (value) => {
  if (value === null || value === undefined || value === '') return null
  const number = Number(String(value).replace(',', '.'))
  return Number.isFinite(number) && number >= 0 ? number : null
}
const formatMoney = (value) => {
  const number = parseMoney(value)
  if (number === null) return 'No detectado'
  return number.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
const confirmLabel = (action) => ({ corrective: 'Crear OT correctiva', preventive: 'Crear OT preventiva', both: 'Crear ambas OT' }[action])
const confirmationTitle = computed(() => pendingAction.value ? confirmLabel(pendingAction.value) : '')
const confirmationDescription = computed(() => pendingAction.value === 'both'
  ? 'Se crearán dos órdenes vinculadas al mismo documento. La lectura se registrará una sola vez y el importe no se duplicará.'
  : pendingAction.value === 'preventive'
    ? 'Se registrará la realización preventiva con el plan seleccionado y se recalculará el próximo mantenimiento.'
    : 'Se creará una OT correctiva finalizada con los trabajos revisados del documento.')
const allocationValid = computed(() => {
  if (pendingAction.value !== 'both') return true
  const total = parseMoney(proposal.totalAmount)
  if (total === null) return true
  const corrective = parseMoney(proposal.correctiveAmount)
  const preventive = parseMoney(proposal.preventiveAmount)
  return corrective !== null && preventive !== null && Math.abs((corrective + preventive) - total) < 0.01
})
const allocationError = computed(() => {
  if (pendingAction.value !== 'both' || allocationValid.value || parseMoney(proposal.totalAmount) === null) return ''
  return 'Distribuí el importe entre ambas OT. La suma debe coincidir con el total del documento.'
})
const openConfirmation = (action) => {
  if (submitting.value) return
  pendingAction.value = action
  if (action === 'corrective') proposal.correctiveAmount = proposal.totalAmount ?? null
  if (action === 'preventive') proposal.preventiveAmount = proposal.totalAmount ?? null
}
const closeConfirmation = () => {
  if (submitting.value) return
  pendingAction.value = null
}
const confirmCreation = () => {
  if (!pendingAction.value || submitting.value || !confirmForm.value || !allocationValid.value) return
  submitting.value = true
  confirmForm.value.requestSubmit()
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
        <div class="rounded-xl bg-surface p-4 text-sm text-ink-muted"><strong class="text-ink">La IA no crea nada automáticamente.</strong> Primero vas a revisar patente/equipo, lectura, importe, tareas y repuestos; después podrás elegir OT correctiva, preventiva o ambas.</div>
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
            <label class="rounded-xl bg-surface p-3 sm:col-span-2 lg:col-span-1"><span class="text-xs font-bold uppercase text-ink-muted">Importe total</span><div class="mt-2 grid grid-cols-[6rem_1fr] gap-2"><input v-model="proposal.currency" maxlength="3" :disabled="isConfirmed" :class="fieldClass" placeholder="ARS" /><input v-model="proposal.totalAmount" inputmode="decimal" :disabled="isConfirmed" :class="fieldClass" placeholder="0,00" /></div><span class="mt-1 block text-xs text-ink-muted">Detectado: {{ analysis.totalAmount == null ? 'no detectado' : `${analysis.currency || 'ARS'} ${formatMoney(analysis.totalAmount)}` }} · confianza {{ confidenceLabel(analysis.confidence?.total_amount) }}</span></label>
          </div>
          <p v-if="contextError" class="mt-4 rounded-xl border border-danger/30 bg-danger/10 p-3 text-sm text-danger-strong">{{ contextError }}</p>
          <div v-if="readingRegression" class="mt-4 rounded-xl border border-warning/30 bg-warning/10 p-3 text-sm text-ink">
            <p class="font-semibold"><ExclamationTriangleIcon class="mr-1 inline size-4" />La lectura ingresada es menor que la lectura actual del equipo.</p>
            <label v-if="!isConfirmed" class="mt-2 flex items-start gap-2"><input v-model="proposal.confirmReadingRollback" type="checkbox" class="mt-1 size-4 rounded border-border-strong text-primary" /><span>Revisé la lectura y confirmo que deseo registrarla de esta manera.</span></label>
          </div>
          <p v-if="proposal.readingValue && !data.can?.registerReading" class="mt-3 text-sm text-warning">Tu perfil no tiene permiso para cargar lecturas; quitá la lectura o solicitá ese permiso antes de confirmar.</p>
        </article>
      </section>

      <section v-if="possibleDuplicates.length" class="rounded-2xl border border-warning/40 bg-warning/10 p-5">
        <div class="flex items-start gap-3"><ExclamationTriangleIcon class="mt-0.5 size-6 shrink-0 text-warning" /><div class="min-w-0"><h2 class="font-bold text-ink">Posible documento duplicado</h2><p class="mt-1 text-sm text-ink-muted">Encontramos una importación anterior muy parecida. Revisala antes de crear otra OT.</p></div></div>
        <ul class="mt-4 space-y-2 text-sm"><li v-for="candidate in possibleDuplicates" :key="candidate.importId" class="rounded-xl border border-warning/20 bg-surface-raised p-3"><div class="flex flex-wrap items-center justify-between gap-2"><span class="font-semibold text-ink">Importación #{{ candidate.importId }} · {{ candidate.status }}</span><a :href="`${data.routes.newImport}/${candidate.importId}`" target="_blank" class="font-semibold text-primary hover:underline">Ver importación</a></div><p class="mt-1 text-xs text-ink-muted">Coincidencias: {{ candidate.reasons?.join(', ') || 'datos similares' }}</p></li></ul>
        <label v-if="!isConfirmed" class="mt-4 flex items-start gap-2 text-sm text-ink"><input v-model="proposal.confirmPossibleDuplicate" type="checkbox" class="mt-1 size-4 rounded border-border-strong text-primary" /><span>Revisé la importación anterior y confirmo que este documento corresponde a un trabajo distinto y debe continuar.</span></label>
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
        <p v-if="possibleDuplicates.length && !duplicateConfirmed && !isConfirmed" class="mt-2 text-sm font-semibold text-warning">Confirmá primero la revisión del posible duplicado.</p>
        <form v-if="!isConfirmed" ref="confirmForm" method="post" :action="data.routes.confirm" class="mt-4 flex flex-wrap gap-2" @submit="submitting = true"><CsrfInput :csrf="data.csrf" /><input type="hidden" name="proposal_json" :value="JSON.stringify(proposal)" /><input type="hidden" name="action" :value="pendingAction || ''" /><button v-for="action in ['corrective','preventive','both']" :key="action" type="button" :disabled="contextLoading || submitting || (action === 'corrective' ? !canCorrective : action === 'preventive' ? !canPreventive : !canBoth)" :class="primaryButton" @click="openConfirmation(action)">{{ confirmLabel(action) }}</button></form>
        <a :href="data.routes.newImport" :class="`${secondaryButton} mt-4`">Importar otro documento</a>
      </section>
    </div>

    <div v-if="pendingAction" class="fixed inset-0 z-50 flex items-center justify-center bg-black/45 p-4" role="presentation" @click.self="closeConfirmation" @keydown.esc="closeConfirmation">
      <section role="dialog" aria-modal="true" aria-labelledby="work-order-confirm-title" class="w-full max-w-lg rounded-2xl border border-border bg-surface-raised p-6 shadow-2xl">
        <p class="text-xs font-bold uppercase tracking-wide text-primary">Confirmar creación</p>
        <h2 id="work-order-confirm-title" class="mt-1 text-xl font-bold text-ink">{{ confirmationTitle }}</h2>
        <p class="mt-2 text-sm text-ink-muted">{{ confirmationDescription }}</p>

        <dl class="mt-5 divide-y divide-border rounded-xl border border-border bg-surface px-4 text-sm">
          <div class="flex items-center justify-between gap-4 py-3"><dt class="text-ink-muted">Equipo</dt><dd class="text-right font-semibold text-ink">{{ selectedEquipment?.code || 'Sin equipo' }}{{ selectedEquipment?.plate ? ` · ${selectedEquipment.plate}` : '' }}</dd></div>
          <div class="flex items-center justify-between gap-4 py-3"><dt class="text-ink-muted">Fecha del trabajo</dt><dd class="text-right font-semibold text-ink">{{ proposal.serviceDate || 'Sin fecha' }}</dd></div>
          <div v-if="proposal.readingValue !== null && proposal.readingValue !== undefined && proposal.readingValue !== ''" class="flex items-center justify-between gap-4 py-3"><dt class="text-ink-muted">Lectura a registrar</dt><dd class="text-right font-semibold text-ink">{{ formatReading(proposal.readingValue) }} {{ proposal.readingType === 'horas' ? 'h' : 'km' }}</dd></div>
          <div v-if="parseMoney(proposal.totalAmount) !== null" class="flex items-center justify-between gap-4 py-3"><dt class="text-ink-muted">Importe del documento</dt><dd class="text-right font-semibold text-ink">{{ proposal.currency || 'ARS' }} {{ formatMoney(proposal.totalAmount) }}</dd></div>
          <div v-if="pendingAction !== 'corrective'" class="flex items-center justify-between gap-4 py-3"><dt class="text-ink-muted">Plan preventivo</dt><dd class="text-right font-semibold text-ink">{{ selectedPlan?.servicio_nombre || `Plan #${proposal.selectedPlanId}` }}</dd></div>
          <div v-if="pendingAction !== 'preventive'" class="flex items-center justify-between gap-4 py-3"><dt class="text-ink-muted">Trabajos correctivos</dt><dd class="font-semibold text-ink">{{ correctiveWorks.length }}</dd></div>
          <div v-if="pendingAction !== 'corrective'" class="flex items-center justify-between gap-4 py-3"><dt class="text-ink-muted">Trabajos preventivos</dt><dd class="font-semibold text-ink">{{ preventiveWorks.length }}</dd></div>
        </dl>

        <div v-if="pendingAction === 'both' && parseMoney(proposal.totalAmount) !== null" class="mt-4 rounded-xl border border-border bg-surface p-4">
          <p class="text-sm font-semibold text-ink">Distribución del importe</p>
          <p class="mt-1 text-xs text-ink-muted">Para no duplicar el costo, indicá cuánto corresponde a cada OT.</p>
          <div class="mt-3 grid gap-3 sm:grid-cols-2">
            <label><span class="mb-1 block text-xs font-bold uppercase text-ink-muted">Correctiva</span><input v-model="proposal.correctiveAmount" inputmode="decimal" :class="fieldClass" /></label>
            <label><span class="mb-1 block text-xs font-bold uppercase text-ink-muted">Preventiva</span><input v-model="proposal.preventiveAmount" inputmode="decimal" :class="fieldClass" /></label>
          </div>
          <p v-if="allocationError" class="mt-2 text-sm font-semibold text-warning">{{ allocationError }}</p>
        </div>

        <div v-if="partialPreventive && pendingAction !== 'corrective'" class="mt-4 rounded-xl border border-warning/30 bg-warning/10 p-3 text-sm text-ink"><ExclamationTriangleIcon class="mr-1 inline size-4" />La realización preventiva es parcial; las tareas sin evidencia quedarán pendientes.</div>

        <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" :disabled="submitting" :class="secondaryButton" @click="closeConfirmation">Cancelar</button>
          <button type="button" :disabled="submitting || !allocationValid" :class="primaryButton" @click="confirmCreation">{{ submitting ? 'Creando OT…' : 'Confirmar y crear' }}</button>
        </div>
      </section>
    </div>
  </div>
</template>