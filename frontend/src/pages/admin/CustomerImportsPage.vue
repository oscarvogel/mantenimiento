<script setup>
import { computed, ref } from 'vue'
import AdminPageHeading from './components/AdminPageHeading.vue'
import CsrfField from './components/CsrfField.vue'

const props = defineProps({ data: { type: Object, required: true } })
const companyId = ref('')
const hasFile = ref(false)
const batch = computed(() => props.data.batch)
const pending = computed(() => batch.value?.estado === 'PENDIENTE')
const ready = computed(() => pending.value && Number(batch.value.summary.errors) === 0)
const inputClass = 'min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-slate-500'
const buttonClass = 'min-h-11 rounded-lg bg-slate-900 px-4 py-2 font-semibold text-white hover:bg-slate-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-40'
</script>

<template>
  <main class="space-y-6">
    <nav class="flex flex-wrap gap-4 text-sm font-semibold text-slate-600" aria-label="Volver">
      <a :href="data.actions.superadmin" class="underline">Administración global</a>
      <a :href="data.actions.portal" class="underline">Portal de módulos</a>
    </nav>
    <AdminPageHeading eyebrow="Administración global" title="Importar clientes" description="Cargá el padrón para una empresa y revisalo antes de confirmar. Sus sucursales podrán usar esos clientes." />
    <p v-if="data.flash?.error" role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-800">{{ data.flash.error }}</p>
    <p v-if="data.flash?.success" role="status" class="rounded-xl border border-green-200 bg-green-50 p-4 text-green-800">{{ data.flash.success }}</p>

    <section v-if="!batch" class="rounded-xl border border-slate-200 bg-white p-5 sm:p-6">
      <h2 class="text-lg font-bold text-slate-900">1. Elegí el destino y el archivo</h2>
      <form method="post" :action="data.actions.preview" enctype="multipart/form-data" class="mt-5 space-y-5">
        <CsrfField :csrf="data.csrf" />
        <label class="block space-y-2 text-sm font-semibold text-slate-700">
          <span>Empresa que recibirá los clientes</span>
          <select v-model="companyId" name="empresa_id" required :class="inputClass">
            <option disabled value="">Seleccioná una empresa</option>
            <option v-for="company in data.companies" :key="company.id" :value="company.id">{{ company.name }}{{ company.cuit ? ` · ${company.cuit}` : '' }}</option>
          </select>
        </label>
        <label class="block space-y-2 text-sm font-semibold text-slate-700">
          <span>Archivo CSV de clientes</span>
          <input name="archivo_csv" type="file" accept=".csv,text/csv" required :class="inputClass" aria-describedby="csv-help" @change="hasFile = Boolean($event.target.files?.length)" />
        </label>
        <p id="csv-help" class="text-sm text-slate-600">Hasta 5 MB y 10.000 clientes. Usá la plantilla; se aceptan comas o punto y coma. Primero verás una revisión, sin guardar clientes.</p>
        <div class="flex flex-wrap items-center gap-4">
          <button type="submit" :disabled="!companyId || !hasFile" :class="buttonClass">Revisar archivo</button>
          <a :href="data.actions.template" class="text-sm font-semibold text-slate-700 underline">Descargar plantilla CSV</a>
        </div>
      </form>
    </section>

    <template v-else>
      <section class="rounded-xl border border-slate-200 bg-white p-5 sm:p-6">
        <h2 class="text-lg font-bold text-slate-900">{{ pending ? '2. Revisá y confirmá' : 'Resultado de la importación' }}</h2>
        <p class="mt-2 text-slate-700">Empresa de destino: <strong class="font-bold text-slate-950">{{ batch.companyName }}</strong></p>
        <p v-if="batch.resultado" role="status" class="mt-4 rounded-lg bg-green-50 p-4 font-semibold text-green-800">{{ batch.resultado.inserted }} clientes importados · {{ batch.resultado.skipped }} ya existentes omitidos.</p>
        <p v-else-if="batch.estado === 'CANCELADO'" role="status" class="mt-4 text-slate-700">Importación cancelada. No se guardaron clientes.</p>
        <dl class="mt-5 grid grid-cols-2 gap-4 text-sm sm:grid-cols-5">
          <div v-for="metric in [['Total', 'total'], ['Nuevos', 'new'], ['Ya existentes', 'existing'], ['Con errores', 'errors'], ['Con advertencias', 'warnings']]" :key="metric[1]" class="rounded-lg bg-slate-50 p-3">
            <dt class="text-slate-600">{{ metric[0] }}</dt><dd class="mt-1 text-xl font-bold text-slate-900">{{ batch.summary[metric[1]] }}</dd>
          </div>
        </dl>
        <p v-if="batch.summary.errors > 0" role="alert" class="mt-4 text-sm font-semibold text-red-800">Hay errores que impiden importar. Descargá el detalle, corregí el CSV y volvé a cargarlo.</p>
        <p class="mt-4 text-sm text-slate-600">Los clientes existentes se omiten. Las ubicaciones dudosas conservan el texto original y quedan pendientes de normalizar.</p>
        <div class="mt-5 flex flex-wrap items-center gap-3">
          <form v-if="pending" method="post" :action="data.actions.confirm">
            <CsrfField :csrf="data.csrf" /><button type="submit" :disabled="!ready" :class="buttonClass">Confirmar importación</button>
          </form>
          <form v-if="pending" method="post" :action="data.actions.cancel">
            <CsrfField :csrf="data.csrf" /><button type="submit" class="min-h-11 rounded-lg border border-slate-300 px-4 py-2 font-semibold text-slate-700 hover:bg-slate-50">Cancelar</button>
          </form>
          <a :href="data.actions.issues" class="text-sm font-semibold text-slate-700 underline">Descargar detalle de revisión</a>
          <a :href="data.actions.index" class="text-sm font-semibold text-slate-700 underline">Cargar otro archivo</a>
        </div>
      </section>
      <section class="overflow-hidden rounded-xl border border-slate-200 bg-white" aria-label="Clientes del archivo">
        <div class="overflow-x-auto">
          <table class="w-full text-left text-sm">
            <caption class="p-4 text-left font-semibold text-slate-800">Clientes del archivo · página {{ batch.page }} de {{ batch.pages }}</caption>
            <thead class="bg-slate-50 text-slate-600"><tr><th scope="col" class="p-3">Fila</th><th scope="col" class="p-3">Cliente</th><th scope="col" class="p-3">Documento</th><th scope="col" class="p-3">Domicilio original</th><th scope="col" class="p-3">Revisión</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="row in batch.rows" :key="row.number">
                <td class="p-3 align-top">{{ row.number }}</td>
                <td class="min-w-48 break-words p-3 align-top"><span class="font-semibold text-slate-900">{{ row.data.nombre || 'Sin nombre' }}</span><span class="block text-xs text-slate-500">Código {{ row.data.codigo_origen }}</span></td>
                <td class="p-3 align-top">{{ row.data.identificacion_fiscal || 'Sin documento' }}</td>
                <td class="min-w-40 break-words p-3 align-top">{{ [row.data.direccion, row.data.localidad, row.data.provincia].filter(Boolean).join(' · ') || 'Sin domicilio' }}</td>
                <td class="min-w-52 p-3 align-top">
                  <span v-if="row.existing" class="font-semibold text-slate-600">Ya existe: se omitirá</span>
                  <span v-else-if="!row.errors.length" class="font-semibold text-green-800">Nuevo</span>
                  <ul v-if="row.errors.length" class="space-y-1 text-red-800"><li v-for="error in row.errors" :key="error">{{ error }}</li></ul>
                  <ul v-if="row.warnings.length" class="mt-1 space-y-1 text-amber-800"><li v-for="warning in row.warnings" :key="warning">{{ warning }}</li></ul>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <nav v-if="batch.pages > 1" class="flex items-center justify-between gap-3 border-t border-slate-200 p-4 text-sm font-semibold" aria-label="Páginas de clientes">
          <a v-if="batch.page > 1" :href="`${data.actions.show}?page=${batch.page - 1}`" class="underline">Anterior</a><span v-else />
          <span>{{ batch.page }} / {{ batch.pages }}</span>
          <a v-if="batch.page < batch.pages" :href="`${data.actions.show}?page=${batch.page + 1}`" class="underline">Siguiente</a><span v-else />
        </nav>
      </section>
    </template>
  </main>
</template>
