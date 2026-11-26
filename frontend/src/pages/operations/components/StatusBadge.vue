<script setup>
import { computed } from 'vue'
import { humanizeCode } from '../../../ui/labels.js'
import { CheckCircleIcon, ClockIcon, ExclamationTriangleIcon, InformationCircleIcon, MinusCircleIcon } from '@heroicons/vue/16/solid'

const props = defineProps({ status: { type: String, required: true } })

// Each tone has its own icon so the state still reads without color (WCAG 1.4.1).
const tones = {
  success: { classes: 'bg-success-subtle text-success-strong', icon: CheckCircleIcon },
  warning: { classes: 'bg-warning-subtle text-warning-foreground', icon: ClockIcon },
  danger: { classes: 'bg-danger-subtle text-danger-strong', icon: ExclamationTriangleIcon },
  info: { classes: 'bg-info-subtle text-info-strong', icon: InformationCircleIcon },
  muted: { classes: 'bg-surface-muted text-ink-muted', icon: MinusCircleIcon },
}

const toneByStatus = {
  ACTIVO: 'success', ACTIVA: 'success', VALIDA: 'success', IMPORTADA: 'success', AL_DIA: 'success', APROBADA: 'success', HOY: 'success',
  PROXIMO: 'warning', DUPLICADA: 'warning', PENDIENTE: 'warning', POSTERGADA: 'warning', REVISAR: 'warning',
  VENCIDO: 'danger', ERROR: 'danger', RECHAZADA: 'danger', ANTIGUO: 'danger', SIN_LECTURA: 'danger',
  EMITIDA: 'info', EN_PROCESO: 'info', BORRADOR_VALIDADO: 'info', AGRUPADA: 'info',
  SIN_DATOS: 'muted', BAJA: 'muted', ANULADA: 'muted', CANCELADA: 'muted', RETIRADO: 'muted', FUERA_DE_FILTRO: 'muted',
}

const tone = computed(() => tones[toneByStatus[props.status]] ?? tones.info)
const label = computed(() => humanizeCode(props.status))
</script>

<template>
  <span class="inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold" :class="tone.classes">
    <component :is="tone.icon" class="size-3.5 shrink-0" aria-hidden="true" />
    {{ label }}
  </span>
</template>
