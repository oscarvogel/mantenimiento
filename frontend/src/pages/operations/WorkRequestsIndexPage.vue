<script setup>
import { computed, reactive } from 'vue'
import { CheckCircleIcon, ClockIcon, ExclamationTriangleIcon, FunnelIcon, LinkIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import CsrfInput from './components/CsrfInput.vue'
import EmptyState from './components/EmptyState.vue'
import PageHeading from './components/PageHeading.vue'
import PaginationBar from './components/PaginationBar.vue'
import StatusBadge from './components/StatusBadge.vue'
import { fieldClass, primaryButton, secondaryButton } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })
const reviewForms = reactive({})
const hasFilters = computed(() => Boolean(props.data.filters?.q || props.data.filters?.status))

const formFor = (request) => {
  if (!reviewForms[request.id]) reviewForms[request.id] = { status: '', reason: '', groupId: '' }
  return reviewForms[request.id]
}
const isOpen = (request) => Boolean(formFor(request).status)
const openReview = (request, status) => { formFor(request).status = status; formFor(request).reason = ''; formFor(request).groupId = '' }
const closeReview = (request) => { formFor(request).status = '' }
const reviewLabel = (status) => ({ APROBADA: 'Aprobar', RECHAZADA: 'Rechazar', POSTERGADA: 'Postergar', AGRUPADA: 'Agrupar' })[status] ?? status
</script>

<template>
  <div>
    <PageHeading eyebrow="Recepción y triage" title="Solicitudes de mantenimiento" description="Revisá fallas y necesidades reportadas antes de convertirlas en trabajo del taller.">
      <template #actions><a :href="data.routes.index" :class="`${secondaryButton} w-full justify-center sm:w-auto`">Actualizar</a></template>
    </PageHeading>

    <form method="get" :action="data.routes.index" class="mb-6 grid gap-3 rounded-xl border border-border bg-surface-raised p-4 sm:grid-cols-[minmax(0,1fr)_14rem_auto] sm:items-end">
      <label class="grid gap-1.5 text-sm font-semibold text-ink">Buscar <input name="q" type="search" :value="data.filters.q" placeholder="Descripción, equipo o patente" :class="fieldClass" /></label>
      <label class="grid gap-1.5 text-sm font-semibold text-ink">Estado <select name="estado" :value="data.filters.status" :class="fieldClass"><option value="">Todos</option><option value="PENDIENTE">Pendientes</option><option value="POSTERGADA">Postergadas</option><option value="APROBADA">Aprobadas</option><option value="AGRUPADA">Agrupadas</option><option value="RECHAZADA">Rechazadas</option></select></label>
      <div class="flex gap-2"><button type="submit" :class="`${primaryButton} justify-center`"><FunnelIcon class="mr-2 size-4" aria-hidden="true" />Filtrar</button><a v-if="hasFilters" :href="data.routes.index" :class="secondaryButton">Limpiar</a></div>
    </form>

    <section aria-label="Resumen de solicitudes" class="mb-6 grid gap-3 sm:grid-cols-3">
      <article class="rounded-xl border border-warning/30 bg-warning-subtle p-4"><ClockIcon class="size-5 text-warning-strong" aria-hidden="true" /><p class="mt-2 text-sm font-semibold text-ink">Pendientes de revisión</p><p class="mt-1 text-2xl font-bold text-ink"><template>{{ data.items.filter((item) => ['PENDIENTE', 'POSTERGADA'].includes(item.status)).length }}</template></p></article>
      <article class="rounded-xl border border-success/30 bg-success-subtle p-4"><CheckCircleIcon class="size-5 text-success-strong" aria-hidden="true" /><p class="mt-2 text-sm font-semibold text-ink">Aprobadas</p><p class="mt-1 text-2xl font-bold text-ink">{{ data.items.filter((item) => item.status === 'APROBADA').length }}</p></article>
      <article class="rounded-xl border border-border bg-surface-raised p-4"><ExclamationTriangleIcon class="size-5 text-ink-subtle" aria-hidden="true" /><p class="mt-2 text-sm font-semibold text-ink">Total visible</p><p class="mt-1 text-2xl font-bold text-ink">{{ data.total }}</p></article>
    </section>

    <section class="overflow-hidden rounded-xl border border-border bg-surface-raised" aria-labelledby="requests-title">
      <header class="flex flex-wrap items-center justify-between gap-3 border-b border-border-subtle px-5 py-4 sm:px-6"><div><h2 id="requests-title" class="text-lg font-bold text-ink">Bandeja de solicitudes</h2><p class="mt-1 text-sm text-ink-muted">{{ data.total }} {{ data.total === 1 ? 'solicitud visible' : 'solicitudes visibles' }}</p></div><span v-if="data.canReview" class="rounded-full bg-primary-subtle px-3 py-1 text-xs font-semibold text-primary">Modo revisión</span></header>
      <EmptyState v-if="data.items.length === 0" title="No hay solicitudes para mostrar" description="Cuando alguien reporte una falla, aparecerá acá con su equipo y sucursal." />
      <div v-else class="divide-y divide-border-subtle">
        <article v-for="request in data.items" :key="request.id" class="p-5 sm:p-6">
          <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><h3 class="font-bold text-ink">#{{ request.id }} · {{ request.equipmentCode }}</h3><StatusBadge :status="request.status" /><span class="text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ request.priority }}</span></div><p class="mt-1 text-sm text-ink-muted">{{ request.branchName }} · Reportó {{ request.reportedBy }} · {{ request.reportedAt }}</p><p class="mt-3 whitespace-pre-line text-sm leading-6 text-ink">{{ request.description }}</p><p v-if="request.resolutionReason" class="mt-2 rounded-lg bg-surface-subtle px-3 py-2 text-sm text-ink-muted">Motivo: {{ request.resolutionReason }}</p><p v-if="request.groupRequestId" class="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-primary"><LinkIcon class="size-4" aria-hidden="true" />Agrupada en #{{ request.groupRequestId }}</p></div>
            <div v-if="data.canReview && ['PENDIENTE', 'POSTERGADA'].includes(request.status)" class="flex shrink-0 flex-wrap gap-2 lg:max-w-xs lg:justify-end"><button type="button" :class="primaryButton" @click="openReview(request, 'APROBADA')">Aprobar</button><button type="button" :class="secondaryButton" @click="openReview(request, 'POSTERGADA')">Postergar</button><button type="button" :class="secondaryButton" @click="openReview(request, 'RECHAZADA')">Rechazar</button><button type="button" :class="secondaryButton" @click="openReview(request, 'AGRUPADA')">Agrupar</button></div>
          </div>
          <form v-if="isOpen(request)" method="post" :action="`${data.routes.index}/${request.id}/revisar`" class="mt-5 grid gap-3 rounded-xl border border-border bg-surface-subtle p-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
            <CsrfInput :csrf="data.csrf" /><input type="hidden" name="estado" :value="formFor(request).status" />
            <label class="grid gap-1.5 text-sm font-semibold text-ink">{{ reviewLabel(formFor(request).status) }}: motivo <textarea v-model="formFor(request).reason" name="motivo" rows="2" minlength="5" maxlength="2000" :required="['RECHAZADA', 'POSTERGADA', 'AGRUPADA'].includes(formFor(request).status)" placeholder="Dejá una explicación para el equipo" :class="fieldClass"></textarea></label>
            <label v-if="formFor(request).status === 'AGRUPADA'" class="grid gap-1.5 text-sm font-semibold text-ink">Solicitud relacionada <select v-model="formFor(request).groupId" name="agrupada_en_id" required :class="fieldClass"><option value="">Elegí una solicitud</option><option v-for="candidate in data.items.filter((item) => item.id !== request.id && ['PENDIENTE', 'APROBADA'].includes(item.status) && item.branchName === request.branchName)" :key="candidate.id" :value="candidate.id">#{{ candidate.id }} · {{ candidate.equipmentCode }}</option></select></label>
            <div class="flex flex-wrap justify-end gap-2 sm:col-span-2"><button type="button" :class="secondaryButton" @click="closeReview(request)"><XMarkIcon class="mr-1 size-4" aria-hidden="true" />Cancelar</button><button type="submit" :class="primaryButton">Confirmar {{ reviewLabel(formFor(request).status).toLowerCase() }}</button></div>
          </form>
        </article>
      </div>
      <PaginationBar :pagination="{ ...data.pagination, perPageOptions: [10, 25, 50] }" />
    </section>
  </div>
</template>
