<script setup>
import * as format from '../../../ui/format.js'
/**
 * Acuerdo con el chofer para la base de liquidación de un tramo (#533).
 *
 * El operador elige de forma explícita una de dos modalidades:
 * - Por toneladas: toneladas x precio por tonelada. El importe se calcula.
 * - Monto acordado: un importe global. No pide toneladas ni precio.
 *
 * Es independiente de cómo se factura el tramo al cliente.
 */
import { computed } from 'vue'
import { fieldClass } from '../helpers.js'
import { liquidationBasisAmount } from '../../../composables/useTripLegMoney.js'

const props = defineProps({
  modelValue: { type: Object, required: true },
  currency: { type: String, default: 'ARS' },
  idPrefix: { type: String, required: true },
  dense: { type: Boolean, default: false },
  // Prefijo de los nombres del formulario, p. ej. `tramos[0]` en el alta.
  namePrefix: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue'])

const MODES = [
  { value: 'MONTO_ACORDADO', label: 'Monto acordado', hint: 'Un importe global pactado con el chofer.' },
  { value: 'POR_TONELADA', label: 'Por toneladas', hint: 'Toneladas × precio por tonelada.' },
]

const labelClass = computed(() => (props.dense ? 'mb-1 block text-xs font-semibold text-ink-muted' : 'mb-1.5 block text-sm font-semibold text-ink'))
const mode = computed(() => props.modelValue.modo || '')
const fieldName = (field) => (props.namePrefix ? `${props.namePrefix}[${field}]` : field)

const update = (field, value) => emit('update:modelValue', { ...props.modelValue, [field]: value })

// Solo para mostrar: el importe que vale lo calcula el servidor al guardar.
const calculated = computed(() => liquidationBasisAmount(props.modelValue))
const formatted = (value) => format.formatMoney(value, { currency: props.currency, cents: true })
</script>

<template>
  <fieldset class="rounded-lg border border-border-subtle p-3" data-testid="liquidation-basis">
    <legend class="px-1 text-xs font-bold text-ink">Acuerdo con el chofer</legend>
    <p class="text-xs text-ink-muted">Base sobre la que se aplica el porcentaje del chofer. No depende de cómo se facture el tramo.</p>
    <div class="mt-2 flex flex-wrap gap-3" role="radiogroup" :aria-label="'Modalidad de liquidación'">
      <label v-for="option in MODES" :key="option.value" :for="`${idPrefix}-${option.value}`" class="flex cursor-pointer items-start gap-2 rounded-lg border px-3 py-2 text-sm" :class="mode === option.value ? 'border-primary bg-primary/5' : 'border-border-subtle'">
        <input :id="`${idPrefix}-${option.value}`" type="radio" :name="fieldName('modo_liquidacion')" :value="option.value" :checked="mode === option.value" required class="mt-1" @change="update('modo', option.value)" />
        <span><span class="block font-semibold text-ink">{{ option.label }}</span><span class="block text-xs text-ink-muted">{{ option.hint }}</span></span>
      </label>
    </div>

    <div v-if="mode === 'POR_TONELADA'" class="mt-3 grid gap-3 md:grid-cols-3">
      <label class="block"><span :class="labelClass">Toneladas acordadas</span><input :value="modelValue.toneladas" type="number" :name="fieldName('toneladas_liquidacion')" min="0" step="0.001" :class="fieldClass" @input="update('toneladas', $event.target.value)" /></label>
      <label class="block"><span :class="labelClass">Precio por tonelada ({{ currency }})</span><input :value="modelValue.precio" type="number" :name="fieldName('precio_unitario_liquidacion')" min="0" step="0.01" :class="fieldClass" @input="update('precio', $event.target.value)" /></label>
      <div class="block"><span :class="labelClass">Base de liquidación</span><p class="rounded-lg border border-border-subtle bg-surface-muted px-3 py-2 text-sm font-semibold text-ink" data-testid="liquidation-basis-total">{{ calculated === null ? 'Cargá toneladas y precio' : formatted(calculated) }}</p></div>
    </div>
    <div v-else-if="mode === 'MONTO_ACORDADO'" class="mt-3 grid gap-3 md:grid-cols-2">
      <label class="block"><span :class="labelClass">Monto acordado ({{ currency }}, opcional)</span><input :value="modelValue.monto" type="number" :name="fieldName('flete_liquidacion')" min="0" step="0.01" :class="fieldClass" @input="update('monto', $event.target.value)" /></label>
    </div>
    <p v-else class="mt-2 text-xs font-semibold text-warning-strong">Elegí cómo se liquida este tramo al chofer.</p>
  </fieldset>
</template>
