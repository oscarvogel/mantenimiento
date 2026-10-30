<script setup>
import { ref } from 'vue'
import AdminPageHeading from './components/AdminPageHeading.vue'
import CsrfField from './components/CsrfField.vue'

const props = defineProps({ data: { type: Object, required: true } })
const hasFile = ref(false)
const inputClass = 'min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-slate-500'
const buttonClass = 'min-h-11 rounded-lg bg-slate-900 px-4 py-2 font-semibold text-white hover:bg-slate-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-40'
</script>

<template>
  <main class="space-y-6">
    <nav class="flex flex-wrap gap-4 text-sm font-semibold text-slate-600" aria-label="Volver">
      <a :href="data.actions.superadmin" class="underline">Administración global</a>
      <a :href="data.actions.portal" class="underline">Portal de módulos</a>
    </nav>
    <AdminPageHeading eyebrow="Catálogo compartido" title="Importar localidades" description="Las localidades quedan disponibles para todas las empresas. Revisá el archivo antes de confirmar." />
    <p v-if="data.flash?.error || data.error" role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-800">{{ data.error || data.flash.error }}</p>
    <p v-if="data.flash?.success" role="status" class="rounded-xl border border-green-200 bg-green-50 p-4 text-green-800">{{ data.flash.success }}</p>

    <section v-if="data.mode === 'upload' || !data.preview" class="rounded-xl border border-slate-200 bg-white p-5 sm:p-6">
      <h2 class="text-lg font-bold text-slate-900">1. Elegí el catálogo oficial</h2>
      <p class="mt-2 text-sm text-slate-600">Argentina, Brasil, Chile o Uruguay. Podés subir el CSV normalizado o su versión comprimida .csv.gz. El tamaño máximo es {{ data.maxSizeMb }} MB.</p>
      <form method="post" :action="data.actions.preview" enctype="multipart/form-data" class="mt-5 space-y-5">
        <CsrfField :csrf="data.csrf" />
        <label class="block space-y-2 text-sm font-semibold text-slate-700">
          <span>Archivo del catálogo</span>
          <input name="archivo" type="file" accept=".csv,.gz,text/csv,application/gzip" required :class="inputClass" @change="hasFile = Boolean($event.target.files?.length)" />
        </label>
        <div class="rounded-lg bg-slate-50 p-4 text-sm text-slate-700">
          <p class="font-semibold">Qué hace la importación</p>
          <ul class="mt-2 list-disc space-y-1 pl-5">
            <li>Actualiza localidades por su código oficial y conserva sus IDs y viajes asociados.</li>
            <li>Reimportar el mismo archivo no duplica registros.</li>
            <li>No borra localidades ni modifica kilómetros reales de los viajes.</li>
          </ul>
        </div>
        <button type="submit" :disabled="!hasFile" :class="buttonClass">Revisar archivo</button>
      </form>
    </section>

    <template v-else>
      <section class="space-y-5 rounded-xl border border-slate-200 bg-white p-5 sm:p-6">
        <h2 class="text-lg font-bold text-slate-900">2. Revisá antes de cargar</h2>
        <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
          <div v-for="metric in [['País', data.preview.country], ['Fuente', data.preview.source], ['Versión', data.preview.version], ['Filas', data.preview.total]]" :key="metric[0]" class="rounded-lg bg-slate-50 p-3">
            <dt class="text-slate-600">{{ metric[0] }}</dt><dd class="mt-1 break-words font-bold text-slate-900">{{ metric[1] || '—' }}</dd>
          </div>
        </dl>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
          <div class="rounded-lg border border-green-200 bg-green-50 p-4"><p class="text-sm text-green-800">Filas válidas</p><p class="mt-1 text-2xl font-bold text-green-900">{{ data.preview.valid }}</p></div>
          <div class="rounded-lg border border-red-200 bg-red-50 p-4"><p class="text-sm text-red-800">Filas con errores</p><p class="mt-1 text-2xl font-bold text-red-900">{{ data.preview.errors }}</p></div>
        </div>
        <p v-if="data.preview.total === 0" role="alert" class="text-sm font-semibold text-red-800">El archivo no contiene localidades para importar.</p>
        <p v-if="data.preview.errors" role="alert" class="text-sm font-semibold text-red-800">Corregí el archivo y volvé a subirlo. No se va a guardar ninguna fila mientras haya errores. Se muestran hasta 100 errores de ejemplo.</p>
        <div v-if="data.preview.errorSamples?.length" class="overflow-x-auto rounded-lg border border-slate-200">
          <table class="w-full min-w-[38rem] text-left text-sm">
            <thead class="bg-slate-50 text-slate-600"><tr><th class="p-3">Fila</th><th class="p-3">Código</th><th class="p-3">Localidad</th><th class="p-3">Detalle</th></tr></thead>
            <tbody class="divide-y divide-slate-100"><tr v-for="row in data.preview.errorSamples" :key="`${row.row}-${row.code}`"><td class="p-3 align-top">{{ row.row }}</td><td class="p-3 align-top">{{ row.code }}</td><td class="p-3 align-top">{{ row.name }}</td><td class="p-3 align-top text-red-800"><ul><li v-for="message in row.messages" :key="message">{{ message }}</li></ul></td></tr></tbody>
          </table>
        </div>
        <div class="flex flex-wrap gap-3">
          <form v-if="data.preview.canConfirm" method="post" :action="data.actions.confirm">
            <CsrfField :csrf="data.csrf" /><input type="hidden" name="token" :value="data.token" />
            <button type="submit" :class="buttonClass">Confirmar importación</button>
          </form>
          <a :href="data.actions.index" class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-4 font-semibold text-slate-700 underline">Elegir otro archivo</a>
        </div>
      </section>
    </template>
  </main>
</template>
