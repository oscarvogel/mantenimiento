<script setup>
const props = defineProps({ status: { type: String, required: true } })

const tone = {
  ACTIVO: 'bg-success-subtle text-success-strong',
  ACTIVA: 'bg-success-subtle text-success-strong',
  VALIDA: 'bg-success-subtle text-success-strong',
  IMPORTADA: 'bg-success-subtle text-success-strong',
  AL_DIA: 'bg-success-subtle text-success-strong',
  PROXIMO: 'bg-warning-subtle text-warning-foreground',
  DUPLICADA: 'bg-warning-subtle text-warning-foreground',
  VENCIDO: 'bg-danger-subtle text-danger-strong',
  ERROR: 'bg-danger-subtle text-danger-strong',
  EMITIDA: 'bg-info-subtle text-info-strong',
  EN_PROCESO: 'bg-info-subtle text-info-strong',
  BORRADOR_VALIDADO: 'bg-info-subtle text-info-strong',
  SIN_DATOS: 'bg-surface-muted text-ink-muted',
  BAJA: 'bg-surface-muted text-ink-muted',
  ANULADA: 'bg-surface-muted text-ink-muted',
  CANCELADA: 'bg-surface-muted text-ink-muted',
  RETIRADO: 'bg-surface-muted text-ink-muted',
}
</script>

<template>
  <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="tone[props.status] ?? 'bg-info-subtle text-info-strong'">
    {{ status.replaceAll('_', ' ') }}
  </span>
</template>
