<script setup>
import { computed } from 'vue'
import XMarkIcon from '@heroicons/vue/24/outline/XMarkIcon'
import CsrfInput from './CsrfInput.vue'
import FormField from './FormField.vue'
import UsageReadingInput from './UsageReadingInput.vue'
import { fieldClass, primaryButton, secondaryButton, today } from '../helpers.js'

const props = defineProps({
  order: { type: Object, required: true },
  csrf: { type: Object, required: true },
  formState: { type: Object, required: true },
})
const emit = defineEmits(['close', 'update:formState'])

const taskResults = [
  { value: 'REALIZADA', label: 'Realizada' },
  { value: 'PENDIENTE', label: 'Pendiente / no realizada' },
  { value: 'NO_APLICA', label: 'No aplica' },
]
const modalTitle = computed(() => `Cerrar ${props.order.number}`)
const updateFormState = (value) => emit('update:formState', value)
const numericCost = (value) => {
  const normalized = String(value ?? '').trim().replace(',', '.')
  if (normalized === '') return 0
  const parsed = Number(normalized)
  return Number.isFinite(parsed) && parsed >= 0 ? parsed : 0
}
const costTotal = computed(() => (
  numericCost(props.formState.costo_mano_obra)
  + numericCost(props.formState.costo_repuestos)
  + numericCost(props.formState.otros_costos)
))
const formattedCostTotal = computed(() => costTotal.value.toLocaleString('es-AR', {
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
}))
</script>

<template>
  <Teleport to="body">
    <div
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/55 p-3 backdrop-blur-[1px] sm:p-5"
      data-testid="work-order-closure-modal"
      @click.self="emit('close')"
      @keydown.esc="emit('close')"
    >
      <section
        class="flex max-h-[calc(100vh-1.5rem)] w-full max-w-3xl flex-col overflow-hidden rounded-2xl border border-border bg-surface-raised shadow-2xl sm:max-h-[calc(100vh-2.5rem)]"
        role="dialog"
        aria-modal="true"
        aria-labelledby="work-order-closure-title"
      >
        <header class="flex items-start justify-between gap-4 border-b border-border-subtle px-5 py-4 sm:px-6">
          <div>
            <p class="text-xs font-bold uppercase tracking-[0.14em] text-primary">Cierre de orden</p>
            <h2 id="work-order-closure-title" class="mt-1 text-xl font-bold text-ink">{{ modalTitle }}</h2>
            <p class="mt-1 text-sm text-ink-muted">Marcá el resultado de cada tarea y dejá el detalle o motivo correspondiente.</p>
          </div>
          <button type="button" :class="secondaryButton" aria-label="Cerrar ventana de cierre" @click="emit('close')">
            <XMarkIcon class="size-5" aria-hidden="true" />
          </button>
        </header>

        <div class="overflow-y-auto px-5 py-5 sm:px-6">
          <form method="post" :action="order.closeUrl" class="grid gap-5">
            <CsrfInput :csrf="csrf" />

            <fieldset class="grid gap-3">
              <legend class="text-base font-bold text-ink">Tareas de la orden</legend>
              <p class="text-sm text-ink-muted">Completá las {{ order.tasks.length }} tarea{{ order.tasks.length === 1 ? '' : 's' }} antes de confirmar el cierre.</p>

              <article v-for="(task, index) in order.tasks" :key="task.id" class="rounded-xl border border-border bg-surface-subtle p-4">
                <div class="flex items-start gap-3">
                  <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-primary-subtle text-sm font-bold text-primary">{{ index + 1 }}</span>
                  <div class="min-w-0 flex-1">
                    <h3 class="font-bold text-ink">{{ task.description }}</h3>
                    <p class="mt-1 text-xs text-ink-muted">Estado actual: {{ task.status || 'PENDIENTE' }}</p>
                  </div>
                </div>
                <div class="mt-4 grid gap-3 sm:grid-cols-[minmax(12rem,0.8fr)_minmax(0,1.2fr)]">
                  <FormField :label="`Resultado de la tarea ${index + 1}`" :for-id="`order-${order.id}-task-${task.id}-result`">
                    <select
                      :id="`order-${order.id}-task-${task.id}-result`"
                      v-model="formState.tasks[task.id].resultado"
                      :name="`trabajo_realizado[${task.id}][resultado]`"
                      required
                      :class="fieldClass"
                    >
                      <option value="">Seleccionar...</option>
                      <option v-for="result in taskResults" :key="result.value" :value="result.value">{{ result.label }}</option>
                    </select>
                  </FormField>
                  <FormField label="Detalle / motivo" :for-id="`order-${order.id}-task-${task.id}-detail`" hint="Mínimo 5 caracteres.">
                    <textarea
                      :id="`order-${order.id}-task-${task.id}-detail`"
                      v-model="formState.tasks[task.id].detalle"
                      :name="`trabajo_realizado[${task.id}][detalle]`"
                      rows="2"
                      :minlength="['PENDIENTE', 'NO_APLICA'].includes(formState.tasks[task.id].resultado) ? 5 : undefined"
                      maxlength="1000"
                      :required="['PENDIENTE', 'NO_APLICA'].includes(formState.tasks[task.id].resultado)"
                      placeholder="Ej.: filtro reemplazado / sin repuesto disponible"
                      :class="fieldClass"
                    ></textarea>
                  </FormField>
                </div>
              </article>
            </fieldset>

            <div class="grid gap-4 border-t border-border-subtle pt-4 sm:grid-cols-2">
              <FormField label="Fecha servicio" :for-id="`order-${order.id}-date`">
                <input :id="`order-${order.id}-date`" type="date" name="fecha_servicio" required :value="today()" :class="fieldClass" />
              </FormField>
              <div class="sm:col-span-2">
                <UsageReadingInput
                  :model-value="formState"
                  :equipment="order"
                  :names="{ kilometers: 'km_salida', hours: 'horas_salida' }"
                  :labels="{ kilometers: 'Nueva lectura de kilometraje', hours: 'Nueva lectura de horómetro', current: 'Actual' }"
                  :id-prefix="`order-${order.id}-reading`"
                  @update:model-value="updateFormState"
                />
              </div>
            </div>

            <fieldset class="grid gap-4 rounded-xl border border-border bg-surface-subtle p-4">
              <div>
                <legend class="text-base font-bold text-ink">Costos del servicio</legend>
                <p class="mt-1 text-sm text-ink-muted">Opcionales. Si no se informan, la orden se cierra con costo $ 0,00.</p>
              </div>
              <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <FormField label="Mano de obra" :for-id="`order-${order.id}-labor-cost`">
                  <input
                    :id="`order-${order.id}-labor-cost`"
                    v-model="formState.costo_mano_obra"
                    type="number"
                    name="costo_mano_obra"
                    min="0"
                    step="0.01"
                    inputmode="decimal"
                    placeholder="0,00"
                    :class="fieldClass"
                  />
                </FormField>
                <FormField label="Repuestos / insumos" :for-id="`order-${order.id}-parts-cost`">
                  <input
                    :id="`order-${order.id}-parts-cost`"
                    v-model="formState.costo_repuestos"
                    type="number"
                    name="costo_repuestos"
                    min="0"
                    step="0.01"
                    inputmode="decimal"
                    placeholder="0,00"
                    :class="fieldClass"
                  />
                </FormField>
                <FormField label="Otros costos" :for-id="`order-${order.id}-other-costs`">
                  <input
                    :id="`order-${order.id}-other-costs`"
                    v-model="formState.otros_costos"
                    type="number"
                    name="otros_costos"
                    min="0"
                    step="0.01"
                    inputmode="decimal"
                    placeholder="0,00"
                    :class="fieldClass"
                  />
                </FormField>
                <div class="rounded-lg border border-primary/20 bg-primary-subtle px-4 py-3" data-testid="work-order-cost-total">
                  <p class="text-xs font-bold uppercase tracking-wide text-primary">Total</p>
                  <p class="mt-1 text-xl font-bold text-ink">$ {{ formattedCostTotal }}</p>
                </div>
              </div>
              <p class="text-xs text-ink-muted">El total definitivo se recalcula en el servidor a partir de los tres importes.</p>
            </fieldset>

            <p class="rounded-lg bg-info-subtle px-3 py-2 text-xs text-info-strong">Al confirmar, se guardará el resultado de cada tarea, se actualizarán las lecturas, los costos informados y se recalculará el próximo mantenimiento.</p>

            <div class="flex flex-wrap justify-end gap-2 border-t border-border-subtle pt-4">
              <button type="button" :class="secondaryButton" @click="emit('close')">Cancelar</button>
              <button type="submit" :class="primaryButton">Confirmar cierre de orden</button>
            </div>
          </form>
        </div>
      </section>
    </div>
  </Teleport>
</template>
