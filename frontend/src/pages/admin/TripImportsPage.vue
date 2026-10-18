<script setup>
import { computed, ref } from 'vue'
import AdminPageHeading from './components/AdminPageHeading.vue'
import CsrfField from './components/CsrfField.vue'

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
const branchSelection = ref({
  AR: summary.value.branch_map?.AR ?? '',
  BR: summary.value.branch_map?.BR ?? '',
  UNCLASSIFIED: summary.value.branch_map?.UNCLASSIFIED ?? '',
})
const inputClass = 'min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-slate-500'
const buttonClass = 'min-h-11 rounded-lg bg-slate-900 px-4 py-2 font-semibold text-white hover:bg-slate-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-40'
const secondaryClass = 'min-h-11 rounded-lg border border-slate-300 px-4 py-2 font-semibold text-slate-700 hover:bg-slate-50'
const countries = computed(() => [
  { key: 'AR', label: 'Argentina' },
  { key: 'BR', label: 'Brasil' },
  { key: 'UNCLASSIFIED', label: 'Sin clasificar' },
])
const requiredCountries = computed(() => countries.value.filter((country) => requirements.value[country.key]))
const fmt = (value) => Number(value ?? 0).toLocaleString('es-AR')
</script>

<template>
  <main class="space-y-6">
    <nav class="flex flex-wrap gap-4 text-sm font-semibold text-slate-600" aria-label="Volver">
      <a :href="data.actions.superadmin" class="underline">Administración global</a>
      <a :href="data.actions.portal" class="underline">Portal de módulos</a>
    </nav>
    <AdminPageHeading
      eyebrow="Administración global"
      title="Importar viajes"
      description="Subí el paquete ZIP generado desde el backup legacy. Primero vas a ver la clasificación Argentina/Brasil y los errores; nada se guarda hasta confirmar."
    />
    <p v-if="data.flash?.error" role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-800">{{ data.flash.error }}</p>
    <p v-if="data.flash?.success" role="status" class="rounded-xl border border-green-200 bg-green-50 p-4 text-green-800">{{ data.flash.success }}</p>

    <section v-if="data.mode === 'form'" class="rounded-xl border border-slate-200 bg-white p-5 sm:p-6">
      <h2 class="text-lg font-bold text-slate-900">1. Elegí la empresa y el paquete</h2>
      <form method="post" :action="data.actions.preview" enctype="multipart/form-data" class="mt-5 space-y-5">
        <CsrfField :csrf="data.csrf" />
        <label class="block space-y-2 text-sm font-semibold text-slate-700">
          <span>Empresa que recibirá los viajes</span>
          <select v-model="companyId" name="empresa_id" required :class="inputClass">
            <option disabled value="">Seleccioná una empresa</option>
            <option v-for="company in data.companies" :key="company.id" :value="company.id">{{ company.name }}{{ company.cuit ? ` · ${company.cuit}` : '' }}</option>
          </select>
        </label>
        <label class="block space-y-2 text-sm font-semibold text-slate-700">
          <span>Paquete de viajes (.zip)</span>
          <input name="archivo" type="file" accept=".zip,application/zip" required :class="inputClass" aria-describedby="trip-package-help" @change="hasFile = Boolean($event.target.files?.length)" />
        </label>
        <p id="trip-package-help" class="text-sm text-slate-600">Hasta {{ data.maxSizeMb }} MB. El paquete debe incluir manifest.json y las tablas del agregado de Viajes. Después de validarlo vas a asignar la sucursal de Argentina, la de Brasil y la de los viajes sin clasificar.</p>
        <button type="submit" :disabled="!companyId || !hasFile" :class="buttonClass">Validar paquete</button>
      </form>
    </section>

    <template v-else>
      <section class="rounded-xl border border-slate-200 bg-white p-5 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div>
            <h2 class="text-lg font-bold text-slate-900">{{ confirmed ? 'Resultado de la importación' : cancelled ? 'Importación cancelada' : '2. Revisá la clasificación y asigná sucursales' }}</h2>
            <p class="mt-2 text-slate-700">Empresa de destino: <strong class="font-bold text-slate-950">{{ batch.company?.name }}</strong><span v-if="batch.company?.cuit"> · {{ batch.company.cuit }}</span></p>
            <p class="mt-1 text-sm text-slate-600">Archivo: {{ batch.archivo_original }} · {{ batch.fecha }}</p>
            <p v-if="summary.source?.source_company" class="mt-1 text-sm text-slate-600">Empresa de origen: {{ summary.source.source_company }}</p>
          </div>
          <a :href="data.actions.index" class="text-sm font-semibold text-slate-700 underline">Subir otro paquete</a>
        </div>

        <p v-if="summary.result" role="status" class="mt-4 rounded-lg bg-green-50 p-4 font-semibold text-green-800">
          {{ fmt(summary.result.inserted) }} viajes nuevos · {{ fmt(summary.result.skipped) }} existentes omitidos · {{ fmt(summary.result.children_inserted) }} filas hijas guardadas · {{ fmt(summary.result.orphans) }} huérfanas omitidas.
        </p>
        <p v-else-if="cancelled" role="status" class="mt-4 text-slate-700">No se guardaron viajes.</p>

        <dl class="mt-5 grid grid-cols-2 gap-4 text-sm sm:grid-cols-5">
          <div v-for="metric in [['Viajes en el paquete', 'trips_total'], ['Nuevos', 'trips_new'], ['Ya existentes', 'trips_existing'], ['Con error', 'trips_error'], ['Advertencias', 'warnings']]" :key="metric[1]" class="rounded-lg bg-slate-50 p-3">
            <dt class="text-slate-600">{{ metric[0] }}</dt><dd class="mt-1 text-xl font-bold text-slate-900">{{ fmt(counts[metric[1]]) }}</dd>
          </div>
        </dl>
        <p v-if="counts.blocking_errors" role="alert" class="mt-4 text-sm font-semibold text-red-800">Hay {{ fmt(counts.blocking_errors) }} errores bloqueantes. Corregí el paquete y volvé a subirlo.</p>
      </section>

      <section class="rounded-xl border border-slate-200 bg-white p-5 sm:p-6">
        <h2 class="text-lg font-bold text-slate-900">Clasificación por evidencia</h2>
        <p class="mt-2 text-sm text-slate-600">La patente Mercosur define Argentina o Brasil. Las patentes anteriores a Mercosur se resuelven por mayoría de menciones de origen y destino. Los viajes sin evidencia suficiente quedan como “sin clasificar”.</p>
        <div class="mt-4 overflow-x-auto">
          <table class="w-full text-left text-sm">
            <thead class="bg-slate-50 text-slate-600"><tr><th class="p-3">País</th><th class="p-3">Viajes</th><th class="p-3">Nuevos</th><th class="p-3">Dominios</th><th class="p-3">Sucursal asignada</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="country in countries" :key="country.key">
                <td class="p-3 font-semibold text-slate-900">{{ country.label }}</td>
                <td class="p-3">{{ fmt(classification[country.key]?.trips) }}</td>
                <td class="p-3">{{ fmt(classification[country.key]?.new) }}</td>
                <td class="min-w-48 p-3 text-slate-600">{{ (classification[country.key]?.domains ?? []).join(', ') || 'Sin dominios' }}</td>
                <td class="p-3">{{ summary.branch_names?.[country.key] || (requirements[country.key] ? 'Falta asignar' : 'No aplica') }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <form v-if="!confirmed && !cancelled && requiredCountries.length" method="post" :action="data.actions.branches" class="mt-5 grid gap-4 border-t border-slate-200 pt-5 sm:grid-cols-2 lg:grid-cols-3">
          <CsrfField :csrf="data.csrf" />
          <label v-for="country in requiredCountries" :key="country.key" class="block space-y-2 text-sm font-semibold text-slate-700">
            <span>Sucursal para {{ country.label.toLowerCase() }}</span>
            <select v-model="branchSelection[country.key]" :name="country.key === 'UNCLASSIFIED' ? 'sucursal_sin_clasificar' : `sucursal_${country.key.toLowerCase()}`" :class="inputClass">
              <option value="">Seleccioná una sucursal</option>
              <option v-for="branch in batch.branches" :key="branch.id" :value="branch.id">{{ branch.code }} · {{ branch.name }}</option>
            </select>
          </label>
          <div class="flex items-end">
            <button type="submit" :class="secondaryClass">Guardar sucursales y recalcular</button>
          </div>
        </form>
        <p v-if="!batch.branches.length" role="alert" class="mt-4 text-sm font-semibold text-amber-800">La empresa no tiene sucursales activas. Creá una antes de importar.</p>
        <p v-if="!confirmed && !cancelled && !requiredCountries.length" class="mt-4 text-sm text-slate-600">Todos los viajes del paquete ya existen en el destino; no hace falta asignar sucursales.</p>
      </section>

      <section v-if="summary.children?.length" class="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <div class="p-5 pb-0"><h2 class="text-lg font-bold text-slate-900">Tablas hijas del agregado</h2></div>
        <div class="mt-3 overflow-x-auto">
          <table class="w-full text-left text-sm">
            <thead class="bg-slate-50 text-slate-600"><tr><th class="p-3">Tabla legacy</th><th class="p-3">Destino</th><th class="p-3">Declaradas</th><th class="p-3">Válidas</th><th class="p-3">Huérfanas</th><th class="p-3">Con error</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="child in summary.children" :key="child.table">
                <td class="p-3 font-mono text-xs text-slate-700">{{ child.table }}</td><td class="p-3">{{ child.destination }}</td>
                <td class="p-3">{{ fmt(child.declared) }}</td><td class="p-3">{{ fmt(child.valid) }}</td><td class="p-3">{{ fmt(child.orphan) }}</td><td class="p-3">{{ fmt(child.error) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="summary.issues_total" class="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <div class="overflow-x-auto">
          <table class="w-full text-left text-sm">
            <caption class="p-4 text-left font-semibold text-slate-800">Observaciones · {{ summary.issues_total }} en total · página {{ summary.page }} de {{ summary.pages }}</caption>
            <thead class="bg-slate-50 text-slate-600"><tr><th class="p-3">Tipo</th><th class="p-3">Tabla</th><th class="p-3">Clave</th><th class="p-3">Campo</th><th class="p-3">Mensaje</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="issue in summary.issues_page" :key="`${issue.table}-${issue.key}-${issue.field}-${issue.message}`">
                <td class="p-3"><span class="font-semibold" :class="issue.severity === 'ERROR' ? 'text-red-800' : 'text-amber-800'">{{ issue.severity }}</span></td>
                <td class="p-3 font-mono text-xs text-slate-700">{{ issue.table }}</td>
                <td class="p-3 font-mono text-xs text-slate-700">{{ issue.key || '—' }}</td>
                <td class="p-3">{{ issue.field }}</td>
                <td class="min-w-64 p-3 text-slate-700">{{ issue.message }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <nav v-if="summary.pages > 1" class="flex items-center justify-between gap-3 border-t border-slate-200 p-4 text-sm font-semibold" aria-label="Páginas de observaciones">
          <a v-if="summary.page > 1" :href="`${data.actions.show}?page=${summary.page - 1}`" class="underline">Anterior</a><span v-else />
          <span>{{ summary.page }} / {{ summary.pages }}</span>
          <a v-if="summary.page < summary.pages" :href="`${data.actions.show}?page=${summary.page + 1}`" class="underline">Siguiente</a><span v-else />
        </nav>
      </section>

      <section v-if="!confirmed && !cancelled" class="flex flex-wrap items-center gap-3 rounded-xl border border-slate-200 bg-white p-5">
        <form method="post" :action="data.actions.confirm">
          <CsrfField :csrf="data.csrf" /><button type="submit" :disabled="!summary.can_confirm" :class="buttonClass">Confirmar importación</button>
        </form>
        <form method="post" :action="data.actions.cancel">
          <CsrfField :csrf="data.csrf" /><button type="submit" :class="secondaryClass">Cancelar</button>
        </form>
        <p class="text-sm text-slate-600">La confirmación guarda viajes e hijos en una sola transacción. Si algo falla, no queda nada a medias.</p>
      </section>
    </template>
  </main>
</template>
