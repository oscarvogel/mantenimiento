<script setup>
import { computed } from 'vue'

const props = defineProps({
  viajes: { type: Array, default: () => [] },
  emptyText: {
    type: String,
    default: 'No hay viajes en curso ni finalizados en el período y la sucursal seleccionados.',
  },
})

const number = new Intl.NumberFormat('es-AR', { maximumFractionDigits: 0 })
const decimal = new Intl.NumberFormat('es-AR', { maximumFractionDigits: 1 })
const dateTime = new Intl.DateTimeFormat('es-AR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })

const SIN_DATOS = 'Sin datos'
const vacio = (value) => value === null || value === undefined
const entero = (value, sufijo = '') => vacio(value) ? SIN_DATOS : `${number.format(Number(value))}${sufijo}`
const unDecimal = (value, sufijo = '') => vacio(value) ? SIN_DATOS : `${decimal.format(Number(value))}${sufijo}`
const conSigno = (value, sufijo = '') => vacio(value) ? SIN_DATOS : `${Number(value) > 0 ? '+' : ''}${decimal.format(Number(value))}${sufijo}`
const fecha = (value) => {
  if (!value) return SIN_DATOS
  const parsed = new Date(String(value).replace(' ', 'T'))
  return Number.isNaN(parsed.getTime()) ? SIN_DATOS : dateTime.format(parsed)
}

// Lo medido sin el viaje completo a la vista se muestra igual, marcado: es lo que
// registró el sensor, no un veredicto.
const esParcial = (viaje) => viaje?.medido?.completo === false
  && (!vacio(viaje.medido?.litros) || !vacio(viaje.medido?.consumo) || !vacio(viaje.medido?.km))

const tono = {
  ok: 'bg-success-subtle text-success-strong',
  aviso: 'bg-warning-subtle text-warning-strong',
  peligro: 'bg-danger-subtle text-danger-strong',
  info: 'bg-info-subtle text-info-strong',
  neutro: 'bg-surface-muted text-ink-muted',
}
const conciliacion = {
  CONCILIADO: tono.ok,
  DECLARADO_SUPERA: tono.peligro,
  DECLARADO_MENOR: tono.aviso,
  SIN_DATOS_SUFICIENTES: tono.neutro,
  EN_CURSO: tono.info,
}

const hayViajes = computed(() => props.viajes.length > 0)
</script>

<template>
  <section class="mt-6 overflow-hidden rounded-xl border border-border bg-surface-raised" aria-labelledby="fuel-reconciliation-heading">
    <div class="border-b border-border px-5 py-4 sm:px-6">
      <h2 id="fuel-reconciliation-heading" class="text-lg font-bold text-ink">Declarado por el chofer contra el sensor del tanque</h2>
      <p class="mt-1 text-sm leading-6 text-ink-muted">
        Se compara por viaje, porque las cargas se declaran todas al volver. Sin veredicto cuando la telemetría no vio el
        viaje completo: un corte del GPS no se imputa al chofer.
      </p>
    </div>
    <div v-if="hayViajes" class="overflow-x-auto">
      <table class="min-w-full divide-y divide-border text-left text-sm">
        <thead class="bg-surface-muted text-xs font-semibold uppercase tracking-wide text-ink-muted">
          <tr>
            <th scope="col" class="px-4 py-3">Viaje</th>
            <th scope="col" class="px-4 py-3">Resultado</th>
            <th scope="col" class="px-4 py-3 text-right">Declarado</th>
            <th scope="col" class="px-4 py-3 text-right">Detectado</th>
            <th scope="col" class="px-4 py-3 text-right">Diferencia</th>
            <th scope="col" class="px-4 py-3 text-right">L/100 km decl.</th>
            <th scope="col" class="px-4 py-3 text-right">Consumo medido</th>
            <th scope="col" class="px-4 py-3 text-right">Sensor</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border-subtle">
          <tr v-for="viaje in viajes" :key="viaje.viajeId" class="align-top hover:bg-surface-subtle">
            <th scope="row" class="whitespace-nowrap px-4 py-3 font-semibold text-ink">
              Nº {{ viaje.numero }} · {{ viaje.patente ?? 'Sin tractor' }}
              <span class="block text-xs font-normal text-ink-muted">{{ fecha(viaje.desde) }} → {{ fecha(viaje.hasta) }}</span>
            </th>
            <td class="min-w-56 px-4 py-3">
              <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="conciliacion[viaje.estado] ?? tono.neutro">{{ viaje.estadoEtiqueta }}</span>
              <span v-if="viaje.motivo" class="mt-1 block text-xs leading-5 text-ink-muted">{{ viaje.motivo }}</span>
            </td>
            <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-ink">
              {{ entero(viaje.declarado?.litros, ' L') }}
              <span class="block text-xs text-ink-muted">{{ entero(viaje.declarado?.cargas) }} cargas</span>
            </td>
            <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-ink">
              {{ entero(viaje.medido?.litros, ' L') }}
              <span class="block text-xs text-ink-muted">{{ entero(viaje.medido?.cargas) }} cargas</span>
              <span
                v-if="esParcial(viaje)"
                class="mt-1 inline-block rounded-full bg-warning-subtle px-2 py-0.5 text-[11px] font-semibold text-warning-strong"
                title="La telemetría no vio el viaje completo: son las cargas que registró el sensor (al menos esos litros). Sin veredicto ni consumo."
              >Parcial</span>
            </td>
            <td class="whitespace-nowrap px-4 py-3 text-right font-semibold tabular-nums text-ink">
              {{ conSigno(viaje.diferencia?.litros, ' L') }}
              <span class="block text-xs font-normal text-ink-muted">{{ conSigno(viaje.diferencia?.porcentaje, '%') }}</span>
            </td>
            <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-ink-muted">{{ unDecimal(viaje.declarado?.litrosPor100Km) }}</td>
            <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-ink-muted">
              {{ unDecimal(viaje.medido?.litrosPor100Km) }}
              <span class="block text-xs">{{ vacio(viaje.medido?.consumo) ? 'Consumo: sin datos' : `Consumo: ${entero(viaje.medido.consumo, ' L')}` }}</span>
              <span v-if="!vacio(viaje.medido?.km)" class="block text-xs">{{ entero(viaje.medido.km, ' km GPS') }}</span>
            </td>
            <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-ink-muted">{{ entero(viaje.medido?.coberturaSensor, '%') }}</td>
          </tr>
        </tbody>
      </table>
    </div>
    <p v-else role="status" class="px-5 py-10 text-center text-sm leading-6 text-ink-muted">
      {{ emptyText }}
    </p>
  </section>
</template>
