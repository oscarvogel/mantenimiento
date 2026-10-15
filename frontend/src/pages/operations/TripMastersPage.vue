<script setup>
import { computed, ref } from 'vue'

const props = defineProps({ data: { type: Object, required: true } })

const catalogs = computed(() => props.data.catalogs ?? {})
const catalogKeys = computed(() => Object.keys(catalogs.value).filter((key) => key !== 'domicilios'))
const selected = ref(catalogKeys.value.includes('clientes') ? 'clientes' : catalogKeys.value[0] ?? 'clientes')
const selectedCustomerId = ref(props.data.catalogs?.clientes?.items?.[0]?.id ?? null)
const customerQuery = ref('')
const customerStatus = ref('all')
const catalogQuery = ref('')
const catalogStatus = ref('all')
const editorOpen = ref(false)
const editing = ref(null)
const formCatalog = ref('')
const form = ref({})

const fieldsByCatalog = {
  clientes: [
    { key: 'nombre', label: 'Nombre o razón social', required: true },
    { key: 'identificacion_fiscal', label: 'CUIT / identificación fiscal' },
    { key: 'telefono', label: 'Teléfono', type: 'tel' },
    { key: 'email', label: 'Correo electrónico', type: 'email' },
  ],
  domicilios: [
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

const customerItems = computed(() => catalogs.value.clientes?.items ?? [])
const domicileItems = computed(() => catalogs.value.domicilios?.items ?? [])
const selectedCustomer = computed(() => customerItems.value.find((item) => String(item.id) === String(selectedCustomerId.value)) ?? null)
const customerDomiciles = computed(() => selectedCustomer.value
  ? domicileItems.value.filter((item) => String(item.cliente_id) === String(selectedCustomer.value.id))
  : [])
const visibleCustomers = computed(() => {
  const query = customerQuery.value.trim().toLocaleLowerCase('es')
  return customerItems.value.filter((item) => {
    const matchesStatus = customerStatus.value === 'all'
      || (customerStatus.value === 'active' ? Number(item.activo) === 1 : Number(item.activo) !== 1)
    const searchable = [item.nombre, item.identificacion_fiscal, item.telefono, item.email].join(' ').toLocaleLowerCase('es')
    return matchesStatus && (!query || searchable.includes(query))
  })
})
const selectedCatalog = computed(() => catalogs.value[selected.value] ?? { label: '', items: [] })
const catalogFields = computed(() => fieldsByCatalog[formCatalog.value] ?? [])
const formCatalogLabel = computed(() => catalogs.value[formCatalog.value]?.label ?? '')
const catalogItems = computed(() => {
  const query = catalogQuery.value.trim().toLocaleLowerCase('es')
  return (selectedCatalog.value.items ?? []).filter((item) => {
    const active = Number(item.activo) === 1
    const matchesStatus = catalogStatus.value === 'all'
      || (catalogStatus.value === 'active' ? active : !active)
    const text = catalogItemTitle(selected.value, item)
    const detail = catalogItemDetail(selected.value, item)
    return matchesStatus && (!query || `${text} ${detail}`.toLocaleLowerCase('es').includes(query))
  })
})
const actionForForm = computed(() => editing.value?.updateUrl ?? props.data.urls.create)

const buttonClass = 'inline-flex min-h-10 items-center justify-center rounded-lg px-4 py-2 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2'
const primaryButton = `${buttonClass} bg-primary text-primary-foreground hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-50`
const secondaryButton = `${buttonClass} border border-border bg-surface-raised text-ink hover:bg-surface-muted`
const inputClass = 'mt-1 block min-h-11 w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20'

function catalogItemTitle(catalogKey, item) {
  if (catalogKey === 'liquidaciones') {
    return `${item.empleado_nombre} ${item.empleado_apellido} · ${item.porcentaje}%`
  }
  return [item.nombre, item.simbolo].filter(Boolean).join(' · ')
}

function catalogItemDetail(catalogKey, item) {
  if (catalogKey === 'liquidaciones') {
    return `Vigencia: ${item.vigente_desde}${item.vigente_hasta ? ` a ${item.vigente_hasta}` : ' en adelante'}`
  }
  return [item.identificacion_fiscal, item.telefono, item.email, item.codigo, item.descripcion].filter(Boolean).join(' · ')
}

function selectCatalog(key) {
  selected.value = key
  catalogQuery.value = ''
  catalogStatus.value = 'all'
  if (key === 'clientes' && !selectedCustomer.value && customerItems.value.length) {
    selectedCustomerId.value = customerItems.value[0].id
  }
}

function openCreate(catalogKey = selected.value, defaults = {}) {
  formCatalog.value = catalogKey
  editing.value = null
  form.value = { ...defaults }
  editorOpen.value = true
}

function openEdit(catalogKey, item) {
  formCatalog.value = catalogKey
  editing.value = item
  form.value = Object.fromEntries((fieldsByCatalog[catalogKey] ?? []).map(({ key }) => [key, item[key] ?? '']))
  if (catalogKey === 'domicilios') form.value.cliente_id = item.cliente_id
  editorOpen.value = true
}

function closeEditor() {
  editorOpen.value = false
  editing.value = null
  form.value = {}
}

function statusMessage(item) {
  return Number(item.activo) === 1
    ? 'Dejará de aparecer en nuevos registros; el historial se conserva.'
    : 'Volverá a estar disponible para nuevos registros.'
}
</script>

<template>
  <div class="mx-auto w-full max-w-[90rem] space-y-6 p-4 sm:p-6 lg:p-8">
    <header class="space-y-2">
      <div class="space-y-2">
        <p class="text-xs font-bold uppercase tracking-[0.16em] text-primary">Viajes · Configuración</p>
        <h1 class="text-2xl font-bold tracking-tight text-ink sm:text-3xl">Maestros de viajes</h1>
        <p class="max-w-3xl text-sm leading-6 text-ink-muted">Administrá los datos que se reutilizan al planificar y cerrar viajes. Los registros inactivos se conservan en el historial.</p>
      </div>
    </header>

    <div v-if="props.data.flash?.success" class="rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-900" role="status">{{ props.data.flash.success }}</div>
    <div v-if="props.data.flash?.error" class="rounded-xl border border-red-300 bg-red-50 px-4 py-3 text-sm font-medium text-red-900" role="alert">{{ props.data.flash.error }}</div>

    <nav class="space-y-3" aria-label="Catálogos de la empresa">
      <div>
        <h2 class="text-sm font-semibold text-ink">Catálogos de la empresa</h2>
        <p class="mt-1 text-sm text-ink-muted">Elegí qué información querés administrar.</p>
      </div>
      <div class="flex gap-2 overflow-x-auto pb-1" role="tablist" aria-label="Maestros de viajes">
        <button
          v-for="key in catalogKeys"
          :key="key"
          type="button"
          role="tab"
          :aria-selected="selected === key"
          :class="selected === key ? primaryButton : secondaryButton"
          @click="selectCatalog(key)"
        >
          {{ catalogs[key].label }}
        </button>
      </div>
    </nav>

    <section v-if="selected === 'clientes'" class="grid min-w-0 gap-4 lg:grid-cols-[minmax(17rem,0.36fr)_minmax(0,0.64fr)]">
      <aside class="min-w-0 overflow-hidden rounded-2xl border border-border bg-surface-raised">
        <div class="space-y-4 border-b border-border-subtle p-4 sm:p-5">
          <div class="flex items-start justify-between gap-3">
            <div>
              <h2 class="text-lg font-semibold text-ink">Clientes</h2>
              <p class="mt-1 text-sm text-ink-muted">{{ customerItems.length }} {{ customerItems.length === 1 ? 'registro' : 'registros' }}</p>
            </div>
            <button v-if="props.data.canEdit" type="button" :class="primaryButton" @click="openCreate('clientes')">Nuevo cliente</button>
          </div>
          <label class="block">
            <span class="sr-only">Buscar clientes</span>
            <input v-model="customerQuery" type="search" class="w-full rounded-lg border border-border bg-surface px-3 py-2.5 text-sm text-ink placeholder:text-ink-muted focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20" placeholder="Buscar por nombre, CUIT o contacto">
          </label>
          <label class="block">
            <span class="sr-only">Filtrar clientes por estado</span>
            <select v-model="customerStatus" class="w-full rounded-lg border border-border bg-surface px-3 py-2.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
              <option value="all">Todos los estados</option>
              <option value="active">Activos</option>
              <option value="inactive">Inactivos</option>
            </select>
          </label>
        </div>

        <ul v-if="visibleCustomers.length" class="max-h-[42rem] divide-y divide-border-subtle overflow-y-auto" aria-label="Clientes">
          <li v-for="customer in visibleCustomers" :key="customer.id">
            <button
              type="button"
              class="flex min-h-[4.5rem] w-full items-center justify-between gap-3 border-l-2 px-4 py-3 text-left transition hover:bg-surface-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary sm:px-5"
              :class="String(selectedCustomerId) === String(customer.id) ? 'border-primary bg-primary-subtle' : 'border-transparent'"
              :aria-pressed="String(selectedCustomerId) === String(customer.id)"
              @click="selectedCustomerId = customer.id"
            >
              <span class="min-w-0">
                <span class="block truncate text-sm font-semibold text-ink">{{ customer.nombre }}</span>
                <span class="mt-1 block truncate text-xs text-ink-muted">{{ customer.identificacion_fiscal || 'Sin CUIT informado' }}</span>
              </span>
              <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold" :class="Number(customer.activo) === 1 ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700'">
                {{ Number(customer.activo) === 1 ? 'Activo' : 'Inactivo' }}
              </span>
            </button>
          </li>
        </ul>
        <div v-else class="px-5 py-10 text-center">
          <h3 class="font-semibold text-ink">{{ customerItems.length ? 'No encontramos clientes' : 'Todavía no hay clientes' }}</h3>
          <p class="mt-1 text-sm text-ink-muted">{{ customerItems.length ? 'Probá con otro nombre, CUIT o estado.' : 'Cuando agregues un cliente, aparecerá acá.' }}</p>
        </div>
      </aside>

      <section v-if="selectedCustomer" class="min-w-0 space-y-5 rounded-2xl border border-border bg-surface-raised p-4 sm:p-6" aria-labelledby="customer-title">
        <header class="flex flex-col gap-4 border-b border-border-subtle pb-5 sm:flex-row sm:items-start sm:justify-between">
          <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-3">
              <h2 id="customer-title" class="text-xl font-bold tracking-tight text-ink">{{ selectedCustomer.nombre }}</h2>
              <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="Number(selectedCustomer.activo) === 1 ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700'">
                {{ Number(selectedCustomer.activo) === 1 ? 'Activo' : 'Inactivo' }}
              </span>
            </div>
            <p class="mt-1 text-sm text-ink-muted">{{ selectedCustomer.identificacion_fiscal || 'Sin CUIT informado' }}</p>
          </div>
          <div v-if="props.data.canEdit" class="flex shrink-0 flex-wrap gap-2">
            <button type="button" :class="secondaryButton" @click="openEdit('clientes', selectedCustomer)">Editar</button>
            <form method="post" :action="selectedCustomer.statusUrl" :data-confirm="Number(selectedCustomer.activo) === 1 ? '' : undefined" :data-confirm-title="Number(selectedCustomer.activo) === 1 ? '¿Inactivar este cliente?' : undefined" :data-confirm-text="Number(selectedCustomer.activo) === 1 ? statusMessage(selectedCustomer) : undefined" :data-confirm-button="Number(selectedCustomer.activo) === 1 ? 'Inactivar' : undefined" :data-confirm-danger="Number(selectedCustomer.activo) === 1 ? 'true' : undefined">
              <input type="hidden" :name="props.data.csrf.name" :value="props.data.csrf.hash">
              <input type="hidden" name="activo" :value="Number(selectedCustomer.activo) === 1 ? 0 : 1">
              <button type="submit" :class="secondaryButton">{{ Number(selectedCustomer.activo) === 1 ? 'Inactivar' : 'Reactivar' }}</button>
            </form>
          </div>
        </header>

        <section aria-labelledby="customer-data-title">
          <h3 id="customer-data-title" class="text-base font-semibold text-ink">Datos del cliente</h3>
          <dl class="mt-4 grid gap-x-8 gap-y-4 sm:grid-cols-2">
            <div>
              <dt class="text-sm text-ink-muted">Nombre o razón social</dt>
              <dd class="mt-1 break-words text-sm font-medium text-ink">{{ selectedCustomer.nombre }}</dd>
            </div>
            <div>
              <dt class="text-sm text-ink-muted">CUIT / identificación fiscal</dt>
              <dd class="mt-1 break-words text-sm font-medium text-ink">{{ selectedCustomer.identificacion_fiscal || 'Sin informar' }}</dd>
            </div>
            <div>
              <dt class="text-sm text-ink-muted">Teléfono</dt>
              <dd class="mt-1 break-words text-sm font-medium text-ink">{{ selectedCustomer.telefono || 'Sin informar' }}</dd>
            </div>
            <div>
              <dt class="text-sm text-ink-muted">Correo electrónico</dt>
              <dd class="mt-1 break-words text-sm font-medium text-ink">{{ selectedCustomer.email || 'Sin informar' }}</dd>
            </div>
          </dl>
        </section>

        <section class="border-t border-border-subtle pt-5" aria-labelledby="customer-domiciles-title">
          <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <h3 id="customer-domiciles-title" class="text-base font-semibold text-ink">Domicilios del cliente</h3>
              <p class="mt-1 text-sm text-ink-muted">{{ customerDomiciles.length }} {{ customerDomiciles.length === 1 ? 'domicilio guardado' : 'domicilios guardados' }}</p>
            </div>
            <button v-if="props.data.canEdit" type="button" :class="primaryButton" :disabled="Number(selectedCustomer.activo) !== 1" @click="openCreate('domicilios', { cliente_id: selectedCustomer.id })">Agregar domicilio</button>
          </div>

          <div v-if="customerDomiciles.length" class="mt-4 overflow-x-auto">
            <table class="w-full min-w-[38rem] border-separate border-spacing-0 text-left text-sm">
              <thead>
                <tr class="bg-surface-muted text-xs font-semibold text-ink-muted">
                  <th class="rounded-l-lg px-3 py-3">Domicilio</th>
                  <th class="px-3 py-3">Localidad</th>
                  <th class="px-3 py-3">País</th>
                  <th class="px-3 py-3">Estado</th>
                  <th class="rounded-r-lg px-3 py-3 text-right">Acciones</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="domicile in customerDomiciles" :key="domicile.id" class="border-b border-border-subtle">
                  <td class="border-b border-border-subtle px-3 py-3">
                    <span class="block font-medium text-ink">{{ domicile.alias }}</span>
                    <span class="mt-1 block text-xs text-ink-muted">{{ domicile.calle }}<template v-if="domicile.codigo_postal"> · {{ domicile.codigo_postal }}</template></span>
                  </td>
                  <td class="border-b border-border-subtle px-3 py-3 text-ink">{{ domicile.localidad }}<template v-if="domicile.provincia">, {{ domicile.provincia }}</template></td>
                  <td class="border-b border-border-subtle px-3 py-3 text-ink">{{ domicile.pais_nombre }}</td>
                  <td class="border-b border-border-subtle px-3 py-3">
                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="Number(domicile.activo) === 1 ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700'">
                      {{ Number(domicile.activo) === 1 ? 'Activo' : 'Inactivo' }}
                    </span>
                  </td>
                  <td class="border-b border-border-subtle px-3 py-3 text-right">
                    <div v-if="props.data.canEdit" class="inline-flex gap-2">
                      <button type="button" class="text-sm font-semibold text-primary hover:underline" @click="openEdit('domicilios', domicile)">Editar</button>
                      <form method="post" :action="domicile.statusUrl" :data-confirm="Number(domicile.activo) === 1 ? '' : undefined" :data-confirm-title="Number(domicile.activo) === 1 ? '¿Inactivar este domicilio?' : undefined" :data-confirm-text="Number(domicile.activo) === 1 ? statusMessage(domicile) : undefined" :data-confirm-button="Number(domicile.activo) === 1 ? 'Inactivar' : undefined" :data-confirm-danger="Number(domicile.activo) === 1 ? 'true' : undefined">
                        <input type="hidden" :name="props.data.csrf.name" :value="props.data.csrf.hash">
                        <input type="hidden" name="activo" :value="Number(domicile.activo) === 1 ? 0 : 1">
                        <button type="submit" class="text-sm font-semibold text-ink-muted hover:text-ink">{{ Number(domicile.activo) === 1 ? 'Inactivar' : 'Reactivar' }}</button>
                      </form>
                    </div>
                    <span v-else class="text-ink-muted">—</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-else class="mt-4 rounded-xl border border-dashed border-border px-5 py-8 text-center">
            <h4 class="font-semibold text-ink">Este cliente todavía no tiene domicilios</h4>
            <p class="mt-1 text-sm text-ink-muted">Podés guardar sus direcciones para reutilizarlas al planificar viajes.</p>
          </div>
        </section>
      </section>

      <section v-else class="flex min-h-72 items-center justify-center rounded-2xl border border-dashed border-border p-8 text-center lg:col-start-2 lg:row-start-1">
        <div>
          <h2 class="font-semibold text-ink">Seleccioná un cliente</h2>
          <p class="mt-1 text-sm text-ink-muted">Sus datos y domicilios aparecerán acá.</p>
        </div>
      </section>
    </section>

    <section v-else class="overflow-hidden rounded-2xl border border-border bg-surface-raised">
      <header class="flex flex-col gap-4 border-b border-border-subtle p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
        <div>
          <h2 class="text-lg font-semibold text-ink">{{ selectedCatalog.label }}</h2>
          <p class="mt-1 text-sm text-ink-muted">Los registros inactivos se conservan para mantener la historia.</p>
        </div>
        <button v-if="props.data.canEdit" type="button" :class="primaryButton" @click="openCreate(selected)">Agregar {{ selectedCatalog.label.toLocaleLowerCase('es') }}</button>
      </header>
      <div v-if="selectedCatalog.items?.length" class="flex flex-col gap-3 border-b border-border-subtle p-4 sm:flex-row sm:items-center sm:p-5">
        <label class="block min-w-0 flex-1">
          <span class="sr-only">Buscar en {{ selectedCatalog.label }}</span>
          <input v-model="catalogQuery" type="search" class="w-full rounded-lg border border-border bg-surface px-3 py-2.5 text-sm text-ink placeholder:text-ink-muted focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20" placeholder="Buscar por nombre o descripción">
        </label>
        <label>
          <span class="sr-only">Filtrar por estado</span>
          <select v-model="catalogStatus" class="w-full rounded-lg border border-border bg-surface px-3 py-2.5 text-sm text-ink sm:w-48">
            <option value="all">Todos los estados</option>
            <option value="active">Activos</option>
            <option value="inactive">Inactivos</option>
          </select>
        </label>
      </div>
      <ul v-if="catalogItems.length" class="divide-y divide-border-subtle">
        <li v-for="item in catalogItems" :key="item.id" class="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
          <div class="min-w-0">
            <h3 class="font-semibold text-ink">{{ catalogItemTitle(selected, item) }}</h3>
            <p v-if="catalogItemDetail(selected, item)" class="mt-1 break-words text-sm text-ink-muted">{{ catalogItemDetail(selected, item) }}</p>
            <span class="mt-2 inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="Number(item.activo) === 1 ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700'">{{ Number(item.activo) === 1 ? 'Activo' : 'Inactivo' }}</span>
          </div>
          <div v-if="props.data.canEdit" class="flex shrink-0 gap-2">
            <button type="button" :class="secondaryButton" @click="openEdit(selected, item)">Editar</button>
            <form method="post" :action="item.statusUrl" :data-confirm="Number(item.activo) === 1 ? '' : undefined" :data-confirm-title="Number(item.activo) === 1 ? '¿Inactivar este registro?' : undefined" :data-confirm-text="Number(item.activo) === 1 ? statusMessage(item) : undefined" :data-confirm-button="Number(item.activo) === 1 ? 'Inactivar' : undefined" :data-confirm-danger="Number(item.activo) === 1 ? 'true' : undefined">
              <input type="hidden" :name="props.data.csrf.name" :value="props.data.csrf.hash">
              <input type="hidden" name="activo" :value="Number(item.activo) === 1 ? 0 : 1">
              <button type="submit" :class="secondaryButton">{{ Number(item.activo) === 1 ? 'Inactivar' : 'Reactivar' }}</button>
            </form>
          </div>
        </li>
      </ul>
      <div v-else class="px-5 py-10 text-center">
        <h3 class="font-semibold text-ink">{{ selectedCatalog.items?.length ? 'No hay resultados para esta búsqueda' : 'Todavía no hay registros' }}</h3>
        <p class="mt-1 text-sm text-ink-muted">{{ selectedCatalog.items?.length ? 'Probá con otro término o estado.' : 'Agregá el primero para empezar a usarlo en los viajes.' }}</p>
      </div>
    </section>

    <details class="rounded-xl border border-border-subtle bg-surface-raised">
      <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-ink">Monedas y países disponibles · Solo consulta</summary>
      <div class="grid gap-5 border-t border-border-subtle p-4 sm:grid-cols-2">
        <section>
          <h2 class="text-sm font-semibold text-ink">Monedas</h2>
          <ul class="mt-3 flex flex-wrap gap-2">
            <li v-for="currency in props.data.currencies" :key="currency.id" class="rounded-lg bg-surface-muted px-3 py-2 text-sm text-ink"><strong>{{ currency.codigo }}</strong> · {{ currency.nombre }}</li>
          </ul>
        </section>
        <section>
          <h2 class="text-sm font-semibold text-ink">Países</h2>
          <ul class="mt-3 flex flex-wrap gap-2">
            <li v-for="country in props.data.countries" :key="country.id" class="rounded-lg bg-surface-muted px-3 py-2 text-sm text-ink">{{ country.nombre }} <span class="text-ink-muted">({{ country.codigo_iso }})</span></li>
          </ul>
        </section>
      </div>
    </details>

    <div v-if="editorOpen" class="fixed inset-0 z-50 flex items-end justify-center bg-black/60 p-0 sm:items-center sm:p-6" @click.self="closeEditor" @keydown.esc.window="closeEditor">
      <section class="max-h-[92vh] w-full max-w-xl overflow-y-auto rounded-t-2xl border border-border bg-surface-raised p-5 shadow-2xl sm:rounded-2xl sm:p-6" role="dialog" aria-modal="true" :aria-labelledby="'trip-master-editor-title'">
        <header class="flex items-start justify-between gap-4">
          <div>
            <p class="text-xs font-bold uppercase tracking-wide text-primary">{{ editing ? 'Editar registro' : 'Nuevo registro' }}</p>
            <h2 id="trip-master-editor-title" class="mt-1 text-xl font-semibold text-ink">{{ formCatalogLabel }}</h2>
            <p v-if="formCatalog === 'domicilios' && selectedCustomer" class="mt-1 text-sm text-ink-muted">Para {{ selectedCustomer.nombre }}</p>
          </div>
          <button type="button" :class="secondaryButton" aria-label="Cerrar formulario" @click="closeEditor">Cerrar</button>
        </header>

        <p v-if="formCatalog === 'liquidaciones' && props.data.employees.length === 0" class="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">Para configurar porcentajes necesitás permiso para consultar empleados activos de tu empresa.</p>
        <form class="mt-5 space-y-4" method="post" :action="actionForForm">
          <input type="hidden" :name="props.data.csrf.name" :value="props.data.csrf.hash">
          <input v-if="!editing" type="hidden" name="catalogo" :value="formCatalog">
          <input v-if="formCatalog === 'domicilios'" type="hidden" name="cliente_id" :value="form.cliente_id">
          <label v-for="field in catalogFields" :key="field.key" class="block text-sm font-medium text-ink">
            {{ field.label }} <span v-if="field.required" class="text-red-700">*</span>
            <select v-if="field.type === 'country'" v-model="form[field.key]" :name="field.key" :required="field.required" :class="inputClass">
              <option value="">Elegí un país</option><option v-for="country in props.data.countries.filter((row) => Number(row.activo))" :key="country.id" :value="country.id">{{ country.nombre }}</option>
            </select>
            <select v-else-if="field.type === 'employee'" v-model="form[field.key]" :name="field.key" :required="field.required" :disabled="props.data.employees.length === 0" :class="inputClass">
              <option value="">Elegí un empleado</option><option v-for="employee in props.data.employees" :key="employee.id" :value="employee.id">{{ employee.name }}</option>
            </select>
            <textarea v-else-if="field.key === 'descripcion'" v-model="form[field.key]" :name="field.key" rows="3" :class="inputClass" />
            <input v-else v-model="form[field.key]" :name="field.key" :type="field.type ?? 'text'" :min="field.min" :max="field.max" :step="field.step" :required="field.required" :class="inputClass">
          </label>
          <div class="flex flex-col-reverse gap-2 border-t border-border-subtle pt-4 sm:flex-row sm:justify-end">
            <button type="button" :class="secondaryButton" @click="closeEditor">Cancelar</button>
            <button type="submit" :disabled="formCatalog === 'liquidaciones' && props.data.employees.length === 0" :class="primaryButton">{{ editing ? 'Guardar cambios' : 'Guardar' }}</button>
          </div>
        </form>
      </section>
    </div>
  </div>
</template>
