// Agrupa los 11 estados del neumático para darles color. El nombre visible
// lo manda el servidor (NeumaticStatus::label): acá solo se decide el tono.
const IN_SERVICE = ['NUEVA', 'MEDIO_USO', 'REPARADO', 'RECAPADA', 'VULCANIZADA']
const ATTENTION = ['EN_REPARACION', 'DESCARTE', 'PARA_VENTA']

export const tireStatusTone = (status) => {
  if (IN_SERVICE.includes(status)) return 'bg-success-soft text-success-strong'
  if (ATTENTION.includes(status)) return 'bg-warning-subtle text-warning-strong'
  return 'bg-surface-subtle text-ink-muted'
}

export const formatDateTimeDmy = (value) => {
  if (!value) return ''
  const [date, time = ''] = String(value).replace('T', ' ').split(' ')
  const [year, month, day] = date.split('-')
  if (!year || !month || !day) return String(value)
  const hhmm = time.slice(0, 5)
  return hhmm && hhmm !== '00:00' ? `${day}/${month}/${year} ${hhmm}` : `${day}/${month}/${year}`
}

export const nowLocalMinutes = (date = new Date()) => {
  const pad = (number) => String(number).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}
