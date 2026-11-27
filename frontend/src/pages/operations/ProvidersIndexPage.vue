<script setup>
import { computed } from 'vue'
import { BuildingOffice2Icon, EnvelopeIcon, MapPinIcon, PhoneIcon, PlusIcon } from '@heroicons/vue/24/outline'
import CsrfInput from './components/CsrfInput.vue'
import EmptyState from './components/EmptyState.vue'
import FilterChip from '../../components/ui/FilterChip.vue'
import PageHeading from './components/PageHeading.vue'
import StatusBadge from './components/StatusBadge.vue'
import { fieldClass, primaryButton, secondaryButton } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })

// Píldoras de estado con los conteos de todo el catálogo; conservan la búsqueda.
const statusChips = computed(() => {
  const { filters, metrics, actions } = props.data
  const href = (status) => {
    const query = new URLSearchParams()
    if (filters?.q) query.set('q', filters.q)
    if (status) query.set('estado', status)
    const text = query.toString()
    return text ? `${actions.index}?${text}` : actions.index
  }
  return [['Activos', '', metrics.active], ['Inactivos', 'inactivo', metrics.inactive]]
    .map(([label, status, count]) => ({ label, count, href: href(status), active: (filters?.status ?? '') === status }))
})
</script>

<template>
  <div>
    <PageHeading eyebrow="Catálogo operativo" title="Proveedores y talleres" description="Mantené un contacto confiable para documentar servicios, repuestos y garantías de cada orden.">
      <template #actions><a :href="data.actions.index" :class="`${secondaryButton} w-full justify-center sm:w-auto`">Actualizar</a></template>
    </PageHeading>

    <nav aria-label="Proveedores por estado" class="-mx-1 mb-4 flex gap-2 overflow-x-auto px-1 pb-2 pt-1 [scrollbar-width:none]">
      <FilterChip v-for="chip in statusChips" :key="chip.label" :label="chip.label" :count="chip.count" :href="chip.href" :active="chip.active" />
    </nav>

    <details v-if="data.permissions.edit" class="ui-details-animated mb-6 overflow-hidden rounded-card bg-surface-raised shadow-card" :open="Boolean(data.flash?.error)">
      <summary class="flex cursor-pointer items-center gap-3 px-5 py-4"><span class="flex size-10 items-center justify-center rounded-lg bg-primary-subtle text-primary"><PlusIcon class="size-5" aria-hidden="true" /></span><span><strong class="text-ink">Nuevo proveedor o taller</strong><span class="mt-0.5 block text-sm font-normal text-ink-muted">Cargá los datos que necesitarás al cerrar una orden.</span></span></summary>
      <form method="post" :action="data.actions.create" class="grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-4"><CsrfInput :csrf="data.csrf" /><label class="grid gap-1.5 text-sm font-semibold text-ink sm:col-span-2">Razón social *<input name="razon_social" required minlength="2" maxlength="255" :class="fieldClass" /></label><label class="grid gap-1.5 text-sm font-semibold text-ink">CUIT<input name="cuit" maxlength="20" inputmode="numeric" :class="fieldClass" /></label><label class="grid gap-1.5 text-sm font-semibold text-ink">Especialidad<input name="especialidad" maxlength="255" placeholder="Ej.: hidráulica" :class="fieldClass" /></label><label class="grid gap-1.5 text-sm font-semibold text-ink">Email<input name="email" type="email" maxlength="255" :class="fieldClass" /></label><label class="grid gap-1.5 text-sm font-semibold text-ink">Teléfono<input name="telefono" maxlength="50" :class="fieldClass" /></label><label class="grid gap-1.5 text-sm font-semibold text-ink sm:col-span-2">Dirección<input name="direccion" maxlength="255" :class="fieldClass" /></label><label class="flex min-h-11 items-center gap-2 text-sm font-semibold text-ink"><input type="checkbox" name="es_taller" value="1" class="size-4 accent-primary" /> Es taller</label><label class="flex min-h-11 items-center gap-2 text-sm font-semibold text-ink"><input type="checkbox" name="es_proveedor" value="1" checked class="size-4 accent-primary" /> Es proveedor</label><label class="grid gap-1.5 text-sm font-semibold text-ink sm:col-span-2 lg:col-span-4">Observaciones<textarea name="observaciones" maxlength="2000" rows="2" :class="fieldClass"></textarea></label><div class="sm:col-span-2 lg:col-span-4"><button type="submit" :class="primaryButton">Guardar proveedor</button></div></form>
    </details>

    <form method="get" :action="data.actions.index" class="mb-5 grid gap-3 rounded-card bg-surface-raised p-4 sm:grid-cols-[minmax(0,1fr)_12rem_auto] sm:items-end shadow-card"><label class="grid gap-1.5 text-sm font-semibold text-ink">Buscar<input name="q" type="search" :value="data.filters.q" placeholder="Nombre, CUIT o especialidad" :class="fieldClass" /></label><label class="grid gap-1.5 text-sm font-semibold text-ink">Estado<select name="estado" :value="data.filters.status" :class="fieldClass"><option value="">Activos</option><option value="inactivo">Inactivos</option></select></label><button type="submit" :class="primaryButton">Filtrar</button></form>

    <section class="grid gap-4 xl:grid-cols-2" aria-label="Listado de proveedores"><article v-for="provider in data.providers" :key="provider.id" class="rounded-card bg-surface-raised p-5 shadow-card"><header class="flex items-start justify-between gap-3"><div class="flex min-w-0 items-center gap-3"><span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary-subtle text-primary"><BuildingOffice2Icon class="size-5" aria-hidden="true" /></span><div class="min-w-0"><h2 class="truncate font-bold text-ink">{{ provider.name }}</h2><p class="text-sm text-ink-muted">{{ provider.taxId || 'CUIT no informado' }}</p></div></div><StatusBadge :status="provider.active ? 'ACTIVO' : 'BAJA'" /></header><div class="mt-4 flex flex-wrap gap-2 text-xs font-semibold"><span v-if="provider.workshop" class="rounded-full bg-info-subtle px-2.5 py-1 text-info-strong">Taller</span><span v-if="provider.supplier" class="rounded-full bg-primary-subtle px-2.5 py-1 text-primary">Proveedor</span><span v-if="provider.specialty" class="rounded-full bg-surface-muted px-2.5 py-1 text-ink-muted">{{ provider.specialty }}</span></div><dl class="mt-4 grid gap-2 text-sm text-ink-muted"><div v-if="provider.email" class="flex items-center gap-2"><EnvelopeIcon class="size-4 text-ink-subtle" aria-hidden="true" />{{ provider.email }}</div><div v-if="provider.phone" class="flex items-center gap-2"><PhoneIcon class="size-4 text-ink-subtle" aria-hidden="true" />{{ provider.phone }}</div><div v-if="provider.address" class="flex items-center gap-2"><MapPinIcon class="size-4 text-ink-subtle" aria-hidden="true" />{{ provider.address }}</div></dl><form v-if="data.permissions.edit" method="post" :action="`${data.actions.index}/${provider.id}`" class="mt-5 grid gap-3 rounded-2xl bg-surface-subtle p-4 sm:grid-cols-2"><CsrfInput :csrf="data.csrf" /><label class="grid gap-1.5 text-sm font-semibold text-ink sm:col-span-2">Razón social<input name="razon_social" required maxlength="255" :value="provider.name" :class="fieldClass" /></label><label class="grid gap-1.5 text-sm font-semibold text-ink">Email<input name="email" type="email" maxlength="255" :value="provider.email" :class="fieldClass" /></label><label class="grid gap-1.5 text-sm font-semibold text-ink">Teléfono<input name="telefono" maxlength="50" :value="provider.phone" :class="fieldClass" /></label><label class="flex items-center gap-2 text-sm text-ink"><input type="checkbox" name="es_taller" value="1" :checked="provider.workshop" class="size-4 accent-primary" /> Taller</label><label class="flex items-center gap-2 text-sm text-ink"><input type="checkbox" name="es_proveedor" value="1" :checked="provider.supplier" class="size-4 accent-primary" /> Proveedor</label><div class="flex flex-wrap gap-2 sm:col-span-2"><button type="submit" :class="secondaryButton">Guardar cambios</button><button formaction="" type="submit" class="sr-only" tabindex="-1" aria-hidden="true"></button></div></form><form v-if="data.permissions.edit" method="post" :action="`${data.actions.index}/${provider.id}/estado`" class="mt-2"><CsrfInput :csrf="data.csrf" /><button type="submit" class="text-sm font-semibold text-primary hover:text-primary-hover">{{ provider.active ? 'Inactivar proveedor' : 'Reactivar proveedor' }}</button></form></article><EmptyState v-if="data.providers.length === 0" title="No hay proveedores en este filtro" description="Podés crear el primer taller o proveedor desde el botón de arriba." /></section>
  </div>
</template>
