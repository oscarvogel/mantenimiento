<script setup>
import { computed } from 'vue'
import PageHeading from './components/PageHeading.vue'
import PanelCard from './components/PanelCard.vue'

const props = defineProps({ data: { type: Object, required: true } })
const trip = computed(() => props.data.trip ?? {})
const sections = computed(() => [
  { key: 'segments', title: 'Tramos', columns: [['item', 'Ítem'], ['codigo_origen_destino', 'Código de recorrido'], ['descripcion_origen', 'Origen'], ['descripcion_destino', 'Destino'], ['cantidad_kilometros', 'Km informados'], ['toneladas', 'Toneladas'], ['codigo_cliente_origen', 'Código cliente de origen'], ['cliente_id', 'Cliente vinculado'], ['remito', 'Remito'], ['codigo_alternativo', 'Código alternativo'], ['fecha_salida', 'Salida'], ['fecha_llegada', 'Llegada'], ['precio', 'Precio'], ['importe', 'Importe'], ['importe_inicial', 'Importe inicial'], ['porcentaje_facturacion', '% facturación'], ['cobrado', 'Cobrado'], ['observaciones_1', 'Observación 1'], ['observaciones_2', 'Observación 2'], ['observaciones_3', 'Observación 3'], ['observaciones_4', 'Observación 4']] },
  { key: 'fuel', title: 'Combustible', columns: [['item', 'Ítem'], ['fecha', 'Fecha'], ['codigo_concepto', 'Concepto'], ['litros', 'Litros'], ['importe', 'Importe'], ['kilometraje_actual', 'Odómetro'], ['observaciones', 'Observaciones']] },
  { key: 'expenses', title: 'Gastos', columns: [['item', 'Ítem'], ['fecha', 'Fecha'], ['codigo_concepto', 'Concepto'], ['importe', 'Importe'], ['observaciones', 'Observaciones']] },
  { key: 'accounts', title: 'Cuenta corriente del viaje', columns: [['item', 'Ítem'], ['fecha', 'Fecha'], ['codigo_concepto', 'Concepto'], ['debitos', 'Débitos'], ['creditos', 'Créditos'], ['debitos_moneda', 'Débitos en moneda'], ['creditos_moneda', 'Créditos en moneda'], ['importe_moneda', 'Importe en moneda'], ['id_moneda', 'Moneda (ID)'], ['cotizacion', 'Cotización'], ['observaciones', 'Observaciones']] },
  { key: 'accountSegments', title: 'Comprobantes asociados', columns: [['item', 'Ítem'], ['sub_item', 'Subítem'], ['tipo_comprobante', 'Tipo'], ['punto_venta', 'Punto de venta'], ['numero_comprobante', 'Número'], ['fecha', 'Fecha'], ['importe', 'Importe']] },
  { key: 'otherEarnings', title: 'Otros haberes', columns: [['item', 'Ítem'], ['fecha', 'Fecha'], ['codigo_concepto', 'Concepto'], ['importe', 'Importe'], ['observaciones', 'Observaciones']] },
])

const display = (value) => value === null || value === undefined || value === '' ? '—' : String(value)
const date = (value) => {
  const match = String(value ?? '').match(/^(\d{4})-(\d{2})-(\d{2})$/)
  return match ? `${match[3]}/${match[2]}/${match[1]}` : display(value)
}
const formatted = (key, value) => key.startsWith('fecha') ? date(value) : display(value)
const facts = computed(() => [
  ['Dominio / unidad', trip.value.dominio_origen],
  ['Número de viaje', trip.value.numero_viaje],
  ['CUIT de empresa de origen', trip.value.cuit_origen],
  ['Origen de datos', trip.value.origen],
  ['Sucursal', trip.value.sucursal_id ? `#${trip.value.sucursal_id}` : null],
  ['Fecha de salida', date(trip.value.fecha_salida)],
  ['Fecha de regreso', date(trip.value.fecha_regreso)],
  ['Odómetro de salida', trip.value.km_salida],
  ['Odómetro de regreso', trip.value.km_regreso],
  ['Chofer (legajo de origen)', trip.value.legajo_chofer_origen],
  ['Chofer vinculado', trip.value.empleado_id],
  ['Equipo vinculado', trip.value.equipo_id],
  ['Porcentaje del chofer', trip.value.porcentaje_chofer === null ? null : `${trip.value.porcentaje_chofer}%`],
  ['Contabilizado', trip.value.contabilizado === null ? null : Number(trip.value.contabilizado) ? 'Sí' : 'No'],
  ['Fecha de contabilización', date(trip.value.fecha_contabilizacion)],
  ['Observaciones', [trip.value.observaciones_1, trip.value.observaciones_2, trip.value.observaciones_3].filter(Boolean).join(' · ') || null],
])
</script>

<template>
  <div>
    <PageHeading eyebrow="Viajes · Historial importado" :title="`Viaje ${display(trip.numero_viaje)}`" description="Consulta de solo lectura. Se muestran los datos guardados del viaje y sus registros relacionados." :back="{ href: data.backUrl ?? '/viajes', label: 'Volver al dashboard' }" />

    <PanelCard title="Datos generales" class="mb-5">
      <dl class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <div v-for="([label, value]) in facts" :key="label" class="min-w-0 rounded-lg border border-border-subtle bg-surface p-3">
          <dt class="text-xs font-semibold text-ink-muted">{{ label }}</dt>
          <dd class="mt-1 break-words text-sm font-semibold text-ink">{{ display(value) }}</dd>
        </div>
      </dl>
    </PanelCard>

    <div class="space-y-5">
      <PanelCard v-for="section in sections" :key="section.key" :title="section.title" :count="(data[section.key] ?? []).length">
        <p v-if="!(data[section.key] ?? []).length" class="text-sm text-ink-muted">Este viaje no tiene registros en esta sección.</p>
        <div v-else class="overflow-x-auto">
          <table class="w-full min-w-[42rem] text-left text-sm">
            <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted"><tr><th v-for="([key, label]) in section.columns" :key="key" class="whitespace-nowrap px-3 py-2">{{ label }}</th></tr></thead>
            <tbody class="divide-y divide-border-subtle">
              <tr v-for="(row, index) in data[section.key]" :key="`${row.item ?? row.id ?? index}-${row.sub_item ?? ''}`">
                <td v-for="([key]) in section.columns" :key="key" class="max-w-64 px-3 py-2 text-ink-muted">{{ formatted(key, row[key]) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </PanelCard>
    </div>
  </div>
</template>
