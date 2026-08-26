<script setup>
import { CpuChipIcon } from '@heroicons/vue/24/outline'
import CsrfField from './CsrfField.vue'

defineProps({
  companies: {
    type: Array,
    default: () => [],
  },
  csrf: {
    type: Object,
    required: true,
  },
})
</script>

<template>
  <section v-if="companies.length" class="mt-8 overflow-hidden rounded-xl border border-border bg-surface-raised shadow-card" aria-labelledby="company-ai-title">
    <div class="flex items-center gap-3 border-b border-border-subtle bg-surface-subtle px-5 py-4 sm:px-6">
      <span class="flex size-10 items-center justify-center rounded-lg bg-primary-subtle text-primary">
        <CpuChipIcon class="size-5" aria-hidden="true" />
      </span>
      <div>
        <h2 id="company-ai-title" class="font-semibold text-ink">Inteligencia Artificial por empresa</h2>
        <p class="text-sm text-ink-muted">Habilitá el módulo únicamente para las empresas que tengan contratado su uso.</p>
      </div>
    </div>

    <div class="divide-y divide-border-subtle">
      <form
        v-for="company in companies"
        :key="company.id"
        method="post"
        :action="company.action"
        data-confirm
        data-confirm-title="¿Cambiar el acceso a Inteligencia Artificial?"
        :data-confirm-text="company.enabled ? 'La empresa dejará de poder utilizar cualquier función de IA.' : 'La empresa podrá utilizar las funciones de IA habilitadas en el sistema.'"
        data-confirm-button="Guardar configuración"
        class="flex flex-col gap-4 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6"
      >
        <CsrfField :csrf="csrf" />
        <input v-for="(value, name) in company.fields" :key="name" type="hidden" :name="name" :value="value" />
        <input type="hidden" name="ia_habilitada" :value="company.enabled ? '0' : '1'" />

        <div>
          <div class="font-semibold text-ink">{{ company.displayName }}</div>
          <div class="mt-1 text-xs text-ink-muted">Empresa #{{ company.id }} · {{ company.enabled ? 'IA habilitada' : 'IA deshabilitada' }}</div>
        </div>

        <button
          type="submit"
          class="inline-flex min-h-11 items-center justify-center rounded-lg border px-4 py-2.5 text-sm font-semibold transition-colors"
          :class="company.enabled ? 'border-danger text-danger-strong hover:bg-danger-subtle' : 'border-primary text-primary hover:bg-primary-subtle'"
        >
          {{ company.enabled ? 'Deshabilitar IA' : 'Habilitar IA' }}
        </button>
      </form>
    </div>

    <div class="border-t border-border-subtle bg-surface-subtle px-5 py-3 text-xs leading-5 text-ink-muted sm:px-6">
      Al deshabilitar IA se bloquean inmediatamente el asistente y los análisis inteligentes. No se realizan llamadas al proveedor para esa empresa.
    </div>
  </section>
</template>
