<script setup>
import CsrfField from './CsrfField.vue'

const props = defineProps({
  data: {
    type: Object,
    required: true,
  },
})

const confirmImport = (event) => {
  if (!window.confirm('Se agregarán a TSA solo las opciones faltantes de los cinco catálogos. No se modificarán registros existentes ni se cargarán porcentajes de liquidación. ¿Continuar?')) {
    event.preventDefault()
  }
}
</script>

<template>
  <section class="rounded-2xl border border-primary/20 bg-surface-raised p-5 shadow-sm sm:p-6" aria-labelledby="tsa-trip-catalogs-title">
    <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
      <div class="max-w-3xl">
        <p class="text-xs font-bold uppercase tracking-[0.14em] text-primary">Solo Superadministrador</p>
        <h2 id="tsa-trip-catalogs-title" class="mt-1 text-lg font-bold text-ink">Completar maestros de Viajes para TSA</h2>
        <p class="mt-1 text-sm leading-6 text-ink-muted">
          Agrega opciones que falten en Tipos de carga, Unidades de carga, Categorías de gastos, Medios de pago y Tipos de documento. Conserva lo que ya existe y omite los porcentajes de liquidación.
        </p>
      </div>
      <form method="post" :action="props.data.tripMasterCatalogsAction" class="shrink-0" @submit="confirmImport">
        <CsrfField :csrf="props.data.csrf" />
        <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground shadow-sm transition hover:bg-primary/90">
          Completar catálogos de TSA
        </button>
      </form>
    </div>
  </section>
</template>
