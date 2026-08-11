import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import PaginationBar from '../../src/pages/operations/components/PaginationBar.vue'
import { pageSizeUrl } from '../../src/pages/operations/components/pagination.js'

describe('PaginationBar', () => {
  it('muestra el selector 5/10/25 aun cuando existe una sola página', () => {
    const wrapper = mount(PaginationBar, {
      props: { pagination: { page: 1, totalPages: 1, total: 4, perPage: 10 } },
    })

    expect(wrapper.get('select[aria-label="Registros por página"]').element.value).toBe('10')
    expect(wrapper.findAll('select option').map((option) => option.text())).toEqual(['5', '10', '25'])
  })

  it('conserva filtros y reinicia la página al cambiar el tamaño', () => {
    const url = pageSizeUrl(
      { perPageOptions: [5, 10, 25], perPageKey: 'filas', pageKey: 'pagina' },
      25,
      'https://example.test/reportes?sucursal_id=7&pagina=3&filas=10',
    )
    const parsed = new URL(url)

    expect(parsed.searchParams.get('sucursal_id')).toBe('7')
    expect(parsed.searchParams.get('pagina')).toBe('1')
    expect(parsed.searchParams.get('filas')).toBe('25')
  })

  it('normaliza un tamaño manipulado al valor por defecto', () => {
    const parsed = new URL(pageSizeUrl({}, 999, 'https://example.test/importaciones?page=4'))
    expect(parsed.searchParams.get('per_page')).toBe('10')
    expect(parsed.searchParams.get('page')).toBe('1')
  })
})
