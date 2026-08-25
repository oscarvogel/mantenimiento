<script setup>
import { computed, ref } from 'vue'
import { MagnifyingGlassIcon, PlusIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import CsrfInput from './CsrfInput.vue'
import FormField from './FormField.vue'
import { fieldClass, primaryButton, secondaryButton, today } from '../helpers.js'

const props = defineProps({ data: { type: Object, required: true } })
const emit = defineEmits(['close'])

const equipmentId = ref('')
const search = ref('')
const equipments = computed(() => props.data.correctiveEquipments ?? [])
const normalize = (value) => String(value ?? '').toLocaleLowerCase('es').replace(/[\s-]+/g, '')
const filtered = computed(() => {
  const term = normalize(search.value)
  if (!term) return equipments.value
  return equipments.value.filter((equipment) => [equipment.code, equipment.plate, equipment.typeName, equipment.branchName]
    .some((value) => normalize(value).includes(term)))
})
const selected = computed(() => equipments.value.find((equipment) => String(equipment.id) === String(equipmentId.value)) ?? null)
const choose = (equipment) => {
  equipmentId.value = String(equipment.id)
  search.value = [equipment.code, equipment.plate].filter(Boolean).join(' · ')
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="presentation" @click.self="emit('close')">
    <section class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-surface-raised shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="corrective-work-title">
      <div class="flex items-start justify-between gap-4 border-b border-border-subtle px-5 py-4 sm:px-6">
        <div>
          <p class="text-xs font-bold uppercase tracking-wide text-primary">Trabajo correctivo</p>
          <h2 id="corrective-work-title" class="mt-1 text-xl font-bold text-ink">Registrar trabajo realizado</h2>
          <p class="mt-1 text-sm text-ink-muted">Registrá una correctiva que todavía no figura en el listado. Quedará finalizada en un solo paso.</p>
        </div>
        <button type="button" class="rounded-lg p-2 text-ink-muted hover:bg-surface-muted hover:text-ink" aria-label="Cerrar modal" @click="emit('close')"><XMarkIcon class="size-5" /></button>
      </div>

      <form method="post" enctype="multipart/form-data" :action="data.routes.registerCorrective" class="grid gap-4 p-5 sm:grid-cols-2 sm:p-6">
        <CsrfInput :csrf="data.csrf" />
        <input type="hidden" name="equipo_id" :value="equipmentId" />
        <input type="hidden" name="volver_ordenes" value="1" />

        <FormField label="Equipo *" for-id="quick-corrective-equipment" class="sm:col-span-2">
          <div class="relative"><MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-3 size-5 text-ink-subtle" /><input id="quick-corrective-equipment" v-model="search" type="search" autocomplete="off" required placeholder="Buscar por código o patente" :class="`${fieldClass} pl-10`" @input="equipmentId = ''" /></div>
          <div v-if="!selected" class="mt-2 max-h-44 overflow-y-auto rounded-xl border border-border bg-surface">
            <button v-for="equipment in filtered" :key="equipment.id" type="button" class="flex w-full items-center justify-between gap-3 border-b border-border-subtle px-3 py-2 text-left last:border-0 hover:bg-surface-muted" @click="choose(equipment)">
              <span><strong class="text-sm text-ink">{{ equipment.code }}</strong><span v-if="equipment.plate" class="ml-2 text-xs text-ink-muted">{{ equipment.plate }}</span></span>
              <span class="text-xs text-ink-subtle">{{ equipment.typeName }}</span>
            </button>
            <p v-if="filtered.length === 0" class="px-3 py-3 text-sm text-ink-muted">No se encontraron equipos.</p>
          </div>
          <p v-else class="mt-2 rounded-lg bg-success-subtle px-3 py-2 text-sm font-semibold text-success-strong">Seleccionado: {{ selected.code }}<span v-if="selected.plate"> · {{ selected.plate }}</span></p>
        </FormField>

        <FormField label="Fecha del trabajo *" for-id="quick-corrective-date"><input id="quick-corrective-date" type="date" name="fecha_servicio" :value="today()" required :class="fieldClass" /></FormField>
        <FormField label="Prioridad" for-id="quick-corrective-priority"><select id="quick-corrective-priority" name="prioridad" :class="fieldClass"><option value="MEDIA" selected>Normal</option><option value="ALTA">Alta</option><option value="CRITICA">Urgente</option></select></FormField>
        <FormField label="Problema / motivo *" for-id="quick-corrective-problem" class="sm:col-span-2"><textarea id="quick-corrective-problem" name="problema_reportado" rows="2" minlength="5" maxlength="3000" required :class="fieldClass"></textarea></FormField>
        <FormField label="Trabajo realizado *" for-id="quick-corrective-work" class="sm:col-span-2"><textarea id="quick-corrective-work" name="trabajo_realizado_correctivo" rows="3" minlength="5" maxlength="5000" required :class="fieldClass"></textarea></FormField>
        <FormField label="Responsable" for-id="quick-corrective-owner"><select id="quick-corrective-owner" name="responsable_usuario_id" :class="fieldClass"><option value="">Sin asignar</option><option v-for="owner in data.owners" :key="owner.id" :value="owner.id">{{ owner.name }}</option></select></FormField>
        <FormField label="Observaciones" for-id="quick-corrective-observations"><textarea id="quick-corrective-observations" name="observaciones" rows="2" maxlength="3000" :class="fieldClass"></textarea></FormField>

        <template v-if="selected">
          <FormField v-if="selected.controlsKm" label="Kilometraje al realizar el trabajo" for-id="quick-corrective-km"><input id="quick-corrective-km" type="number" min="0" name="km_salida" :value="selected.currentKm ?? ''" :class="fieldClass" /></FormField>
          <FormField v-if="selected.controlsHours" label="Horómetro al realizar el trabajo" for-id="quick-corrective-hours"><input id="quick-corrective-hours" type="number" min="0" step="0.1" name="horas_salida" :value="selected.currentHours ?? ''" :class="fieldClass" /></FormField>
        </template>

        <div class="grid gap-3 sm:col-span-2 sm:grid-cols-3">
          <FormField label="Mano de obra" for-id="quick-corrective-labor"><input id="quick-corrective-labor" type="number" min="0" step="0.01" name="costo_mano_obra" value="0" :class="fieldClass" /></FormField>
          <FormField label="Repuestos" for-id="quick-corrective-parts"><input id="quick-corrective-parts" type="number" min="0" step="0.01" name="costo_repuestos" value="0" :class="fieldClass" /></FormField>
          <FormField label="Otros costos" for-id="quick-corrective-other"><input id="quick-corrective-other" type="number" min="0" step="0.01" name="otros_costos" value="0" :class="fieldClass" /></FormField>
        </div>

        <FormField label="Evidencia opcional" for-id="quick-corrective-evidence" hint="PDF, JPG, PNG o WEBP: remito, foto o comprobante." class="sm:col-span-2"><input id="quick-corrective-evidence" type="file" name="evidencia" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp" :class="fieldClass" /></FormField>

        <div class="flex flex-col-reverse gap-2 border-t border-border-subtle pt-4 sm:col-span-2 sm:flex-row sm:justify-end">
          <button type="button" :class="secondaryButton" @click="emit('close')">Cancelar</button>
          <button type="submit" :disabled="!equipmentId" :class="primaryButton"><PlusIcon class="mr-2 size-4" />Registrar trabajo realizado</button>
        </div>
      </form>
    </section>
  </div>
</template>
