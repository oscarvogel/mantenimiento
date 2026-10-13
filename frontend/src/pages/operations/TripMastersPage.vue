<script setup>
import { computed, ref } from 'vue'

const props = defineProps({ data: { type: Object, required: true } })
const keys = Object.keys(props.data.catalogs ?? {})
const selected = ref(keys[0] ?? 'clientes')
const editing = ref(null)
const form = ref({})
const catalog = computed(() => props.data.catalogs?.[selected.value] ?? { label: '', items: [] })
const fieldsByCatalog = {
  clientes: [
    { key: 'nombre', label: 'Nombre o razón social', required: true },
    { key: 'identificacion_fiscal', label: 'CUIT / identificación fiscal' },
    { key: 'telefono', label: 'Teléfono' },
    { key: 'email', label: 'Correo electrónico', type: 'email' },
  ],
  domicilios: [
    { key: 'cliente_id', label: 'Cliente', type: 'customer', required: true },
    { key: 'alias', label: 'Nombre del domicilio', required: true },
    { key: 'calle', label: 'Dirección', required: true },
    { key: 'localidad', label: 'Localidad', required: true },
    { key: 'provincia', label: 'Provincia / región' },
    { key: 'pais_id', label: 'País', type: 'country', required: true },
    { key: 'codigo_postal', label: 'Código postal' },
  ],
  'tipos-carga': [
    { key: 'nombre', label: 'Tipo de carga', required: true },
    { key: 'descripcion', label: 'Descripción' },
  ],
  'unidades-carga': [
    { key: 'nombre', label: 'Unidad de medida', required: true },
    { key: 'simbolo', label: 'Símbolo', required: true },
  ],
  'categorias-gasto': [
    { key: 'nombre', label: 'Categoría de gasto', required: true },
    { key: 'descripcion', label: 'Descripción' },
  ],
  'medios-pago': [
    { key: 'nombre', label: 'Medio de pago', required: true },
    { key: 'descripcion', label: 'Descripción' },
  ],
  'tipos-documento': [
    { key: 'nombre', label: 'Tipo de documento', required: true },
    { key: 'codigo', label: 'Código de referencia' },
    { key: 'descripcion', label: 'Descripción' },
  ],
  liquidaciones: [
    { key: 'empleado_id', label: 'Empleado', type: 'employee', required: true },
    { key: 'porcentaje', label: 'Porcentaje de liquidación', type: 'number', min: 0, max: 100, step: '0.01', required: true },
    { key: 'vigente_desde', label: 'Vigente desde', type: 'date', required: true },
    { key: 'vigente_hasta', label: 'Vigente hasta', type: 'date' },
  ],
}
const fields = computed(() => fieldsByCatalog[selected.value] ?? [])
const titleFor = (item) => {
  if (selected.value === 'domicilios') return `${item.alias} · ${item.cliente_nombre}`
  if (selected.value === 'liquidaciones') return `${item.empleado_nombre} ${item.empleado_apellido} · ${item.porcentaje}%`
  return [item.nombre, item.simbolo].filter(Boolean).join(' · ')
}
const detailFor = (item) => {
  if (selected.value === 'domicilios') return [item.calle, item.localidad, item.provincia, item.pais_nombre, item.codigo_postal].filter(Boolean).join(', ')
  if (selected.value === 'liquidaciones') return `Vigencia: ${item.vigente_desde}${item.vigente_hasta ? ` a ${item.vigente_hasta}` : ' en adelante'}`
  return [item.identificacion_fiscal, item.telefono, item.email, item.codigo, item.descripcion].filter(Boolean).join(' · ')
}
const newForm = () => {
  editing.value = null
  form.value = {}
}
const editItem = (item) => {
  editing.value = item
  form.value = Object.fromEntries(fields.value.map(({ key }) => [key, item[key] ?? '']))
}
const actionForForm = computed(() => editing.value ? editing.value.updateUrl : props.data.urls.create)
const buttonClass = 'inline-flex min-h-10 items-center justify-center rounded-lg px-4 py-2 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary'
const inputClass = 'mt-1 block min-h-11 w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20'
</script>

<template>
  <div class="mx-auto w-full max-w-7xl space-y-6 p-4 sm:p-6 lg:p-8">
    <header class="space-y-2">
      <p class="text-xs font-bold uppercase tracking-[0.16em] text-primary">Viajes · Configuración</p>
      <h1 class="text-2xl font-bold tracking-tight text-ink sm:text-3xl">Maestros de viajes</h1>
      <p class="max-w-3xl text-sm leading-6 text-ink-muted">Configurá los datos que se reutilizarán al registrar viajes de carga y facturación. Estos catálogos pertenecen a tu empresa; monedas y países son compartidos.</p>
    </header>

    <div v-if="props.data.flash?.success" class="rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-900" role="status">{{ props.data.flash.success }}</div>
    <div v-if="props.data.flash?.error" class="rounded-xl border border-red-300 bg-red-50 px-4 py-3 text-sm font-medium text-red-900" role="alert">{{ props.data.flash.error }}</div>

    <section class="overflow-hidden rounded-2xl border border-border bg-surface-raised shadow-sm">
      <div class="border-b border-border-subtle px-4 py-4 sm:px-6">
        <h2 class="text-lg font-semibold text-ink">Catálogos de la empresa</h2>
        <p class="mt-1 text-sm text-ink-muted">Los registros inactivos se conservan para mantener la historia.</p>
      </div>
      <div class="flex flex-wrap gap-2 border-b border-border-subtle p-3 sm:px-5" role="tablist" aria-label="Maestros de viajes">
        <button v-for="key in keys" :key="key" type="button" role="tab" :aria-selected="selected === key" :class="`${buttonClass} ${selected === key ? 'bg-primary text-primary-foreground' : 'bg-surface-muted text-ink-muted hover:text-ink'}`" @click="selected = key; newForm()">
          {{ props.data.catalogs[key].label }}
        </button>
      </div>

      <div class="grid gap-6 p-4 sm:p-6 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,0.72fr)]">
        <div class="min-w-0">
          <div v-if="catalog.items.length" class="divide-y divide-border-subtle rounded-xl border border-border-subtle">
            <article v-for="item in catalog.items" :key="item.id" class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
              <div class="min-w-0">
                <h3 class="truncate font-semibold text-ink">{{ titleFor(item) }}</h3>
                <p v-if="detailFor(item)" class="mt-1 text-sm text-ink-muted">{{ detailFor(item) }}</p>
                <span class="mt-2 inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="Number(item.activo) ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'">{{ Number(item.activo) ? 'Activo' : 'Inactivo' }}</span>
              </div>
              <div v-if="props.data.canEdit" class="flex shrink-0 gap-2">
                <button type="button" :class="`${buttonClass} border border-border bg-surface-raised text-ink hover:bg-surface-muted`" @click="editItem(item)">Editar</button>
                <form method="post" :action="item.statusUrl" :data-confirm="Number(item.activo) ? '' : undefined" :data-confirm-title="Number(item.activo) ? '¿Inactivar este registro?' : undefined" :data-confirm-text="Number(item.activo) ? 'Dejará de aparecer en nuevos registros; los datos históricos se conservan.' : undefined" :data-confirm-button="Number(item.activo) ? 'Inactivar' : undefined" :data-confirm-danger="Number(item.activo) ? 'true' : undefined">
                  <input type="hidden" :name="props.data.csrf.name" :value="props.data.csrf.hash">
                  <input type="hidden" name="activo" :value="Number(item.activo) ? 0 : 1">
                  <button type="submit" :class="`${buttonClass} ${Number(item.activo) ? 'bg-red-50 text-red-800 hover:bg-red-100' : 'bg-emerald-50 text-emerald-800 hover:bg-emerald-100'}`">{{ Number(item.activo) ? 'Inactivar' : 'Reactivar' }}</button>
                </form>
              </div>
            </article>
          </div>
          <div v-else class="rounded-xl border border-dashed border-border px-5 py-10 text-center">
            <h3 class="font-semibold text-ink">Todavía no hay registros</h3>
            <p class="mt-1 text-sm text-ink-muted">Agregá el primero con el formulario.</p>
          </div>
        </div>

        <aside v-if="props.data.canEdit" class="h-fit rounded-xl border border-border-subtle bg-surface-subtle p-4 sm:p-5">
          <div class="flex items-start justify-between gap-3">
            <div>
              <p class="text-xs font-bold uppercase tracking-wide text-primary">{{ editing ? 'Editar registro' : 'Nuevo registro' }}</p>
              <h3 class="mt-1 text-lg font-semibold text-ink">{{ catalog.label }}</h3>
            </div>
            <button v-if="editing" type="button" class="text-sm font-semibold text-primary hover:underline" @click="newForm">Cancelar</button>
          </div>
          <p v-if="selected === 'liquidaciones' && props.data.employees.length === 0" class="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">Para configurar porcentajes necesitás permiso para consultar empleados activos de tu empresa.</p>
          <form class="mt-4 space-y-4" method="post" :action="actionForForm">
            <input type="hidden" :name="props.data.csrf.name" :value="props.data.csrf.hash">
            <input v-if="!editing" type="hidden" name="catalogo" :value="selected">
            <label v-for="field in fields" :key="field.key" class="block text-sm font-medium text-ink">
              {{ field.label }} <span v-if="field.required" class="text-red-700">*</span>
              <select v-if="field.type === 'customer'" v-model="form[field.key]" :name="field.key" :required="field.required" :class="inputClass">
                <option value="">Elegí un cliente</option><option v-for="customer in props.data.catalogs.clientes.items.filter((row) => Number(row.activo))" :key="customer.id" :value="customer.id">{{ customer.nombre }}</option>
              </select>
              <select v-else-if="field.type === 'country'" v-model="form[field.key]" :name="field.key" :required="field.required" :class="inputClass">
                <option value="">Elegí un país</option><option v-for="country in props.data.countries.filter((row) => Number(row.activo))" :key="country.id" :value="country.id">{{ country.nombre }}</option>
              </select>
              <select v-else-if="field.type === 'employee'" v-model="form[field.key]" :name="field.key" :required="field.required" :disabled="props.data.employees.length === 0" :class="inputClass">
                <option value="">Elegí un empleado</option><option v-for="employee in props.data.employees" :key="employee.id" :value="employee.id">{{ employee.name }}</option>
              </select>
              <textarea v-else-if="field.key === 'descripcion'" v-model="form[field.key]" :name="field.key" rows="3" :class="inputClass" />
              <input v-else v-model="form[field.key]" :name="field.key" :type="field.type ?? 'text'" :min="field.min" :max="field.max" :step="field.step" :required="field.required" :class="inputClass">
            </label>
            <button type="submit" :disabled="selected === 'liquidaciones' && props.data.employees.length === 0" :class="`${buttonClass} w-full bg-primary text-primary-foreground hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-50`">{{ editing ? 'Guardar cambios' : 'Crear maestro' }}</button>
          </form>
        </aside>
      </div>
    </section>

    <section class="grid gap-4 sm:grid-cols-2">
      <article class="rounded-2xl border border-border bg-surface-raised p-5 shadow-sm">
        <h2 class="font-semibold text-ink">Monedas disponibles</h2>
        <p class="mt-1 text-sm text-ink-muted">Catálogo compartido de consulta.</p>
        <ul class="mt-4 flex flex-wrap gap-2"><li v-for="currency in props.data.currencies" :key="currency.id" class="rounded-lg bg-surface-muted px-3 py-2 text-sm"><strong>{{ currency.codigo }}</strong> · {{ currency.nombre }}</li></ul>
      </article>
      <article class="rounded-2xl border border-border bg-surface-raised p-5 shadow-sm">
        <h2 class="font-semibold text-ink">Países disponibles</h2>
        <p class="mt-1 text-sm text-ink-muted">Catálogo compartido para domicilios.</p>
        <ul class="mt-4 flex flex-wrap gap-2"><li v-for="country in props.data.countries" :key="country.id" class="rounded-lg bg-surface-muted px-3 py-2 text-sm">{{ country.nombre }} <span class="text-ink-muted">({{ country.codigo_iso }})</span></li></ul>
      </article>
    </section>
  </div>
</template>
