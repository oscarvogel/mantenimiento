// Turns backend codes (EN_PROCESO, CRITICA) into words a person reads ("En proceso",
// "Crítica"). Values that are already text ("Operativo", "Camión") pass unchanged, so it
// is safe on fields that mix both. docs/DESIGN_TOKENS.md, "Lenguaje".

// Codes whose plain sentence-casing would lose an accent, read badly or stay in English.
const words = {
  ACTIVO: 'Activo',
  AL_DIA: 'Al día',
  ANTIGUO: 'Lectura antigua',
  BAJA: 'Dado de baja',
  CAMION: 'Camión',
  CRITICA: 'Crítica',
  CRITICAL: 'Crítica',
  CRITICO: 'Crítico',
  ESPERA_REPUESTOS: 'En espera de repuestos',
  EN_ESPERA_REPUESTOS: 'En espera de repuestos',
  GOMERIA: 'Gomería',
  INFO: 'Información',
  MEDIO_USO: 'Medio uso',
  NO_APLICA: 'No aplica',
  PROXIMO: 'Próximo',
  QR_ANONIMO: 'QR anónimo',
  REVISION: 'Revisión',
  SIN_LECTURA: 'Sin lectura',
  VALIDA: 'Válida',
  VALIDO: 'Válido',
  WARNING: 'Advertencia',
}

// Acronyms are codes too, but they read fine as they are.
const keep = new Set(['BCRA', 'OT', 'QR', 'PDF', 'CSV', 'ARS', 'USD', 'BRL', 'CAE', 'ARCA', 'CUIT', 'GPS'])

const isCode = (text) => /^[A-Z0-9]+(_[A-Z0-9]+)*$/.test(text) && /[A-Z]/.test(text)

export const humanizeCode = (value) => {
  const text = String(value ?? '').trim()
  if (!isCode(text) || keep.has(text)) return text
  if (words[text]) return words[text]
  const plain = text.replaceAll('_', ' ').toLowerCase()
  return plain.charAt(0).toUpperCase() + plain.slice(1)
}

// "application/pdf" → "PDF", "image/jpeg" → "Imagen".
export const fileTypeLabel = (mimeType) => {
  const type = String(mimeType ?? '').toLowerCase()
  if (type === 'application/pdf') return 'PDF'
  if (type.startsWith('image/')) return 'Imagen'
  if (type.includes('spreadsheet') || type.includes('excel') || type === 'text/csv') return 'Planilla'
  if (type.includes('word')) return 'Documento'
  return type ? 'Archivo' : ''
}
