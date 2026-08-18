import { describe, expect, it } from 'vitest'
import { flattenLibraryTasks, taskStatusLabel } from './libraryTaskStatus.js'

describe('library task status', () => {
  it('deriva contexto y url de estado desde la tarea de biblioteca', () => {
    const [task] = flattenLibraryTasks([{
      serviceTypeId: 12,
      tasks: [{
        id: 7,
        code: 'FILTRO-ACEITE',
        name: 'Filtro de aceite',
        active: true,
        updateUrl: '/mantenimiento/importaciones/biblioteca/tareas/7',
      }],
    }])

    expect(task.serviceTypeId).toBe(12)
    expect(task.statusUrl).toBe('/mantenimiento/importaciones/biblioteca/tareas/7/estado')
  })

  it('presenta estado en lenguaje directo', () => {
    expect(taskStatusLabel(true)).toBe('Activa')
    expect(taskStatusLabel(false)).toBe('Inactiva')
  })
})
