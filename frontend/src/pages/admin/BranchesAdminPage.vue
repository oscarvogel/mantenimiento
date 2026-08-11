<script setup>
import {
  BellAlertIcon,
  BuildingOffice2Icon,
  EnvelopeIcon,
  MapPinIcon,
  PlusIcon,
} from '@heroicons/vue/24/outline'
import AdminFlash from './components/AdminFlash.vue'
import AdminMetric from './components/AdminMetric.vue'
import AdminPageHeading from './components/AdminPageHeading.vue'
import CsrfField from './components/CsrfField.vue'
import StatusBadge from './components/StatusBadge.vue'
import PaginationBar from '../operations/components/PaginationBar.vue'

defineProps({
  data: {
    type: Object,
    required: true,
  },
})
</script>

<template>
  <div class="admin-branches">
    <AdminPageHeading
      :eyebrow="data.company.name"
      title="Sucursales"
      description="Administrá las bases, talleres y ubicaciones operativas de tu empresa."
    >
      <template #aside>
        <div class="inline-flex items-center gap-2 self-start rounded-lg border border-border bg-white px-3 py-2 text-sm font-semibold text-ink-muted shadow-sm">
          <BuildingOffice2Icon class="size-5 text-primary" aria-hidden="true" />
          Una sola empresa
        </div>
      </template>
    </AdminPageHeading>

    <AdminFlash :success="data.flash.success" :error="data.flash.error" />

    <section aria-label="Resumen de sucursales" class="mb-6 grid gap-3 sm:grid-cols-3">
      <AdminMetric label="Registradas" :value="data.metrics.total" />
      <AdminMetric label="Activas" :value="data.metrics.active" tone="success" />
      <AdminMetric label="Inactivas" :value="data.metrics.inactive" tone="muted" />
    </section>

    <section v-if="data.permissions.edit" class="mb-8 overflow-hidden rounded-xl border border-border bg-surface-raised shadow-card" aria-labelledby="new-branch-title">
      <div class="flex items-center gap-3 border-b border-border-subtle bg-surface-subtle px-5 py-4 sm:px-6">
        <span class="flex size-10 items-center justify-center rounded-lg bg-primary-subtle text-primary">
          <PlusIcon class="size-5" aria-hidden="true" />
        </span>
        <div>
          <h2 id="new-branch-title" class="font-semibold text-ink">Nueva sucursal</h2>
          <p class="text-sm text-ink-muted">Definí su identificación y el canal de alertas.</p>
        </div>
      </div>

      <form method="post" :action="data.actions.create" class="grid gap-4 p-5 sm:grid-cols-2 sm:p-6 lg:grid-cols-12">
        <CsrfField :csrf="data.csrf" />
        <label class="block lg:col-span-3">
          <span class="mb-1.5 block text-sm font-medium text-ink">Código <span class="text-danger" aria-hidden="true">*</span></span>
          <input name="codigo" maxlength="20" required :value="data.oldInput.codigo" autocapitalize="characters" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm font-semibold uppercase text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
        </label>
        <label class="block lg:col-span-5">
          <span class="mb-1.5 block text-sm font-medium text-ink">Nombre <span class="text-danger" aria-hidden="true">*</span></span>
          <input name="nombre" maxlength="255" required :value="data.oldInput.nombre" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
        </label>
        <label class="block lg:col-span-4">
          <span class="mb-1.5 block text-sm font-medium text-ink">Email de alertas</span>
          <input type="email" name="email_alertas" maxlength="255" :value="data.oldInput.emailAlertas" autocomplete="email" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
        </label>
        <label class="block sm:col-span-2 lg:col-span-12">
          <span class="mb-1.5 block text-sm font-medium text-ink">Dirección</span>
          <input name="direccion" maxlength="255" :value="data.oldInput.direccion" autocomplete="street-address" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
        </label>
        <div class="sm:col-span-2 lg:col-span-12">
          <button type="submit" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground shadow-sm transition-colors hover:bg-primary-hover active:bg-primary-active">
            <MapPinIcon class="size-5" aria-hidden="true" />
            Crear sucursal
          </button>
        </div>
      </form>
    </section>

    <section aria-labelledby="branches-list-title">
      <div class="mb-4 flex items-center justify-between gap-3">
        <div>
          <h2 id="branches-list-title" class="text-lg font-bold text-ink">Sucursales registradas</h2>
          <p class="mt-1 text-sm text-ink-muted">El alcance se mantiene limitado a {{ data.company.name }}.</p>
        </div>
        <span class="rounded-full bg-surface-muted px-3 py-1 text-sm font-semibold text-ink-muted">{{ data.metrics.total }}</span>
      </div>

      <div v-if="data.branches.length" class="grid gap-4 xl:grid-cols-2">
        <article v-for="branch in data.branches" :key="branch.id" class="overflow-hidden rounded-xl border border-border bg-surface-raised shadow-card">
          <div class="flex items-start justify-between gap-4 border-b border-border-subtle px-5 py-4">
            <div class="flex min-w-0 items-center gap-3">
              <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary-subtle text-primary">
                <MapPinIcon class="size-5" aria-hidden="true" />
              </span>
              <div class="min-w-0">
                <h3 class="truncate font-semibold text-ink">{{ branch.name }}</h3>
                <p class="text-xs font-bold uppercase tracking-wide text-primary">{{ branch.code }}</p>
              </div>
            </div>
            <StatusBadge :active="branch.active" active-label="Activa" inactive-label="Inactiva" />
          </div>

          <form v-if="data.permissions.edit" method="post" :action="branch.actions.update" class="grid gap-4 p-5 sm:grid-cols-12">
            <CsrfField :csrf="data.csrf" />
            <label class="block sm:col-span-4">
              <span class="mb-1.5 block text-sm font-medium text-ink">Código</span>
              <input name="codigo" maxlength="20" required :value="branch.code" autocapitalize="characters" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm font-semibold uppercase text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
            </label>
            <label class="block sm:col-span-8">
              <span class="mb-1.5 block text-sm font-medium text-ink">Nombre</span>
              <input name="nombre" maxlength="255" required :value="branch.name" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
            </label>
            <label class="block sm:col-span-12">
              <span class="mb-1.5 block text-sm font-medium text-ink">Dirección</span>
              <input name="direccion" maxlength="255" :value="branch.address" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
            </label>
            <label class="block sm:col-span-8">
              <span class="mb-1.5 block text-sm font-medium text-ink">Email de alertas</span>
              <input type="email" name="email_alertas" maxlength="255" :value="branch.alertEmail" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
            </label>
            <label class="block sm:col-span-4">
              <span class="mb-1.5 block text-sm font-medium text-ink">Estado</span>
              <select name="estado" :value="branch.active ? '1' : '0'" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20">
                <option value="1">Activa</option>
                <option value="0">Inactiva</option>
              </select>
            </label>
            <div class="sm:col-span-12">
              <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-lg border border-primary px-4 py-2.5 text-sm font-semibold text-primary transition-colors hover:bg-primary-subtle">Guardar cambios</button>
            </div>
          </form>

          <dl v-else class="grid gap-4 p-5 text-sm sm:grid-cols-2">
            <div class="flex gap-2"><MapPinIcon class="size-5 shrink-0 text-ink-subtle" aria-hidden="true" /><div><dt class="text-ink-subtle">Dirección</dt><dd class="mt-1 font-medium text-ink">{{ branch.address || 'Sin dirección' }}</dd></div></div>
            <div class="flex gap-2"><EnvelopeIcon class="size-5 shrink-0 text-ink-subtle" aria-hidden="true" /><div><dt class="text-ink-subtle">Alertas</dt><dd class="mt-1 break-all font-medium text-ink">{{ branch.alertEmail || 'Sin email' }}</dd></div></div>
          </dl>

          <div v-if="branch.alertEmail" class="flex items-center gap-2 border-t border-border-subtle bg-surface-subtle px-5 py-3 text-xs font-medium text-ink-muted">
            <BellAlertIcon class="size-4 text-primary" aria-hidden="true" />
            Recibe alertas en {{ branch.alertEmail }}
          </div>
        </article>
      </div>

      <div v-else class="rounded-xl border border-dashed border-border-strong bg-surface-raised px-6 py-10 text-center">
        <MapPinIcon class="mx-auto size-10 text-ink-subtle" aria-hidden="true" />
        <p class="mt-3 font-semibold text-ink">Todavía no hay sucursales</p>
        <p class="mt-1 text-sm text-ink-muted">Creá la primera ubicación operativa de la empresa.</p>
      </div>

      <PaginationBar :pagination="data.pagination" />
    </section>
  </div>
</template>
