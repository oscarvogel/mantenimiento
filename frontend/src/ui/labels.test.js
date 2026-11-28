import { describe, expect, it } from 'vitest'
import { fileTypeLabel, humanizeCode } from './labels.js'

describe('humanizeCode', () => {
  it('convierte códigos en palabras', () => {
    expect(humanizeCode('EN_PROCESO')).toBe('En proceso')
    expect(humanizeCode('CORRECTIVO')).toBe('Correctivo')
    expect(humanizeCode('ESPERA_REPUESTOS')).toBe('En espera de repuestos')
  })

  it('recupera acentos y traduce lo que vino en inglés', () => {
    expect(humanizeCode('CRITICA')).toBe('Crítica')
    expect(humanizeCode('AL_DIA')).toBe('Al día')
    expect(humanizeCode('WARNING')).toBe('Advertencia')
  })

  it('no toca lo que ya es texto ni las siglas', () => {
    expect(humanizeCode('Operativo')).toBe('Operativo')
    expect(humanizeCode('Próximamente')).toBe('Próximamente')
    expect(humanizeCode('BCRA')).toBe('BCRA')
    expect(humanizeCode(null)).toBe('')
  })
})

describe('fileTypeLabel', () => {
  it('nombra el tipo de archivo como lo diría una persona', () => {
    expect(fileTypeLabel('application/pdf')).toBe('PDF')
    expect(fileTypeLabel('image/jpeg')).toBe('Imagen')
    expect(fileTypeLabel('application/zip')).toBe('Archivo')
  })
})
