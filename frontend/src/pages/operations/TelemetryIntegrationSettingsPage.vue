<script setup>
import { computed } from 'vue'
import PageHeading from './components/PageHeading.vue'
import { fieldClass, primaryButton } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })
const integrations = computed(() => props.data.integrations ?? [])
</script>

<template>
  <div class="space-y-6">
    <PageHeading
      eyebrow="Administración · Integraciones"
      title="Telemetría de la empresa"
      description="Conectá el proveedor de telemetría de tu empresa. La conexión se guarda aquí y el sistema asocia los equipos que coinciden por patente."
    />

    <section class="rounded-2xl border border-border bg-surface-raised p-5 shadow-card sm:p-6">
      <form method="post" :action="data.actions.save" class="grid gap-5">
        <input type="hidden" :name="data.csrf.name" :value="data.csrf.hash" />

        <label class="block">
          <span class="mb-1.5 block text-sm font-semibold text-ink">Proveedor</span>
          <select name="provider" required :class="fieldClass">
            <option value="wialon">Wialon</option>
          </select>
        </label>

        <label class="block">
          <span class="mb-1.5 block text-sm font-semibold text-ink">Nombre de la cuenta <span class="font-normal text-ink-muted">(opcional)</span></span>
          <input name="name" maxlength="100" placeholder="Wialon" :class="fieldClass" />
          <span class="mt-1 block text-xs text-ink-muted">Usá un nombre distinto si tu empresa tiene más de una cuenta del mismo proveedor.</span>
        </label>

        <label class="block">
          <span class="mb-1.5 block text-sm font-semibold text-ink">Token</span>
          <textarea name="token" rows="3" required maxlength="4096" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="Pegá acá el token del proveedor" :class="fieldClass" />
          <span class="mt-1 block text-xs text-ink-muted">Se valida al guardar y queda cifrado en la configuración de tu empresa. No se vuelve a mostrar.</span>
        </label>

        <div class="rounded-xl border border-primary/20 bg-primary-subtle p-4 text-sm leading-6 text-ink">
          Al guardar, Wialon valida el token y se vinculan automáticamente los equipos cuya patente coincide con el nombre de la unidad. Si alguno no coincide, te lo vamos a indicar.
        </div>

        <div class="flex flex-wrap gap-3">
          <button type="submit" :class="primaryButton">Guardar y conectar</button>
        </div>
      </form>
    </section>

    <section v-if="integrations.length" class="rounded-2xl border border-border bg-surface-raised p-5 shadow-card sm:p-6">
      <h2 class="text-base font-semibold text-ink">Proveedores conectados en esta empresa</h2>
      <ul class="mt-4 divide-y divide-border">
        <li v-for="integration in integrations" :key="`${integration.provider}-${integration.name}`" class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
          <div>
            <p class="font-semibold text-ink">{{ integration.name }} <span class="font-normal text-ink-muted">· {{ integration.provider }}</span></p>
            <p class="mt-1 text-sm text-ink-muted">{{ integration.linkedEquipmentCount }} equipos vinculados<span v-if="integration.lastSuccess"> · última consulta correcta {{ integration.lastSuccess }}</span></p>
          </div>
          <span class="rounded-full bg-success-subtle px-3 py-1 text-xs font-bold text-success">{{ integration.active ? 'Activo' : 'Inactivo' }}</span>
        </li>
      </ul>
    </section>
  </div>
</template>
