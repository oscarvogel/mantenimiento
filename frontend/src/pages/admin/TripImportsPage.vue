<script setup>
import { computed, ref } from 'vue'
import {
  ArrowUpTrayIcon,
  CheckCircleIcon,
  ExclamationTriangleIcon,
  InformationCircleIcon,
  MapPinIcon,
  XCircleIcon,
} from '@heroicons/vue/24/outline'
import AdminPageHeading from './components/AdminPageHeading.vue'
import CsrfField from './components/CsrfField.vue'
import PanelCard from '../operations/components/PanelCard.vue'
import StatusBadge from '../operations/components/StatusBadge.vue'
import { fieldClass, primaryButton, secondaryButton } from '../operations/helpers.js'

const props = defineProps({ data: { type: Object, required: true } })

const companyId = ref('')
const hasFile = ref(false)
const batch = computed(() => props.data.batch)
const summary = computed(() => batch.value?.summary ?? {})
const counts = computed(() => summary.value.counts ?? {})
const classification = computed(() => summary.value.classification ?? {})
const requirements = computed(() => summary.value.branch_requirements ?? {})
const confirmed = computed(() => batch.value?.estado === 'CONFIRMADO')
const cancelled = computed(() => batch.value?.estado === 'CANCELADO')
const pending = computed(() => !confirmed.value && !cancelled.value)
const branchSelection = ref({
  AR: summary.value.branch_map?.AR ?? '',
  BR: summary.value.branch_map?.BR ?? '',
  UNCLASSIFIED: summary.value.branch_map?.UNCLASSIFIED ?? '',
})

const countries = [
  { key: 'AR', label: 'Argentina', selectName: 'sucursal_ar' },
  { key: 'BR', label: 'Brasil', selectName: 'sucursal_br' },
  { key: 'UNCLASSIFIED', label: 'Sin clasificar', selectName: 'sucursal_sin_clasificar' },
]
const requiredCountries = computed(() => countries.filter((country) => requirements.value[country.key]))
const blockingIssues = computed(() => (summary.value.issues_page ?? []).filter((issue) => issue.severity === 'ERROR'))
const fmt = (value) => Number(value ?? 0).toLocaleString('es-AR')

const metrics = computed(() => [
  { label: 'Viajes nuevos', value: counts.value.trips_new, tone: 'text-ink' },
  { label: 'Ya existentes', value: counts.value.trips_existing, tone: 'text-ink-muted' },
  { label: 'Filas hijas', value: counts.value.children_valid, tone: 'text-ink' },
  { label: 'Advertencias', value: counts.value.warnings, tone: 'text-warning-strong' },
  { label: 'Errores bloqueantes', value: counts.value.blocking_errors, tone: Number(counts.value.blocking_errors) > 0 ? 'text-danger-strong' : 'text-ink-muted' },
])

const issueGroups = computed(() => {
  const groups = new Map()
  const push = (key, label, description, issue) => {
    if (!groups.has(key)) groups.set(key, { key, label, description, items: [] })
    groups.get(key).items.push(issue)
  }
  for (const issue of summary.value.issues_page ?? []) {
    if (issue.field === 'fecha_regreso') {
      push('fechas', 'Fechas de regreso incoherentes', 'La fecha de regreso es anterior a la salida. El viaje se conserva tal como estaba en el sistema anterior.', issue)
    } else if (issue.field === 'viaje') {
      push('huerfanas', 'Filas hijas huérfanas', 'No tienen un viaje identificable en el paquete ni en el destino. Se omiten.', issue)
    } else if (issue.severity === 'ERROR') {
      push('errores', 'Errores bloqueantes', 'Impiden confirmar hasta corregir el paquete.', issue)
    } else {
      push('otros', 'Otros datos con advertencia', 'Valores recortados o inválidos que se conservan o se omiten según el campo.', issue)
    }
  }
  return [...groups.values()]
})

const decision = computed(() => {
  if (confirmed.value) {
    const result = summary.value.result ?? {}
    return {
      tone: 'success',
      icon: CheckCircleIcon,
      kicker: 'Importación confirmada',
      title: 'Los viajes ya están guardados',
      text: `${fmt(result.inserted)} viajes nuevos, ${fmt(result.skipped)} existentes omitidos, ${fmt(result.children_inserted)} filas hijas guardadas y ${fmt(result.orphans)} huérfanas omitidas.`,
    }
  }
  if (cancelled.value) {
    return {
      tone: 'neutral',
      icon: XCircleIcon,
      kicker: 'Importación cancelada',
      title: 'No se guardaron viajes',
      text: 'Podés subir el paquete de nuevo cuando quieras. El archivo original ya no se conserva.',
    }
  }
  if (Number(counts.value.blocking_errors) > 0) {
    return {
      tone: 'danger',
      icon: XCircleIcon,
      kicker: 'Revisión necesaria',
      title: 'Hay errores bloqueantes',
      text: 'Corregí el paquete y volvé a subirlo. No se puede confirmar con errores.',
    }
  }
  if (!summary.value.can_confirm) {
    return {
      tone: 'warning',
      icon: ExclamationTriangleIcon,
      kicker: 'Falta un paso',
      title: 'Asigná las sucursales',
      text: 'Elegí una sucursal para cada país con viajes nuevos y guardá para habilitar la confirmación.',
    }
  }
  return {
    tone: 'success',
    icon: CheckCircleIcon,
    kicker: 'Todo listo',
    title: 'Listo para confirmar',
    text: 'Revisaste la clasificación y las advertencias. La importación se ejecuta en una sola transacción.',
  }
})

const decisionCard = { success: 'border-success/30 bg-success-subtle', warning: 'border-warning/40 bg-warning-subtle', danger: 'border-danger/30 bg-danger-subtle', neutral: 'border-border bg-surface-muted' }
const decisionKicker = { success: 'text-success-strong', warning: 'text-warning-strong', danger: 'text-danger-strong', neutral: 'text-ink-muted' }
const decisionIcon = { success: 'bg-success/15 text-success', warning: 'bg-warning/15 text-warning', danger: 'bg-danger/15 text-danger', neutral: 'bg-surface text-ink-subtle' }
</script>

<template>
  <main class="space-y-6">
    <nav class="flex flex-wrap gap-4 text-sm font-semibold text-ink-muted" aria-label="Volver">
      <a :href="data.actions.superadmin" class="text-primary hover:text-primary-hover">Administración global</a>
      <a :href="data.actions.portal" class="text-primary hover:text-primary-hover">Portal de módulos</a>
    </nav>
    <AdminPageHeading
      eyebrow="Administración global"
      title="Importar viajes"
      description="Subí el paquete ZIP generado desde el backup legacy. Primero vas a ver qué se va a importar y qué se va a omitir; nada se guarda hasta confirmar."
    />
    <p v-if="data.flash?.error" role="alert" class="rounded-xl border border-danger/30 bg-danger-subtle p-4 text-danger-strong">{{ data.flash.error }}</p>
    <p v-if="data.flash?.success" role="status" class="rounded-xl border border-success/30 bg-success-subtle p-4 text-success-strong">{{ data.flash.success }}</p>

    <section v-if="data.mode === 'form'" class="mx-auto max-w-3xl rounded-2xl border border-border bg-surface-raised p-6 shadow-sm">
      <div class="mb-5 flex items-start gap-4">
        <span class="rounded-xl bg-primary/10 p-3 text-primary"><ArrowUpTrayIcon class="size-7" aria-hidden="true" /></span>
        <div>
          <h2 class="text-lg font-bold text-ink">1. Elegí la empresa y el paquete</h2>
          <p class="mt-1 text-sm text-ink-muted">El paquete debe incluir <span class="font-mono text-xs">manifest.json</span> y las tablas del agregado de Viajes.</p>
        </div>
      </div>
      <form method="post" :action="data.actions.preview" enctype="multipart/form-data" class="space-y-5">
        <CsrfField :csrf="data.csrf" />
        <label class="block">
          <span class="mb-1.5 block text-sm font-semibold text-ink">Empresa que recibirá los viajes</span>
          <select v-model="companyId" name="empresa_id" required :class="fieldClass">
            <option disabled value="">Seleccioná una empresa</option>
            <option v-for="company in data.companies" :key="company.id" :value="company.id">{{ company.name }}{{ company.cuit ? ` · ${company.cuit}` : '' }}</option>
          </select>
          <span class="mt-1.5 block text-xs text-ink-muted">El CUIT del paquete debe coincidir con el de la empresa.</span>
        </label>
        <label class="block">
          <span class="mb-1.5 block text-sm font-semibold text-ink">Paquete de viajes (.zip)</span>
          <input name="archivo" type="file" accept=".zip,application/zip" required :class="fieldClass" @change="hasFile = Boolean($event.target.files?.length)" />
          <span class="mt-1.5 block text-xs text-ink-muted">Hasta {{ data.maxSizeMb }} MB. Después de validarlo vas a asignar la sucursal de Argentina, la de Brasil y la de los viajes sin clasificar.</span>
        </label>
        <div class="rounded-xl border border-border bg-surface p-4 text-sm text-ink-muted">
          <strong class="text-ink">Nada se guarda en este paso.</strong> Primero se valida el manifiesto, se clasifican los viajes por evidencia y se muestran las advertencias.
        </div>
        <button type="submit" :disabled="!companyId || !hasFile" :class="primaryButton"><ArrowUpTrayIcon class="mr-2 size-5" aria-hidden="true" />Validar paquete</button>
      </form>
    </section>

    <template v-else>
      <section id="trip-decision" :class="['rounded-2xl border p-5 shadow-sm sm:p-6', decisionCard[decision.tone]]">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div class="flex min-w-0 items-start gap-4">
            <span :class="['flex size-12 shrink-0 items-center justify-center rounded-2xl', decisionIcon[decision.tone]]"><component :is="decision.icon" class="size-7" aria-hidden="true" /></span>
            <div class="min-w-0">
              <p :class="['text-xs font-bold uppercase tracking-wide', decisionKicker[decision.tone]]">{{ decision.kicker }}</p>
              <h2 class="mt-1 text-xl font-bold text-ink">{{ decision.title }}</h2>
              <p class="mt-1 max-w-2xl text-sm text-ink-muted">{{ decision.text }}</p>
            </div>
          </div>
          <StatusBadge v-if="batch.estado" :status="batch.estado" />
        </div>

        <div class="mt-4 grid gap-1 text-sm sm:grid-cols-2">
          <p><span class="text-ink-muted">Empresa destino:</span> <strong class="text-ink">{{ batch.company?.name || 'sin informar' }}</strong><span class="text-ink-muted"> · {{ batch.company?.cuit || 'sin CUIT' }}</span></p>
          <p><span class="text-ink-muted">Paquete:</span> <strong class="text-ink">{{ summary.source?.source_company || 'sin empresa' }}</strong><span class="text-ink-muted"> · {{ summary.source?.source_cuit || 'sin CUIT' }}</span></p>
        </div>

        <div v-if="Number(counts.blocking_errors) > 0" class="mt-4 rounded-xl border border-danger/40 bg-surface-raised p-4">
          <p class="text-sm font-bold text-danger-strong">Errores que impiden importar</p>
          <ul class="mt-2 space-y-1 text-sm text-ink">
            <li v-for="(issue, index) in blockingIssues.slice(0, 5)" :key="`${issue.field}-${index}`" class="flex flex-wrap gap-x-2">
              <span class="font-mono text-xs text-ink-subtle">{{ issue.field }}</span>
              <span>{{ issue.message }}</span>
            </li>
          </ul>
          <p v-if="Number(counts.blocking_errors) > blockingIssues.length" class="mt-1 text-xs text-ink-muted">y {{ Number(counts.blocking_errors) - blockingIssues.length }} más…</p>
        </div>

        <dl class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-5">
          <div v-for="metric in metrics" :key="metric.label" class="rounded-xl border border-border/60 bg-surface-raised px-4 py-3">
            <dt class="text-xs font-semibold text-ink-muted">{{ metric.label }}</dt>
            <dd class="mt-1 text-2xl font-bold" :class="metric.tone">{{ fmt(metric.value) }}</dd>
          </div>
        </dl>

        <ul class="mt-4 grid gap-2 text-sm text-ink-muted sm:grid-cols-2">
          <li class="flex gap-2"><InformationCircleIcon class="mt-0.5 size-4 shrink-0 text-primary" aria-hidden="true" />No se vinculan equipos ni choferes: el viaje conserva dominio y legajo de origen.</li>
          <li class="flex gap-2"><InformationCircleIcon class="mt-0.5 size-4 shrink-0 text-primary" aria-hidden="true" />No se generan lecturas de odómetro.</li>
          <li class="flex gap-2"><InformationCircleIcon class="mt-0.5 size-4 shrink-0 text-primary" aria-hidden="true" />Reimportar el mismo paquete omite los viajes ya existentes.</li>
          <li class="flex gap-2"><InformationCircleIcon class="mt-0.5 size-4 shrink-0 text-primary" aria-hidden="true" />Los viajes sin evidencia de país se asignan a la sucursal “sin clasificar”.</li>
        </ul>

        <div v-if="pending" class="mt-5 flex flex-wrap items-center gap-3 border-t border-border-subtle pt-5">
          <form method="post" :action="data.actions.confirm">
            <CsrfField :csrf="data.csrf" /><button type="submit" :disabled="!summary.can_confirm" :class="primaryButton"><CheckCircleIcon class="mr-2 size-5" aria-hidden="true" />Confirmar importación</button>
          </form>
          <form method="post" :action="data.actions.cancel">
            <CsrfField :csrf="data.csrf" /><button type="submit" :class="secondaryButton">Cancelar</button>
          </form>
          <p class="text-xs text-ink-muted">Una sola transacción: viajes e hijos, o nada.</p>
        </div>
      </section>

      <PanelCard title="Sucursales por país" :count="requiredCountries.length || null">
        <p class="text-sm text-ink-muted">La patente Mercosur define Argentina o Brasil. Las patentes anteriores a Mercosur se resuelven por mayoría de menciones de origen y destino. Los viajes sin evidencia suficiente quedan como “sin clasificar”.</p>
        <component :is="pending ? 'form' : 'div'" v-bind="pending ? { method: 'post', action: data.actions.branches } : {}" class="mt-4">
          <CsrfField v-if="pending" :csrf="data.csrf" />
          <div class="grid gap-4 md:grid-cols-3">
          <article v-for="country in countries" :key="country.key" class="rounded-xl border border-border bg-surface p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <h3 class="font-bold text-ink">{{ country.label }}</h3>
              <span class="rounded-full bg-surface-muted px-2.5 py-1 text-xs font-bold text-ink-muted">{{ fmt(classification[country.key]?.trips) }} viajes</span>
            </div>
            <p class="mt-1 text-xs text-ink-muted">{{ fmt(classification[country.key]?.new) }} nuevos</p>
            <div class="mt-3 flex flex-wrap gap-1">
              <span v-for="domain in classification[country.key]?.domains ?? []" :key="domain" class="rounded bg-surface-muted px-1.5 py-0.5 font-mono text-[11px] text-ink-muted">{{ domain }}</span>
              <span v-if="!(classification[country.key]?.domains ?? []).length" class="text-xs text-ink-subtle">Sin dominios</span>
            </div>
            <div class="mt-4">
              <template v-if="pending && requirements[country.key]">
                <label :for="`branch-${country.key}`" class="mb-1.5 block text-xs font-bold uppercase tracking-wide text-ink-muted">Sucursal asignada</label>
                <select :id="`branch-${country.key}`" v-model="branchSelection[country.key]" :name="country.selectName" :class="fieldClass">
                  <option value="">Seleccioná una sucursal</option>
                  <option v-for="branch in batch.branches" :key="branch.id" :value="branch.id">{{ branch.code }} · {{ branch.name }}</option>
                </select>
              </template>
              <p v-else-if="requirements[country.key]" class="rounded-lg bg-surface-muted px-3 py-2 text-sm text-ink-muted">
                {{ summary.branch_names?.[country.key] || 'Sin asignar' }}
              </p>
              <p v-else class="rounded-lg bg-surface-muted px-3 py-2 text-sm text-ink-muted">No hace falta: todos los viajes de este grupo ya existen.</p>
            </div>
          </article>
          </div>
          <div v-if="pending && requiredCountries.length" class="mt-4 flex flex-wrap items-center gap-3">
            <button type="submit" :class="primaryButton"><MapPinIcon class="mr-2 size-5" aria-hidden="true" />Guardar sucursales y recalcular</button>
            <p class="text-xs text-ink-muted">Se recalcula el resumen y se habilita la confirmación.</p>
          </div>
        </component>
        <p v-if="!batch.branches.length" role="alert" class="mt-4 rounded-xl border border-warning/40 bg-warning-subtle p-3 text-sm font-semibold text-warning-strong">La empresa no tiene sucursales activas. Creá una antes de importar.</p>
      </PanelCard>

      <PanelCard title="Qué se va a revisar u omitir" :count="counts.warnings">
        <p v-if="!issueGroups.length" class="flex items-center gap-2 text-sm font-semibold text-success-strong"><CheckCircleIcon class="size-5" aria-hidden="true" />Sin observaciones. El paquete se puede importar tal cual.</p>
        <div v-else class="space-y-3">
          <details v-for="group in issueGroups" :key="group.key" class="group rounded-xl border border-border bg-surface" open>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3">
              <div>
                <p class="font-semibold text-ink">{{ group.label }}</p>
                <p class="text-xs text-ink-muted">{{ group.description }}</p>
              </div>
              <span class="rounded-full bg-surface-muted px-2.5 py-1 text-xs font-bold text-ink-muted">{{ group.items.length }}</span>
            </summary>
            <ul class="border-t border-border-subtle px-4 py-3 text-sm">
              <li v-for="(issue, index) in group.items.slice(0, 50)" :key="`${issue.table}-${issue.key}-${index}`" class="flex flex-wrap gap-x-3 gap-y-1 py-1.5">
                <span class="font-mono text-xs text-ink-subtle">{{ issue.key || issue.table }}</span>
                <span class="text-ink-muted">{{ issue.message }}</span>
              </li>
              <li v-if="group.items.length > 50" class="pt-1 text-xs text-ink-muted">y {{ group.items.length - 50 }} más…</li>
            </ul>
          </details>
          <p v-if="summary.issues_total > (summary.issues_page?.length ?? 0)" class="text-xs text-ink-muted">Mostrando {{ summary.issues_page.length }} de {{ summary.issues_total }} observaciones.</p>
        </div>
      </PanelCard>

      <PanelCard title="Filas hijas del agregado">
        <div class="overflow-x-auto">
          <table class="min-w-full text-left text-sm">
            <thead>
              <tr class="border-b border-border text-xs uppercase tracking-wide text-ink-muted">
                <th class="px-3 py-3">Tabla legacy</th><th class="px-3 py-3">Destino</th><th class="px-3 py-3 text-right">Declaradas</th><th class="px-3 py-3 text-right">Válidas</th><th class="px-3 py-3 text-right">Huérfanas</th><th class="px-3 py-3 text-right">Con error</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="child in summary.children" :key="child.table" class="border-b border-border-subtle">
                <td class="whitespace-nowrap px-3 py-3 font-mono text-xs text-ink-muted">{{ child.table }}</td>
                <td class="whitespace-nowrap px-3 py-3 font-semibold text-ink">{{ child.destination }}</td>
                <td class="px-3 py-3 text-right text-ink-muted">{{ fmt(child.declared) }}</td>
                <td class="px-3 py-3 text-right font-semibold text-ink">{{ fmt(child.valid) }}</td>
                <td class="px-3 py-3 text-right" :class="Number(child.orphan) > 0 ? 'font-semibold text-warning-strong' : 'text-ink-muted'">{{ fmt(child.orphan) }}</td>
                <td class="px-3 py-3 text-right" :class="Number(child.error) > 0 ? 'font-semibold text-danger-strong' : 'text-ink-muted'">{{ fmt(child.error) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </PanelCard>

      <details class="rounded-2xl border border-border bg-surface-raised">
        <summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-3 px-5 py-4">
          <span class="font-bold text-ink">Contenido del paquete</span>
          <span class="text-xs text-ink-muted">{{ summary.manifest?.length ?? 0 }} tablas · {{ summary.source?.file }}</span>
        </summary>
        <div class="border-t border-border-subtle p-5">
          <p class="text-sm text-ink-muted">Archivo: {{ summary.source?.file }} · SHA256 {{ (summary.source?.sha256 ?? '').slice(0, 16) }}… · empresa origen {{ summary.source?.source_company || 'no informada' }}</p>
          <div class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            <div v-for="table in summary.manifest" :key="table.table" class="flex items-center justify-between gap-2 rounded-lg bg-surface px-3 py-2 text-sm">
              <span class="font-mono text-xs text-ink-muted">{{ table.table }}</span><span class="font-semibold text-ink">{{ fmt(table.rows) }}</span>
            </div>
          </div>
        </div>
      </details>

      <section v-if="pending" class="rounded-2xl border border-primary/20 bg-primary/5 p-5">
        <h2 class="font-bold text-ink">Confirmar la importación</h2>
        <p class="mt-1 text-sm text-ink-muted">Se guardan viajes e hijos en una sola transacción. La confirmación usa la sucursal guardada, no lo que envíe el navegador. Si algo falla, no queda nada a medias.</p>
        <a href="#trip-decision" class="mt-3 inline-flex text-sm font-semibold text-primary hover:underline">Volver al resumen y confirmar</a>
      </section>
    </template>
  </main>
</template>
